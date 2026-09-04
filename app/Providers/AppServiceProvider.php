<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\SetCompany;
use App\Services\Billing\StripeSzolgaltatas;
use App\Services\Billing\SzamlazoKapu;
use App\Support\Berlo;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Berlo::class);

        // A túlhasznalat-elszámolás csak a `SzamlazoKapu`-t ismeri; élesben ezt
        // a Stripe szolgáltatja.
        $this->app->bind(SzamlazoKapu::class, StripeSzolgaltatas::class);
    }

    public function boot(): void
    {
        // Élesben a tárhely a TLS-t a proxy előtt zárja: enélkül a generált
        // linkek http-re mutatnának.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Fejlesztés közben a néma hibák kerülnek a legtöbbe: a lusta betöltés
        // és a nem létező attribútum írása is hangos legyen.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // A `ceg` middleware csak az oldal első betöltésekor fut le — minden
        // további Livewire-akció (gombnyomás, feltöltés) egy saját, belső
        // /livewire/update kérésen megy, amit Livewire alapból NEM enged át a
        // saját middleware-jeinken, csak egy rövid listán (auth, kötésfeloldás).
        // Enélkül a Berlo minden ilyen kérésnél üresen indulna.
        Livewire::addPersistentMiddleware([SetCompany::class]);

        // A jelszóbeállító levél magyarul. Ez az egyetlen levél, amit a
        // rendszer küld, ezért nem építünk köré fordítási réteget.
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $percek = (int) config('auth.passwords.users.expire', 60);

            return (new MailMessage)
                ->subject('SzámlaFolyó — jelszó beállítása')
                ->greeting('Szia!')
                ->line('Ezzel a linkkel tudsz új jelszót beállítani a SzámlaFolyóhoz.')
                ->action('Jelszó beállítása', url(route('password.reset', [
                    'token' => $token,
                    'email' => $notifiable->getEmailForPasswordReset(),
                ], absolute: false)))
                ->line("A link {$percek} percig érvényes.")
                ->line('Ha nem te kérted, ezt a levelet nyugodtan hagyd figyelmen kívül — a jelszavad nem változik.')
                ->salutation('Üdvözlettel, a SzámlaFolyó');
        });
    }
}
