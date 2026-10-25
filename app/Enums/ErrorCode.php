<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * API hata kodu katalogu — sozlesmenin tek dogruluk kaynagi (K20).
 *
 * Backend kullaniciya gosterilecek METIN dondurmez; olayi bildiren bir KOD dondurur.
 * Metne cevirmek frontend'in isidir (10 dil i18next'te zaten var).
 * Ayrintili aciklama: docs/rehber/app/Enums/ErrorCode.md
 */
enum ErrorCode: string
{
    // 400 — istek bicimsel olarak bozuk
    case MalformedRequest = 'MALFORMED_REQUEST';

    // 401 — kimlik yok veya gecersiz. Frontend bu durumda oturumu dusurur.
    case Unauthenticated = 'UNAUTHENTICATED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case TokenExpired = 'TOKEN_EXPIRED';

    // 402 — odeme gerekli
    case PaywallTierInsufficient = 'PAYWALL_TIER_INSUFFICIENT';
    case PaymentRequired = 'PAYMENT_REQUIRED';

    // 403 — kimlik var, islem yasak
    case InvitationLocked = 'INVITATION_LOCKED';
    case RsvpDeadlinePassed = 'RSVP_DEADLINE_PASSED';
    case RsvpQuotaExceeded = 'RSVP_QUOTA_EXCEEDED';
    case MediaQuotaExceeded = 'MEDIA_QUOTA_EXCEEDED';

    /**
     * Ödeme yöntemi `.env`'de kapalı (Faz 13, K134): `PAYMENT_METHODS`.
     *
     * 🔴 403, 402 DEĞİL: 402 "ödeme yap" der, oysa kullanıcı tam da ödemeye
     * çalışıyor. Kapalı bir yöntem kullanıcının aşabileceği bir eksik değil,
     * işletmenin bir kararıdır (LCV kotası gibi bir sınır). `method` hangi
     * yöntem olduğunu söyler; frontend o düğmeyi zaten gizlemiş olmalıydı.
     */
    case PaymentMethodDisabled = 'PAYMENT_METHOD_DISABLED';

    // 404 — kaynak yok VEYA senin degil (H7: sahiplik yoksa 403 degil 404)
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';

    // 409 — durum catismasi
    case InvitationAlreadyPublished = 'INVITATION_ALREADY_PUBLISHED';
    case SlugTaken = 'SLUG_TAKEN';

    // 413 — govde cok buyuk
    case FileTooLarge = 'FILE_TOO_LARGE';

    // 422 — dogrulama basarisiz
    case ValidationFailed = 'VALIDATION_FAILED';
    case RegistrationFailed = 'REGISTRATION_FAILED';

    /**
     * Parola sifirlama baglantisi gecersiz (Faz 10, 10.31).
     *
     * Neden 400 degil 422? Istek BICIMSEL olarak saglam ve dogrulamayi geciyor;
     * reddedilen sey icerigin ANLAMI. REGISTRATION_FAILED ile ayni sinif.
     *
     * 🔴 Tek kod, uc sebep: token yanlis, suresi dolmus (60 dk) ya da e-posta
     * kayitli degil. Ayrilsaydi sifirlama formu bir hesap tarayicisina donerdi
     * (H6, 08 §3.1). Frontend'in tek mesaji: "baglanti gecersiz, yenisini iste".
     */
    case PasswordResetInvalid = 'PASSWORD_RESET_INVALID';

    /**
     * Hediye kodu kullanılamaz (Faz 11, D7).
     *
     * 🔴 Tek kod, dört sebep: yok, kapatılmış, süresi dolmuş ya da tükenmiş.
     * Ayrılsaydı kod deneyen biri hangi kodların VAR olduğunu öğrenirdi
     * (PASSWORD_RESET_INVALID ile aynı gerekçe, H6).
     */
    case GiftCodeInvalid = 'GIFT_CODE_INVALID';

    /** Bu kullanıcı bu kodu zaten kullanmış (kişi başı 1, D5). */
    case GiftCodeAlreadyUsed = 'GIFT_CODE_ALREADY_USED';

    /** Kod geçerli ama seçilen plan için değil (ücretsiz ya da plana özel kod). */
    case GiftCodeNotApplicable = 'GIFT_CODE_NOT_APPLICABLE';

    // 429 — hiz siniri VE zamana bagli kota
    case RateLimited = 'RATE_LIMITED';

