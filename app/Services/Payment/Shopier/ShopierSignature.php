<?php

declare(strict_types=1);

namespace App\Services\Payment\Shopier;

/**
 * Shopier'in üç imza formülü — saf fonksiyonlar, durum yok, ağ yok (12.5).
 *
 * Kaynak: topluluk SDK'sı `erkineren/shopier` ve OSB örnekleri (kılavuz §1).
 * Paket bağımlılık olarak EKLENMEDİ (K120): HTML basıp `die` ediyor, bizim
 * mimarimizde formu frontend gönderiyor; alınacak şey birkaç satırlık formül.
 *
 * 🔴 Her karşılaştırma hash_equals ile (W2): `===` ilk farklı baytta durur ve
 * yanıt süresinden imza bayt bayt tahmin edilebilir.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/Shopier/ShopierSignature.md
 */
final class ShopierSignature
{
    private const ALGORITHM = 'sha256';

    /**
     * Ödeme formunun imzası: base64(HMAC-SHA256(random_nr . order_id . tutar . para_birimi)).
     *
     * Tutar ve para birimi imzanın İÇİNDE: tarayıcıda formu düzenleyip
     * `total_order_value=1.00` yapan kullanıcıyı Shopier reddeder.
     */
    public static function forPayment(
        string $randomNr,
        string $orderId,
        string $totalOrderValue,
        string $currency,
        string $secret,
    ): string {
        return base64_encode(hash_hmac(
            self::ALGORITHM,
            $randomNr.$orderId.$totalOrderValue.$currency,
            $secret,
            true,
        ));
    }

    /**
     * Tarayıcı callback'inin imzası: random_nr . platform_order_id.
     *
     * 🔴 `status` imzanın DIŞINDA (K117). Bu fonksiyonun `true` dönmesi
     * "bu sipariş kimliği Shopier'den geldi" demektir, "ödeme başarılı" değil.
     */
    public static function verifyCallback(string $randomNr, string $orderId, string $signature, string $secret): bool
    {
        // Katı kip: base64 alfabesi dışı bir karakter varsa false. Gevşek kip
        // onları sessizce atar ve "bozuk ama geçerli sayılan" bir imza üretir.
        $decoded = base64_decode($signature, true);

        if ($decoded === false || $decoded === '') {
            return false;
        }

        $expected = hash_hmac(self::ALGORITHM, $randomNr.$orderId, $secret, true);

        return hash_equals($expected, $decoded);
    }

    /**
     * Otomatik Sipariş Bildirimi (OSB): hex(HMAC-SHA256(res . kullanıcı_adı, anahtar)).
     *
     * `res` ham haliyle (base64 metni) imzalanır; çözülmüş JSON değil. Önce
     * imza, sonra çözme (L1): imzasız bir istek JSON ayrıştırması yaptıramaz.
     */
    public static function verifyOsb(string $res, string $hash, string $username, string $key): bool
    {
        if ($hash === '') {
            return false;
        }

        $expected = hash_hmac(self::ALGORITHM, $res.$username, $key);

        // Hex büyük/küçük harf farkı imzayı değiştirmez; karşılaştırma küçük harfle.
        return hash_equals($expected, strtolower($hash));
    }
}
