<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\Szerep;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A felhasználó saját fiókjának törlése.
 *
 * A vezérlő szabály egyetlen mondat: **a fiók a felhasználóé, a cég pedig
 * akkor szűnik meg, ha az utolsó ember is elment.** Ebből következik a
 * három eset, és a köztük lévő különbség nem szőrszálhasogatás:
 *
 * - Egyedül van a cégben → mindent viszünk, az előfizetést lemondjuk.
 * - Van más is, és nem ő az utolsó tulajdonos → csak ő megy.
 * - Ő az utolsó tulajdonos, de más még dolgozik a cégben → **megállunk**.
 *
 * A harmadik eset nélkül a két rossz végkifejlet egyike biztosan bekövetkezne.
 * Ha a tulajdonos törlése mindig elvinné a céget, akkor két tulajdonos közül
 * bármelyik letörölhetné a másik alól a teljes archívumot. Ha viszont sosem
 * vinné el, akkor maradna egy cég, amiben senki nem tud tagot kezelni és
 * senki nem tudja lemondani az előfizetést — vagyis egy fizető, de
 * használhatatlan fiók, amihez már csak e-mailben lehetne hozzáférni.
 */
final class FiokTorlo
{
    public function __construct(
        private readonly ElofizetesKapu $szamlazo,
        private readonly CegTorlo $cegTorlo,
    ) {}

    /** Mi történne most, ha ez a felhasználó törölné a fiókját. */
    public function terv(User $user): TorlesTerv
    {
        $ceg = $user->ceg();

        if ($ceg === null) {
            return new TorlesTerv(
                ceg: null,
                cegIsTorlodik: false,
                maradoTagok: 0,
                akadaly: null,
                vanElofizetes: false,
                iratok: 0,
            );
        }

        $marad = max(0, $ceg->users()->count() - 1);

        // A saját szerepe a pivotból; a tulajdonosok számához pedig az
        // egész taglista kell, mert nem az számít, hogy ő tulajdonos-e,
        // hanem hogy marad-e utána másik.
        $tulajdonosok = $ceg->users()
            ->wherePivot('role', Szerep::Tulajdonos->value)
            ->count();

        $akadaly = ($marad > 0
            && $user->szerepe($ceg) === Szerep::Tulajdonos
            && $tulajdonosok <= 1)
                ? TorlesAkadaly::utolsoTulajdonos($marad)
                : null;

        return new TorlesTerv(
            ceg: $ceg,
            cegIsTorlodik: $marad === 0,
            maradoTagok: $marad,
            akadaly: $akadaly,
            vanElofizetes: $ceg->stripe_subscription_id !== null,
            // A bérlőszűrő megkerülésével: a törlés konzolról is futhat,
            // ahol nincs bejelentkezett felhasználó, akire szűkíteni lehetne.
            iratok: Document::query()->withoutGlobalScopes()->where('company_id', $ceg->id)->count(),
        );
    }

    /**
     * @return TorlesTerv amit ténylegesen végrehajtottunk
     *
     * @throws TorlesAkadaly ha a törlés nem mehet végbe — ilyenkor semmi nem változik
     */
    public function torol(User $user): TorlesTerv
    {
        $terv = $this->terv($user);

        if ($terv->akadaly !== null) {
            throw $terv->akadaly;
        }

        /*
         * A számlázó megy elsőnek, és ez a sorrend a lényeg.
         *
         * Ha a lemondás elhasal, még minden megvan, és a felhasználó
         * újrapróbálhatja — bosszantó, de helyrehozható. Fordítva viszont a
         * törölt cég mellett futva maradna egy fizetős előfizetés, amiről
         * már nincs is felületünk, ahol lemondható lenne: a felhasználó
         * hónapokig fizetne egy nem létező fiókért. A kettő közül csak az
         * egyik hiba javítható.
         */
        if ($terv->cegIsTorlodik && $terv->vanElofizetes && $terv->ceg !== null) {
            $this->elofizetestLemond($terv->ceg);
        }

        DB::transaction(function () use ($user, $terv): void {
            // Csak akkor naplózunk, ha lesz kinek: ha a cég is megy, a
            // naplósor vele együtt törlődik, tehát üres mozdulat volna.
            // A `user_id` idegen kulcsa `nullOnDelete`, ezért az e-mail
            // az összefoglalóba kerül — különben a bejegyzés névtelen lenne.
            if (! $terv->cegIsTorlodik && $terv->ceg !== null) {
                // A `nevében` nem díszlet: a fióktörlés képernyője **nincs**
                // a `ceg` middleware alatt (különben a cég nélküli felhasználó
                // sosem jutna el ide), tehát a bérlő ilyenkor üres, és a
                // naplósor `company_id` nélkül, kivétellel hasalna el —
                // magával rántva az egész törlést.
                app(Berlo::class)->nevében(
                    $terv->ceg,
                    fn () => ActivityLog::rogzit('fiok.torolve', null, $user->email),
                );
            }

            $this->nyomokTorlese($user);

            // A tagságok a `company_user` idegen kulcsán keresztül mennek el.
            $user->delete();

            if ($terv->cegIsTorlodik && $terv->ceg !== null) {
                $this->cegTorlo->torol($terv->ceg);
            }
        });

        return $terv;
    }

    /**
     * Ami a `users` során kívül a felhasználóhoz köt.
     *
     * A `sessions` és a `password_reset_tokens` táblán nincs idegen kulcs,
     * tehát maguktól maradnának. A munkamenet-sor önmagában ártalmatlan (a
     * hozzá tartozó felhasználót már nem lehet betölteni), a jelszó-token
     * viszont **nem az**: e-mail címre szól, nem azonosítóra. Ha bent marad,
     * és később valaki ugyanezzel az e-mail címmel regisztrál, egy régi,
     * még érvényes tokennel át lehetne venni az új fiókot.
     */
    private function nyomokTorlese(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }

    private function elofizetestLemond(Company $ceg): void
    {
        if (! $this->szamlazo->beallitva()) {
            // Nincs beállítva a Stripe ezen a példányon: nincs mit lemondani,
            // és nincs is mivel. Az azonosító ilyenkor fejlesztői maradvány.
            return;
        }

        try {
            $this->szamlazo->elofizetestLemond((string) $ceg->stripe_subscription_id);
        } catch (Throwable) {
            throw TorlesAkadaly::szamlazoNemErheto();
        }
    }
}
