<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * API hata kodu katalogu — sozlesmenin tek dogruluk kaynagi (K20): backend METIN degil KOD dondurur, metin frontend'in.
 * Ayrintili aciklama: docs/rehber/app/Enums/ErrorCode.md (E1–E9: koddan tasinan notlar, Faz 21.6)
 */
enum ErrorCode: string
{
    // 400 — istek bicimsel olarak bozuk
    case MalformedRequest = 'MALFORMED_REQUEST';

    // 401 — kimlik yok veya gecersiz. Frontend bu durumda oturumu dusurur.
    case Unauthenticated = 'UNAUTHENTICATED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';

    // 402 — odeme gerekli
    case PaywallTierInsufficient = 'PAYWALL_TIER_INSUFFICIENT';
    case PaymentRequired = 'PAYMENT_REQUIRED';

    // 403 — kimlik var, islem yasak
    case RsvpDeadlinePassed = 'RSVP_DEADLINE_PASSED';
    case RsvpQuotaExceeded = 'RSVP_QUOTA_EXCEEDED';
    case MediaQuotaExceeded = 'MEDIA_QUOTA_EXCEEDED';

    /** Odeme yontemi `.env`'de kapali (Faz 13, K134). 403, 402 degil: isletmenin karari, kullanicinin eksigi degil (E2). */
    case PaymentMethodDisabled = 'PAYMENT_METHOD_DISABLED';

    // 404 — kaynak yok VEYA senin degil (H7: sahiplik yoksa 403 degil 404)
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';

    // 409 — durum catismasi
    case InvitationAlreadyPublished = 'INVITATION_ALREADY_PUBLISHED';

    /** Faz 19 (K192, FE-50): kullanıcı başına yayınlanmamış davetiye tavanı doldu. */
    case DraftLimitReached = 'DRAFT_LIMIT_REACHED';

    /** Bu hak iptal edilip iade istenemez (Faz 14, K139). 409: engel siparisin SU ANKI durumu; `reason` (E3). */
    case RefundNotAllowed = 'REFUND_NOT_ALLOWED';

    // 413 — govde cok buyuk
    case FileTooLarge = 'FILE_TOO_LARGE';

    // 422 — dogrulama basarisiz
    case ValidationFailed = 'VALIDATION_FAILED';
    case RegistrationFailed = 'REGISTRATION_FAILED';

    /** Parola sifirlama baglantisi gecersiz (Faz 10.31). Tek kod, uc sebep; ayrilsaydi hesap tarayicisi olurdu (H6, E4). */
    case PasswordResetInvalid = 'PASSWORD_RESET_INVALID';

    /** E-posta degisikligi onay baglantisi gecersiz (Faz 14, K144). Tek kod, bes sebep (H6, E5). */
    case EmailChangeInvalid = 'EMAIL_CHANGE_INVALID';

    /** Faz 19 (K191, SEC-09): adres doğrulanmadan ödeme başlatılamaz ve yayınlanamaz. */
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';

    /**
     * Faz 19 (K191): doğrulama bağlantısı geçersiz. Tek kod, çok sebep (imza, süre,
     * kullanılmış, adres değişmiş, hesap yok); EMAIL_CHANGE_INVALID ile aynı gerekçe (H6).
     */
    case EmailVerificationInvalid = 'EMAIL_VERIFICATION_INVALID';

    /** Hediye kodu kullanilamaz (Faz 11, D7). Tek kod, dort sebep; ayrilsaydi var olan kodlar ogrenilirdi (H6, E6). */
    case GiftCodeInvalid = 'GIFT_CODE_INVALID';

    /** Bu kullanıcı bu kodu zaten kullanmış (kişi başı 1, D5). */
    case GiftCodeAlreadyUsed = 'GIFT_CODE_ALREADY_USED';

    /** Kod geçerli ama seçilen plan için değil (ücretsiz ya da plana özel kod). */
    case GiftCodeNotApplicable = 'GIFT_CODE_NOT_APPLICABLE';

    // 429 — hiz siniri VE zamana bagli kota
    case RateLimited = 'RATE_LIMITED';

    /** 🔴 Asistanin GUNLUK mesaj butcesi doldu (Faz 8). 429: zamana bagli butce; RATE_LIMITED'dan ayri metin (E7). */
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
            self::InvalidCredentials => 401,

            self::PaywallTierInsufficient,
            self::PaymentRequired => 402,

            self::RsvpDeadlinePassed,
            self::RsvpQuotaExceeded,
            self::MediaQuotaExceeded,
            self::EmailNotVerified,
            self::DraftLimitReached,
            self::PaymentMethodDisabled => 403,

            self::ResourceNotFound => 404,

            self::InvitationAlreadyPublished,
            self::RefundNotAllowed => 409,

            self::FileTooLarge => 413,

            self::ValidationFailed,
            self::RegistrationFailed,
            self::PasswordResetInvalid,
            self::EmailChangeInvalid,
            self::EmailVerificationInvalid,
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
            // Iki kod da 'requiredTier' tasir; ayrim kullanicinin onundeki eylemde (E8).
            self::PaywallTierInsufficient,
            self::PaymentRequired => ['requiredTier'],
            self::RsvpQuotaExceeded => ['remaining', 'limit'],

            // 🔴 'remaining' YOK, yalnizca 'limit'. Kalan sayi kac dosyanin
            // yuklendigini ele verir; sahip zaten kendi galerisini goruyor ama
            // misafirin LCV yuklemesi de ayni kodu doneriyor (H9).
            self::MediaQuotaExceeded => ['limit'],
            self::FileTooLarge => ['max'],

            // K192: kullanıcının KENDİ taslak tavanı (ürün sabiti); arayüz "en çok N taslak" der.
            self::DraftLimitReached => ['limit'],
            self::RateLimited, self::ProviderUnavailable => ['retryAfter'],

            // 'limit' sizinti degil: kullanicinin KENDI gunluk hakki; 'remaining' kota dolunca zaten 0 (H9, E9).
            self::AssistantQuotaExceeded => ['retryAfter', 'limit'],

            // Kodun geçtiği plan: kodu elinde tutan kullanıcı bunu zaten
            // hak ediyor ("bu kod Gold içindir"). INVALID'de parametre YOK (D7).
            self::GiftCodeNotApplicable => ['tier'],

            // Kapatılan yöntem: hangi düğmenin gizleneceği. Sır değil, fiyat
            // ucu açık yöntemleri zaten herkese söylüyor.
            self::PaymentMethodDisabled => ['method'],

            // Neden iade edilemediği (RefundBlocker). Kullanıcı yalnızca KENDİ
            // siparişini soruyor; başkasınınki 404 alır (H7), sebep sızmaz.
            self::RefundNotAllowed => ['reason'],
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
