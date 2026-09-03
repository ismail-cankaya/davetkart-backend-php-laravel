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

    // 429 — hiz siniri
    case RateLimited = 'RATE_LIMITED';

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
            self::MediaQuotaExceeded => 403,

            self::ResourceNotFound => 404,

            self::InvitationAlreadyPublished,
            self::SlugTaken => 409,

            self::FileTooLarge => 413,

            self::ValidationFailed,
            self::RegistrationFailed => 422,

            self::RateLimited => 429,

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
