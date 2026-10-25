<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Enums\PaymentMethod;
use RuntimeException;

/**
 * Ödeme yöntemi kullanılamıyor (Faz 13, K134).
 *
 * | Kurucu          | Kod                     | Durum | Anlamı |
 * |-----------------|-------------------------|-------|--------|
 * | disabled()      | PAYMENT_METHOD_DISABLED | 403   | Yöntem bilerek kapatılmış |
 * | misconfigured() | PROVIDER_UNAVAILABLE    | 503   | `PAYMENT_METHODS` yanlış yazılmış |
 *
 * 🔴 İkisi ayrı, çünkü biri bir İŞ KARARI (kullanıcıya "bu yöntem şu an yok"
 * denir), diğeri bir YAPILANDIRMA HATASI (izleme alarmı çalar). Yanlış yazılmış
 * bir değeri sessizce yok saymak K70'in yasakladığı şeydir: `PAYMENT_METHODS=
 * gift_cod` yazıldığı gün sistem "hiçbir yöntem açık değil" diye satışı durdurur
 * ve kimse nedenini bilmez.
 * Ayrıntılı açıklama: docs/rehber/app/Exceptions/PaymentMethodException.md
 */
final class PaymentMethodException extends RuntimeException implements HasErrorCode
{
    private function __construct(
        private readonly ErrorCode $errorCode,
        private readonly ?PaymentMethod $method,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Yöntem kapalı. Frontend hangi yöntem olduğunu `method` parametresinden okur. */
    public static function disabled(PaymentMethod $method): self
    {
        return new self(
            ErrorCode::PaymentMethodDisabled,
            $method,
            "Payment method '{$method->value}' is disabled.",
        );
    }

    /** `PAYMENT_METHODS` tanımsız bir kelime içeriyor. Kelimenin kendisi yanıta girmez (H8). */
    public static function misconfigured(string $token): self
    {
        return new self(
            ErrorCode::ProviderUnavailable,
            null,
            "PAYMENT_METHODS contains an unknown method '{$token}'.",
        );
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    /**
     * Beyaz listeden geçer (H9): yalnızca DISABLED `method` taşır.
     *
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return $this->method === null ? [] : ['method' => $this->method->value];
    }
}
