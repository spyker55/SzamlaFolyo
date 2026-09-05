<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Auth\ElfelejtettJelszo;
use App\Livewire\Auth\JelszoBeallitas;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A tagfelvétel erre az útra támaszkodik: akinek a tulajdonos vesz fel fiókot,
 * jelszót soha nem kap — itt tud belépni először. Ha ez elromlik, a
 * meghívott felhasználó bezárva marad.
 */
final class JelszoBeallitasTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_kert_link_megerkezik_es_mukodik(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'uj.kollega@pelda.hu']);

        Livewire::test(ElfelejtettJelszo::class)
            ->set('email', 'uj.kollega@pelda.hu')
            ->call('kuldes')
            ->assertHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $ertesites) use ($user): bool {
            Livewire::test(JelszoBeallitas::class, ['token' => $ertesites->token])
                ->set('email', $user->email)
                ->set('jelszo', 'UjJelszo123!')
                ->set('jelszo_megerosites', 'UjJelszo123!')
                ->call('beallit')
                ->assertHasNoErrors();

            return true;
        });

        $this->assertTrue(Hash::check('UjJelszo123!', $user->fresh()->password));
    }

    /**
     * Ismeretlen címre ugyanaz a válasz megy, mint ismertre — különben ez a
     * képernyő megmondaná egy idegennek, kinek van nálunk fiókja.
     */
    public function test_ismeretlen_cimre_ugyanaz_a_valasz(): void
    {
        Notification::fake();

        Livewire::test(ElfelejtettJelszo::class)
            ->set('email', 'nincs.ilyen@pelda.hu')
            ->call('kuldes')
            ->assertHasNoErrors()
            ->assertSet('uzenet', 'Ha ehhez a címhez tartozik fiók, elküldtük rá a jelszóbeállító linket.');

        Notification::assertNothingSent();
    }

    public function test_hamis_token_nem_allit_be_jelszot(): void
    {
        $user = User::factory()->create();

        Livewire::test(JelszoBeallitas::class, ['token' => 'kitalalt-token'])
            ->set('email', $user->email)
            ->set('jelszo', 'UjJelszo123!')
            ->set('jelszo_megerosites', 'UjJelszo123!')
            ->call('beallit')
            ->assertHasErrors('email');
    }

    /**
     * A visszaállító levelet nem a kérő kapja, hanem a cím tulajdonosa. Korlát
     * nélkül tehát bárki teleszórhatná egy idegen postafiókját a mi nevünkben —
     * és a mi levelezőnk hírnevét vinné rá.
     *
     * A Laravel jelszóbrókere önmagában is szüneteltet (`auth.passwords.users.throttle`,
     * alapból 60 másodperc), de az a **felhasználó sorához** kötődik: nem létező
     * címnél nem lép működésbe, és óránként így is hatvan levelet enged át.
     * Ez a korlát a kérőre vonatkozik, ezért mindkét esetet lefedi.
     */
    public function test_ugyanarra_a_cimre_nem_lehet_vegtelenszer_kerni(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'aron@pelda.hu']);

        for ($i = 0; $i < 3; $i++) {
            Livewire::test(ElfelejtettJelszo::class)
                ->set('email', 'aron@pelda.hu')
                ->call('kuldes')
                ->assertHasNoErrors();
        }

        Livewire::test(ElfelejtettJelszo::class)
            ->set('email', 'aron@pelda.hu')
            ->call('kuldes')
            ->assertHasErrors('email');

    }

    /**
     * A címenkénti korlát nem fogná meg azt, aki ezer különböző címre küld
     * egyet-egyet, ezért a gépnek is van saját kerete.
     */
    public function test_egy_gep_nem_szorhat_szet_akarmennyi_cimre(): void
    {
        Notification::fake();

        for ($i = 0; $i < 10; $i++) {
            Livewire::test(ElfelejtettJelszo::class)
                ->set('email', "valaki{$i}@pelda.hu")
                ->call('kuldes')
                ->assertHasNoErrors();
        }

        Livewire::test(ElfelejtettJelszo::class)
            ->set('email', 'meg-egy@pelda.hu')
            ->call('kuldes')
            ->assertHasErrors('email');
    }
}
