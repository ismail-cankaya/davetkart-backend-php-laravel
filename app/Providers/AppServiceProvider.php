<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\RsvpQuotaResolver;
use App\Exceptions\PaymentProviderException;
use App\Services\Payment\FakeGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Rsvp\TierRsvpQuotaResolver;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
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

        $this->app->bind(PaymentGateway::class, $this->resolvePaymentGateway(...));
    }

    /**
     * Aktif odeme surucusunu config'ten secer — Strategy Pattern'in
     * "hangi strateji?" karari (K8).
     *
     * 🔴 Bu closure KAYIT aninda degil COZUM aninda calisir. Aksi halde
     * config henuz yuklenmemis olabilirdi ve bir yapilandirma hatasi
     * uygulamanin ACILISINI kirardi — oysa yalnizca ODEME uclarini kirmasi
     * gerekir (saglik sondasi, davetiye okuma, LCV calismaya devam etmeli).
     *
     * 🔴 Bilinmeyen surucu SESSIZCE null donmez, PROVIDER_UNAVAILABLE (503)
     * firlatir. Sessiz bir varsayilan ("bulamazsan fake kullan") uretimde
     * IYZICO_API_KEY eksik oldugu gun her odemeyi sahte olarak BASARILI
     * sayardi — bir yapilandirma hatasinin sessizce bedava yayina donusmesi.
     */
    private function resolvePaymentGateway(Application $app): PaymentGateway
    {
        $default = Config::string('payment.default');

        /** @var mixed $driver */
        $driver = Config::get("payment.providers.{$default}.driver");

        return match ($driver) {
            'fake' => $app->make(FakeGateway::class),

            // Faz 9: 'iyzico' => $app->make(IyzicoGateway::class),
            default => throw PaymentProviderException::unavailable($default),
        };
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
        RateLimiter::for('rsvp', $this->rsvpLimits(...));
        RateLimiter::for('media', $this->guestMediaLimits(...));
        RateLimiter::for('api', $this->apiLimits(...));
    }

    /**
     * 🔴 LCV gonderimi — sistemin tek auth'suz YAZMA yolu (Faz 5).
     *
     * IKI kova birlikte calisir, cunku iki farkli saldiri sekli var:
     *   1) Tek kaynaktan seri gonderim        -> IP anahtari (dakikada 10)
     *   2) Botnet'ten tek davetiyeye yagmur   -> davetiye anahtari (saatte 60)
     *
     * Ikincisi olmasaydi 500 farkli IP'den gelen istek hicbir limite takilmaz
     * ve bir davetiyenin LCV listesi coplenirdi; birincisi olmasaydi tek bir
     * makine tum davetiyeleri sirayla doldurabilirdi.
     *
     * Kota bu limitin YERINE GECMEZ (5.7): hiz siniri ne kadar cok/hizli
     * gonderildigine bakar, kota kac MISAFIR yazildigina.
     *
     * @return list<Limit>
     */
    private function rsvpLimits(Request $request): array
    {
        // Rota parametresi: throttle middleware'i rota eslesmesinden SONRA
        // calisir, dolayisiyla burada okunabilir.
        $invitation = $request->route('invitation');
        $invitation = is_string($invitation) ? $invitation : 'bilinmeyen';

        return [
            Limit::perMinute(Config::integer('davetkart.rsvp.rate_limit.per_ip_per_minute'))
                ->by('rsvp-ip|'.$request->ip()),

            Limit::perHour(Config::integer('davetkart.rsvp.rate_limit.per_invitation_per_hour'))
                ->by('rsvp-inv|'.$invitation),
        ];
    }

    /**
     * 🔴 Misafirin MEDYA yuklemesi — sistemin IKINCI auth'suz yazma yolu (Faz 6).
     *
     * Kalip rsvpLimits() ile ayni (IP kovasi + davetiye kovasi) ama sayilar
     * DAHA DAR, iki sebeple:
     *
     *   1. Honeypot YOK. Faz 5'te bot, tek sorgu bile actirmadan eleniyordu;
     *      dosya yuklemede gorunmez alan diye bir sey yok. Bu limiter, orada
     *      honeypot'un yaptigi isi de ustlenmek zorunda.
     *   2. Istek basina maliyet on kat. Bir LCV satiri birkac yuz bayt; bir
     *      video 20 MB + MIME analizi + kuyrukta yeniden kodlama.
     *
     * Kota bu limitin YERINE GECMEZ (L3): limit "ne siklikta"ya, kota "kac
     * dosya"ya bakar. Suresiz bir saldirgan yavaslar ama kotayi yine doldurur.
     *
     * @return list<Limit>
     */
    private function guestMediaLimits(Request $request): array
    {
        $invitation = $request->route('invitation');
        $invitation = is_string($invitation) ? $invitation : 'bilinmeyen';

        return [
            Limit::perMinute(Config::integer('davetkart.media.rate_limit.guest_per_ip_per_minute'))
                ->by('media-ip|'.$request->ip()),

            Limit::perHour(Config::integer('davetkart.media.rate_limit.guest_per_invitation_per_hour'))
                ->by('media-inv|'.$invitation),
        ];
    }

    /**
     * Genel API tavani — FAZ-4 §9.2'nin acik borcu.
     *
     * Faz 4'te fark edilmisti: public davetiye ucunda 404'ler CACHE'LENMIYOR,
     * yani rastgele ULID yagdiran biri her istekte bir sorgu actirabiliyordu.
     * Ayrica logout/me uclarinin hicbir siniri yoktu.
     *
     * 🔴 Anahtar yalnizca IP: bu limiter GRUP seviyesinde, yani auth:sanctum'DAN
     * ONCE calisir. $request->user() burada zaten null doner (varsayilan guard
     * 'web'), ustelik T13'te ogrenildigi gibi guard'a erken dokunmak onbellek
     * tuzagi acar. Kullanici bazli tavan gerekirse rota seviyesinde ayri bir
     * limiter tanimlanir.
     *
     * @return list<Limit>
     */
    private function apiLimits(Request $request): array
    {
        return [
            Limit::perMinute(60)->by('api|'.$request->ip()),
        ];
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