    /**
     * 🔴 Asistanin GUNLUK mesaj butcesi doldu (Faz 8).
     *
     * Neden 403 degil 429? K28 LCV kotasini 403 yapmisti cunku o bir
     * KAPASITE sinirdir: misafir bekleyerek asamaz, satin alinan planin
     * kotasi neyse odur. Asistan kotasi ise ZAMANA BAGLI bir butcedir —
     * gece yarisi yenilenir, yani kullanici bekleyerek GERCEKTEN asar.
     * "Belirli bir zaman diliminde cok fazla istek" 429'un tanimidir ve
     * Retry-After (RFC 9110 §10.2.3) burada yaniltici degil DOGRU bilgidir.
     *
     * Neden RATE_LIMITED yeniden kullanilmiyor? "Hizlisin, 30 sn bekle" ile
     * "bugunluk hakkin bitti" frontend'de ayni metni gosteremez; kod, ince
     * ayrimin tasiyicisidir (08 §4).
     */
    case AssistantQuotaExceeded = 'ASSISTANT_QUOTA_EXCEEDED';

    // 5xx — sunucu ve yukari akis (upstream)
    case ServerError = 'SERVER_ERROR';
    case PaymentProviderError = 'PAYMENT_PROVIDER_ERROR';
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';

    /** Bu kodun tek ve degismez HTTP karsiligi. */
    public function status(): int
    {
        return match ($this) {
            self::MalformedRequest => 400,

            self::Unauthenticated,
            self::InvalidCredentials,
            self::TokenExpired => 401,

            self::PaywallTierInsufficient,
            self::PaymentRequired => 402,

            self::InvitationLocked,
            self::RsvpDeadlinePassed,
            self::RsvpQuotaExceeded,
            self::MediaQuotaExceeded,
            self::PaymentMethodDisabled => 403,

            self::ResourceNotFound => 404,

            self::InvitationAlreadyPublished,
            self::SlugTaken => 409,

            self::FileTooLarge => 413,

            self::ValidationFailed,
            self::RegistrationFailed,
            self::PasswordResetInvalid,
            self::GiftCodeInvalid,
            self::GiftCodeAlreadyUsed,
            self::GiftCodeNotApplicable => 422,

            self::RateLimited,
            self::AssistantQuotaExceeded => 429,

            self::ServerError => 500,
            self::PaymentProviderError => 502,
            self::ProviderUnavailable => 503,
        };
    }

    /**
     * Bu kodun disari verebilecegi parametreler — beyaz liste (H9).
     * Varsayilan bos: adi burada gecmeyen hicbir sey disari cikamaz.
     *
     * @return list<string>
     */
    public function allowedParams(): array
    {
        return match ($this) {
            // 🔴 Faz 7: IKI kod da 'requiredTier' tasir. Ayrim kullanicinin
            // onundeki EYLEMDE: PAYMENT_REQUIRED "once bir plan al",
            // PAYWALL_TIER_INSUFFICIENT "planin yetmiyor, yukselt". Ikisinde
            // de frontend hangi plani gosterecegini bilmek zorunda.
            // Sizinti degil: plan fiyat sayfasi zaten herkese acik (08 §3.4).
            self::PaywallTierInsufficient,
            self::PaymentRequired => ['requiredTier'],
            self::RsvpQuotaExceeded => ['remaining', 'limit'],

            // 🔴 'remaining' YOK, yalnizca 'limit'. Kalan sayi kac dosyanin
            // yuklendigini ele verir; sahip zaten kendi galerisini goruyor ama
            // misafirin LCV yuklemesi de ayni kodu doneriyor (H9).
            self::MediaQuotaExceeded => ['limit'],
            self::FileTooLarge => ['max'],
            self::RateLimited, self::ProviderUnavailable => ['retryAfter'],

            // 🔴 'limit' SIZINTI DEGIL: kullanicinin KENDI gunluk hakki, tipki
            // FILE_TOO_LARGE'in 'max'i gibi urun sabiti. 'remaining' YOK —
            // kota doldugunda kalan zaten sifirdir, ikinci bir alan bilgi
            // tasimaz (H9: beyaz liste, "her ihtimale karsi" degil).
            self::AssistantQuotaExceeded => ['retryAfter', 'limit'],

            // Kodun geçtiği plan: kodu elinde tutan kullanıcı bunu zaten
            // hak ediyor ("bu kod Gold içindir"). INVALID'de parametre YOK (D7).
            self::GiftCodeNotApplicable => ['tier'],

            // Kapatılan yöntem: hangi düğmenin gizleneceği. Sır değil, fiyat
            // ucu açık yöntemleri zaten herkese söylüyor.
            self::PaymentMethodDisabled => ['method'],
            default => [],
        };
    }

    /**
     * Beyaz listeyi ZORLAR: listede olmayan anahtarlari sessizce duserir.
     * Sizinti savunmasi boylece belgeye degil koda baglanir.
     *
     * @param  array<string, mixed>  $params
     *
     * @return array<string, mixed>
     */
    public function filterParams(array $params): array
    {
        return array_intersect_key(
            $params,
            array_flip($this->allowedParams()),
        );
    }

    /** Istemci istegi guvenle tekrarlayabilir mi? (429 / 502 / 503) */
    public function isRetryable(): bool
    {
        return in_array($this->status(), [429, 502, 503], true);
    }
}
