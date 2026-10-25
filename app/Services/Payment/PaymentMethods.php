<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Exceptions\PaymentMethodException;
use App\Support\Iban;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * "Hangi ödeme yöntemleri açık?" sorusunun TEK cevabı (Faz 13, K134–K135).
 *
 * Kaynak `.env`'de tek satır: `PAYMENT_METHODS=gift_code` ya da
 * `PAYMENT_METHODS=card,bank_transfer,gift_code`.
 *
 * 🔴 İki kullanıcısı var ve ikisi de buradan sorar:
 *   - StartCheckoutAction / PreviewGiftCodeAction → kapalı yöntemi REDDEDER
 *   - fiyat uçları (PricingResource)              → açık yöntemleri BİLDİRİR
 * Frontend düğmeyi gizler ama karar burada verilir: frontend'i atlatan bir
 * istek de kapalı yöntemi kullanamaz (paywall'ın "istemciye güvenme" ilkesi).
 *
 * Havale/EFT ikinci bir koşula bağlıdır: hesap bilgisi tam ve IBAN geçerli
 * olmalı. Listede olup hesabı eksik olan havale KAPALI sayılır — yanlış bir
 * IBAN göstermek, seçeneği hiç göstermemekten her zaman kötüdür.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/PaymentMethods.md
 */
final class PaymentMethods
{
    /**
     * Açık yöntemler, `PaymentMethod::cases()` sırasında (kart, havale, kod).
     *
     * Sıra istemcinin çizdiği sıradır; `.env`'deki yazılış sırası değil —
     * aynı ayar iki farklı biçimde yazılınca ekran değişmesin.
     *
     * @return list<PaymentMethod>
     *
     * @throws PaymentMethodException Listede tanımsız bir kelime var (503)
     */
    public function enabled(): array
    {
        $listed = $this->listed();

        return array_values(array_filter(
            PaymentMethod::cases(),
            fn (PaymentMethod $method): bool => in_array($method, $listed, true)
                && ($method !== PaymentMethod::BankTransfer || $this->bankAccount() !== null),
        ));
    }

    /** @throws PaymentMethodException */
    public function isEnabled(PaymentMethod $method): bool
    {
        return in_array($method, $this->enabled(), true);
    }

    /**
     * Kapalıysa 403 `PAYMENT_METHOD_DISABLED` fırlatır.
     *
     * @throws PaymentMethodException
     */
    public function ensureEnabled(PaymentMethod $method): void
    {
        if (! $this->isEnabled($method)) {
            throw PaymentMethodException::disabled($method);
        }
    }

    /**
     * Havale hesabı; eksik ya da IBAN'ı geçersizse null.
     *
     * Yöntemin açık olup olmadığından BAĞIMSIZ sorulabilir (Iban testleri ve
     * uyarı log'u için) ama dışarıya yalnızca yöntem açıkken verilir.
     */
    public function bankAccount(): ?BankTransferAccount
    {
        $bankName = trim((string) Config::get('payment.bank_transfer.bank_name'));
        $holder = trim((string) Config::get('payment.bank_transfer.account_holder'));
        $iban = Iban::normalize((string) Config::get('payment.bank_transfer.iban'));

        if ($bankName === '' || $holder === '' || $iban === '') {
            return null;
        }

        if (! Iban::isValid($iban)) {
            // Sessiz değil: bu bir yazım hatası ve müşterinin parası yanlış
            // hesaba gidebilirdi. IBAN'ın kendisi log'a GİRMEZ.
            Log::warning('Bank transfer disabled: BANK_TRANSFER_IBAN fails the checksum.');

            return null;
        }

        return new BankTransferAccount($bankName, $holder, $iban);
    }

    /**
     * `.env`'de yazılanlar. Boşluk ve büyük/küçük harf önemsiz; boş liste
     * "hiçbir yöntem açık değil" demektir (satış durur, ama bilerek).
     *
     * @return list<PaymentMethod>
     *
     * @throws PaymentMethodException
     */
    private function listed(): array
    {
        $raw = Config::get('payment.methods');
        $tokens = is_string($raw) ? explode(',', $raw) : [];
        $methods = [];

        foreach ($tokens as $token) {
            $token = strtolower(trim($token));

            if ($token === '') {
                continue;
            }

            $methods[] = PaymentMethod::tryFrom($token)
                ?? throw PaymentMethodException::misconfigured($token);
        }

        return $methods;
    }
}
