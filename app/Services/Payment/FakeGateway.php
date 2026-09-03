<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Models\Order;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Gelistirme ve test saglayicisi — dis aga HIC cikmaz (K8).
 *
 * 🔴 Bu sinif bir "yer tutucu" (stub) DEGIL, calisan bir surucudur: gercek bir
 * referans uretir, gercek bir imza dogrular, gercek bir sozluk cevirisi yapar.
 * Fark yalnizca odemenin gerceklesmemesidir.
 *
 * Gerekce: akisin dogrulugu saglayici anlasmasindan ONCE kanitlanabilsin.
 * Bos govdeli bir sahte surucu yazsaydik, Faz 9'da Iyzico baglandigi gun
 * imza dogrulamasi ve durum cevirisi ILK KEZ calisirdi — yani en pahali
 * yerde (uretimde, para akarken) ilk kez sinanirdi.
 *
 * Null Object Pattern DEGIL: NullProvider "hicbir sey yapma" der (Faz 8'in AI
 * saglayicisi oyle olacak); bu surucu "her seyi yap, yalnizca para alma" der.
 * Ayrintili aciklama: docs/rehber/app/Services/Payment/FakeGateway.md
 */
final class FakeGateway implements PaymentGateway
{
    /** Uretilen referanslarin oneki — gercek saglayici referanslarindan ayirt eder. */
    private const REF_PREFIX = 'fake_';

    public function name(): string
    {
        return 'fake';
    }

    /**
     * Sahte bir odeme oturumu acar.
     *
     * Ag cagrisi YOK: testler ne yavaslar ne kirilgan olur. Gercek surucude
     * burasi bir HTTP istegi olacak ve hatasi StartCheckoutAction'da
     * PaymentProviderException'a cevrilecek (H8).
     */
    public function startCheckout(Order $order): CheckoutSession
    {
        $expiresAt = now()->addMinutes(
            Config::integer('payment.order_expires_after_minutes'),
        )->toImmutable();

        return new CheckoutSession(
            providerRef: self::REF_PREFIX.Str::ulid()->toBase32(),

            // Frontend rotasi; saglayici sayfasi yerine dogrudan "basarili"
            // ekranina donuyoruz. Sorgu parametresi, sahte odemeyi tetikleyecek
            // olan geliştirme aracinin siparisi bulabilmesi icin.
            redirectUrl: Config::string('payment.return_urls.success').'?order='.$order->getKey(),

            expiresAt: $expiresAt,
        );
    }

    /**
     * Webhook govdesini dogrular ve cevirir.
     *
     * @throws InvalidWebhookSignatureException Imza tutmadi -> 404 (sessiz red)
     * @throws BadRequestHttpException Imza dogru ama govde anlamsiz -> 400
     */
    public function parseNotification(string $payload, string $signature): PaymentNotification
    {
        $this->assertSignatureIsValid($payload, $signature);

        // 🔴 SIRA ONEMLI: once imza, sonra govde. Ters sirada, imzasiz bir
        // istek bile JSON ayristirmasi yaptirir ve hata mesaji farkindan
        // "govden bozuk ama imzan sorulmadi" bilgisi sizardi (L1: en ucuz ve
        // en cok eleyen katman en basta).
        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new BadRequestHttpException('Webhook payload is not a JSON object.');
        }

        $ref = $decoded['providerRef'] ?? null;
        $rawStatus = $decoded['status'] ?? null;

        if (! is_string($ref) || $ref === '' || ! is_string($rawStatus)) {
            throw new BadRequestHttpException('Webhook payload is missing providerRef or status.');
        }

        return new PaymentNotification($ref, $this->translateStatus($rawStatus));
    }

    /**
     * 🔴 Imza dogrulamasi — webhook ucunun TEK savunmasi.
     *
     * HMAC (Hash-based Message Authentication Code): govde ile PAYLASILAN SIR
     * birlikte hash'lenir. Siri bilmeyen biri, govdeyi degistirdiginde gecerli
     * bir imza uretemez. Duz `hash('sha256', $payload)` YETMEZ — onu herkes
     * hesaplayabilir; sir, hash'i bir KIMLIK KANITINA cevirir.
     *
     * 🔴 hash_equals() kullaniliyor, `===` DEGIL. Normal string
     * karsilastirmasi ilk farkli baytta durur; saldirgan yanit suresini
     * olcerek imzayi bayt bayt bulabilir (zamanlama saldirisi). hash_equals
     * her zaman TUM baytlari karsilastirir — Faz 2'de LoginUserAction'da
     * ogrenilen A3/A4'un ayni ailesi.
     *
     * Sahte surucu APP_KEY'i sir olarak kullanir: repoya sir yazilmaz ve
     * .env'de zaten var olan bir deger yeniden kullanilir. Gercek surucude
     * bu deger config('payment.providers.iyzico.webhook_secret') olacak.
     */
    private function assertSignatureIsValid(string $payload, string $signature): void
    {
        $expected = hash_hmac('sha256', $payload, Config::string('app.key'));

        if (! hash_equals($expected, $signature)) {
            throw new InvalidWebhookSignatureException;
        }
    }

    /**
     * Saglayicinin sozlugunu BIZIM sozlugumuze cevirir.
     *
     * Sahte surucude ikisi ayni; gercek surucude 'SUCCESS' -> Paid gibi bir
     * esleme olacak. Ceviri surucunun isidir: bu sinirdan sonra sistemde tek
     * bir sozluk konusulur (CLAUDE.md §1, sihirli string yasagi).
     *
     * 🔴 `pending` BILEREK kabul edilmiyor: webhook bir SONUC bildirimidir.
     * "Hala bekliyor" diyen bir bildirim, hicbir gecisi tetiklemeyecegi icin
     * OrderStatus::canTransitionTo() tarafindan zaten reddedilirdi — ama
     * burada reddetmek hatayi kaynagina yakin tutar.
     */
    private function translateStatus(string $raw): OrderStatus
    {
        return match ($raw) {
            'paid' => OrderStatus::Paid,
            'failed' => OrderStatus::Failed,
            'refunded' => OrderStatus::Refunded,
            default => throw new BadRequestHttpException("Unknown provider status '{$raw}'."),
        };
    }
}
