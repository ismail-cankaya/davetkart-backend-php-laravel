<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\PublishEntitlementResolver;
use App\Contracts\RsvpQuotaResolver;
use App\Exceptions\AiProviderException;
use App\Exceptions\PaymentProviderException;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\NullProvider;
use App\Services\Payment\FakeGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\ShopierGateway;
use App\Services\Pricing\OrderEntitlementResolver;
use App\Services\Rsvp\SubscriptionRsvpQuotaResolver;
use App\Support\EmailNormalizer;
use App\Support\Iban;
use App\Support\IpBucket;
use App\Support\MediaDiskGuard;
use App\Support\ProductionMailCheck;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Konteyner baglamalari: "bu arayuz istenince su sinifi ver" (kota K42/K51, odeme K8, AI K70).
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P1.
     */
    public function register(): void
    {
        $this->app->bind(RsvpQuotaResolver::class, SubscriptionRsvpQuotaResolver::class);

        $this->app->bind(PaymentGateway::class, $this->resolvePaymentGateway(...));

        // K42: yayin hakki iki kaynaktan (tekil + paket) ama tek arayuzden.
        $this->app->bind(PublishEntitlementResolver::class, OrderEntitlementResolver::class);

        // K8'in ikinci uygulamasi: AI saglayicisi da bir arayuzun arkasinda.
        $this->app->bind(AiProvider::class, $this->resolveAiProvider(...));
    }

    /**
     * Aktif odeme surucusunu config'ten secer — Strategy Pattern'in "hangi strateji?" karari (K8).
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P2.
     */
    private function resolvePaymentGateway(Application $app): PaymentGateway
    {
        $default = Config::string('payment.default');

        /** @var mixed $driver */
        $driver = Config::get("payment.providers.{$default}.driver");

        return match ($driver) {
            'fake' => $app->make(FakeGateway::class),

            // Faz 12: sir ya da OSB kimligi eksikse kurucu 503 firlatir (K123).
            // Kontrol kurucuda, burada degil: callback/OSB uclari surucuyu
            // DOGRUDAN istiyor ve ayni kapidan gecmeliler.
            'shopier' => $app->make(ShopierGateway::class),

            default => throw PaymentProviderException::unavailable($default),
        };
    }

    /**
     * Aktif AI surucusunu config'ten secer — resolvePaymentGateway()'in BIREBIR ikizi (K70).
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P3.
     */
    private function resolveAiProvider(Application $app): AiProvider
    {
        $default = Config::string('ai.default');

        /** @var mixed $driver */
        $driver = Config::get("ai.providers.{$default}.driver");

        return match ($driver) {
            'gemini' => $app->make(GeminiProvider::class),
            'null' => $app->make(NullProvider::class),

            default => throw AiProviderException::unavailable($default),
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
        $this->configureLocalServer();
        $this->configureRateLimiting();
        $this->configureValidationRules();
        $this->configureAboutCommand();
        $this->configureMailCheck();
    }

    /** K183/K185: `php artisan about` medya diskini ve posta sürücüsünü üretim uyarısıyla gösterir. */
    private function configureAboutCommand(): void
    {
        AboutCommand::add('DavetKart', fn (): array => [
            'Media disk' => $this->app->make(MediaDiskGuard::class)->aboutLine(),
            'Mail mailer' => $this->app->make(ProductionMailCheck::class)->aboutLine(),
        ]);
    }

    /** K185: alarm açılışta değil gönderim anında; açılış her istekte olur ve her isteği alarma çevirirdi. */
    private function configureMailCheck(): void
    {
        Event::listen(MessageSending::class, function (): void {
            $this->app->make(ProductionMailCheck::class)->reportIfSilent();
        });
    }

    /**
     * Uygulamanın kendi doğrulama kuralları (Faz 10, 10.62).
     *
     * Kural nesnesi değil, ADI olan bir kural: hata zarfına {"rule":"iban"}
     * olarak çıkar, sınıf adı sözleşmeye sızmaz (D6).
     */
    private function configureValidationRules(): void
    {
        Validator::extend(
            'iban',
            static fn (string $attribute, mixed $value): bool => is_string($value) && Iban::isValid($value),
        );
    }

    /**
     * Auth uc noktalari icin hiz siniri (K36).
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P4.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', $this->authLimits(...));
        RateLimiter::for('rsvp', $this->rsvpLimits(...));
        RateLimiter::for('media', $this->guestMediaLimits(...));
        RateLimiter::for('assistant', $this->assistantLimits(...));
        RateLimiter::for('contact', $this->contactLimits(...));
        RateLimiter::for('gift-code', $this->giftCodeLimits(...));
        RateLimiter::for('api', $this->apiLimits(...));
        RateLimiter::for('public-read', $this->publicReadLimits(...));
        RateLimiter::for('email-verification', $this->emailVerificationLimits(...));
    }

    /**
     * 🔴 LCV gonderimi — sistemin tek auth'suz YAZMA yolu (Faz 5).
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P5.
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
                ->by('rsvp-ip|'.IpBucket::of($request->ip())),

            Limit::perHour(Config::integer('davetkart.rsvp.rate_limit.per_invitation_per_hour'))
                ->by('rsvp-inv|'.$invitation),
        ];
    }

    /**
     * 🔴 Misafirin MEDYA yuklemesi — sistemin IKINCI auth'suz yazma yolu (Faz 6).
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P6.
     *
     * @return list<Limit>
     */
    private function guestMediaLimits(Request $request): array
    {
        $invitation = $request->route('invitation');
        $invitation = is_string($invitation) ? $invitation : 'bilinmeyen';

        return [
            Limit::perMinute(Config::integer('davetkart.media.rate_limit.guest_per_ip_per_minute'))
                ->by('media-ip|'.IpBucket::of($request->ip())),

            Limit::perHour(Config::integer('davetkart.media.rate_limit.guest_per_invitation_per_hour'))
                ->by('media-inv|'.$invitation),
        ];
    }

    /**
     * 🔴 Asistan — sistemin PARA HARCAYAN tek ucu (Faz 8).
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P7.
     *
     * @return list<Limit>
     */
    private function assistantLimits(Request $request): array
    {
        // 🔴 Rota seviyesinde, auth:sanctum'dan SONRA calisir: user() burada cozulmus gelir (apiLimits'te null).
        // Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P8.
        $user = $request->user();

        $identity = $user !== null
            ? 'user|'.$user->id
            : 'ip|'.IpBucket::of($request->ip());   // savunma amacli yedek; normalde erisilmez

        return [
            Limit::perMinute(
                Config::integer('davetkart.assistant.rate_limit.per_user_per_minute'),
            )->by('assistant|'.$identity),
        ];
    }

    /**
     * K191 (SEC-09): doğrulama postasını yeniden gönderme — kullanıcı başına saatte 3.
     * Dışarıya posta gönderen bir uç: kova posta bombalamasını ve SES itibarını korur.
     *
     * @return list<Limit>
     */
    private function emailVerificationLimits(Request $request): array
    {
        $user = $request->user();
        $identity = $user instanceof User ? 'user|'.$user->id : 'ip|'.IpBucket::of($request->ip());

        return [
            Limit::perHour(Config::integer('davetkart.auth.email_verification_resend_per_hour'))
                ->by('email-verification|'.$identity),
        ];
    }

    /**
     * 🔴 Iletisim formu — sistemin DORDUNCU auth'suz yazma yolu (Faz 8).
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P9.
     *
     * @return list<Limit>
     */
    private function contactLimits(Request $request): array
    {
        $ip = IpBucket::of($request->ip());

        return [
            Limit::perMinute(
                Config::integer('davetkart.contact.rate_limit.per_ip_per_minute'),
            )->by('contact-min|'.$ip),

            Limit::perHour(
                Config::integer('davetkart.contact.rate_limit.per_ip_per_hour'),
            )->by('contact-hour|'.$ip),
        ];
    }

    /**
     * 🔴 Hediye kodu denemesi (Faz 11): kod para degerinde, anahtar KULLANICI; onizleme ve kodlu checkout ayni kova.
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P10.
     *
     * @return list<Limit>
     */
    private function giftCodeLimits(Request $request): array
    {
        $code = $request->input('code', $request->input('giftCode'));

        if (! is_string($code) || trim($code) === '') {
            return [Limit::none()];
        }

        /** @var User|null $user */
        $user = $request->user();

        $identity = $user !== null
            ? 'user|'.$user->id
            : 'ip|'.IpBucket::of($request->ip());   // savunma amacli yedek; normalde erisilmez

        return [
            Limit::perMinute(
                Config::integer('davetkart.gift_codes.rate_limit.per_user_per_minute'),
            )->by('gift-code-min|'.$identity),

            Limit::perHour(
                Config::integer('davetkart.gift_codes.rate_limit.per_user_per_hour'),
            )->by('gift-code-hour|'.$identity),
        ];
    }

    /**
     * Genel API tavani (K186, SEC-06): kimligi bilinen istek kullanici kovasinda, bilinmeyen IP kovasinda.
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P11.
     *
     * @return list<Limit>
     */
    private function apiLimits(Request $request): array
    {
        $user = $request->user();

        if ($user instanceof User) {
            return [
                Limit::perMinute(Config::integer('davetkart.api.rate_limit.per_user_per_minute'))
                    ->by('api|user|'.$user->id),
            ];
        }

        return [
            Limit::perMinute(Config::integer('davetkart.api.rate_limit.per_ip_per_minute'))
                ->by('api|'.IpBucket::of($request->ip())),
        ];
    }

    /**
     * K186: misafir davetiye okumasi (GET /public/invitations/{id}) kendi kovasinda.
     * Uc onbellekli ve ETag'li (K7); tavan yalnizca ULID yagmuruna karsi.
     *
     * @return list<Limit>
     */
    private function publicReadLimits(Request $request): array
    {
        return [
            Limit::perMinute(Config::integer('davetkart.api.rate_limit.public_read_per_ip_per_minute'))
                ->by('public-read|'.IpBucket::of($request->ip())),
        ];
    }

    /**
     * Auth kovasi: anahtar dogrulamadan once, kayit/girisle AYNI e-posta normalizasyonuyla (Faz 10.13).
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P12.
     *
     * @return list<Limit>
     */
    private function authLimits(Request $request): array
    {
        $email = $request->input('email');
        $identity = is_string($email) ? EmailNormalizer::normalize($email) : 'anonim';

        return [
            Limit::perMinute(5)->by($identity.'|'.IpBucket::of($request->ip())),
            Limit::perMinute(20)->by(IpBucket::of($request->ip())),
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

    /**
     * 🔴 `php artisan serve` altinda HER dosya yuklemesi dusuyordu (Windows).
     * Ayrinti: docs/rehber/app/Providers/AppServiceProvider.md P13.
     */
    private function configureLocalServer(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        ServeCommand::$passthroughVariables = array_values(array_unique([
            ...ServeCommand::$passthroughVariables,
            'TEMP',
            'TMP',
            'TMPDIR',
        ]));
    }
}
