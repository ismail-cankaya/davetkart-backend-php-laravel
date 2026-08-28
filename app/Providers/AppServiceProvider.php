<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\RsvpQuotaResolver;
use App\Services\Rsvp\TierRsvpQuotaResolver;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Konteyner baglamalari burada yapilir: "bu arayuz istendiginde su sinifi
     * ver". Faz 7'de LCV kotasi gercek abonelik kayitlarindan okunacak (K42);
     * o gun degisecek TEK satir asagidakidir — SubmitRsvpAction'a dokunulmaz.
     */
    public function register(): void
    {
        $this->app->bind(RsvpQuotaResolver::class, TierRsvpQuotaResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureModels();
        $this->configureDates();
        $this->configureCommands();
        $this->configureRateLimiting();
    }

    /**
     * Auth uc noktalari icin hiz siniri (K36).
     *
     * IKI limit birlikte calisir, cunku iki farkli saldiri sekli var:
     *   1) Ayni hesaba tekrarli deneme (brute-force)  -> e-posta + IP anahtari
     *   2) Ayni IP'den cok hesaba yayilma (spraying)  -> yalnizca IP anahtari
     *
     * Ayrica K32 (Argon2id) her denemeyi 64 MB + ~200 ms yaptigi icin sinirsiz
     * cagri bir BELLEK TUKETIMI saldirisidir; limit onu da kapatir.
     * Ayrintili aciklama: docs/rehber/app/Providers/AppServiceProvider.md §4
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', $this->authLimits(...));
    }

    /**
     * Anahtar dogrulamadan ONCE hesaplanir; `email` dizi de gelebilir.
     *
     * @return list<Limit>
     */
    private function authLimits(Request $request): array
    {
        $email = $request->input('email');
        $identity = is_string($email) ? mb_strtolower(trim($email)) : 'anonim';

        return [
            Limit::perMinute(5)->by($identity.'|'.$request->ip()),
            Limit::perMinute(20)->by((string) $request->ip()),
        ];
    }

    /**
     * Eloquent kati kip. Uc korumayi birden acar: lazy loading (N+1),
     * sessizce atilan alanlar, olmayan alana erisim.
     * Uretimde KAPALI: hata musteri istegini dusurmesin, log'a dussun.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    /**
     * Tarihler degismez (immutable) olsun; ->addDay() cagrisi orijinali
     * degistirmek yerine yeni ornek dondursun.
     */
    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    /**
     * Uretimde migrate:fresh / migrate:reset / db:wipe komutlarini engelle.
     */
    private function configureCommands(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
