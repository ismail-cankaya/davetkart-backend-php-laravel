<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payment\Shopier\ShopierAmount;
use App\Services\Payment\Shopier\ShopierSignature;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Shopier imza formülleri ve tutar biçimi (Faz 12, 12.5).
 *
 * 🔴 SABİT VEKTÖRLER: beklenen imzalar PHP ile değil, ayrı bir araçla (Python
 * `hmac` modülü) hesaplanıp buraya yazıldı. Beklenen değeri test edilen
 * fonksiyonla üretmek, fonksiyonun kendisiyle karşılaştırmaktır: formül yanlışsa
 * iki taraf AYNI şekilde yanlış olur ve test yeşil kalır.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/Shopier/ShopierSignature.md §5
 */
final class ShopierSignatureTest extends TestCase
{
    private const SECRET = 'test-secret';

    private const ORDER = '01JB2Z8Q4M7X3K9V5N6P0R1T2W';

    private const RANDOM = '482913';

    // ----------------------------------------------------------- ödeme formu

    #[Test]
    public function the_payment_signature_matches_the_fixed_vector(): void
    {
        $this->assertSame(
            'knN7UNt6zPH8MgWa6X41OMaBK6Vw64OuFZNcQ1nL55k=',
            ShopierSignature::forPayment(self::RANDOM, self::ORDER, '399.00', '0', self::SECRET),
        );
    }

    /** Tutar imzanın içinde: indirimli tutar başka bir imza üretir. */
    #[Test]
    public function the_amount_is_part_of_the_payment_signature(): void
    {
        $this->assertSame(
            'pRO1jCrGFERljmhgkoAMUwPtAzt8ARjQ7816fO3GoTs=',
            ShopierSignature::forPayment(self::RANDOM, self::ORDER, '199.50', '0', self::SECRET),
        );
    }

    // -------------------------------------------------------------- callback

    #[Test]
    public function a_correct_callback_signature_is_accepted(): void
    {
        $this->assertTrue(ShopierSignature::verifyCallback(
            self::RANDOM,
            self::ORDER,
            '7QBWgAzXJ9Ric3YrzjJ9luYEB5f1FEzJfFQ/BtSuT3o=',
            self::SECRET,
        ));
    }

    #[Test]
    #[DataProvider('forgedCallbacks')]
    public function a_forged_callback_is_refused(string $random, string $order, string $signature, string $secret): void
    {
        $this->assertFalse(ShopierSignature::verifyCallback($random, $order, $signature, $secret));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function forgedCallbacks(): iterable
    {
        $good = '7QBWgAzXJ9Ric3YrzjJ9luYEB5f1FEzJfFQ/BtSuT3o=';

        yield 'başka sipariş' => [self::RANDOM, '01JB2Z8Q4M7X3K9V5N6P0R1T2X', $good, self::SECRET];
        yield 'başka random_nr' => ['482914', self::ORDER, $good, self::SECRET];
        yield 'başka sır' => [self::RANDOM, self::ORDER, $good, 'other-secret'];
        yield 'boş imza' => [self::RANDOM, self::ORDER, '', self::SECRET];
        yield 'base64 olmayan' => [self::RANDOM, self::ORDER, '***not-base64***', self::SECRET];
        yield 'hex imza' => [self::RANDOM, self::ORDER, hash_hmac('sha256', self::RANDOM.self::ORDER, self::SECRET), self::SECRET];
    }

    // ------------------------------------------------------------------- OSB

    #[Test]
    public function a_correct_osb_hash_is_accepted_in_either_case(): void
    {
        $res = 'eyJvcmRlcmlkIjoiMTAwMSIsInByaWNlIjoiMzk5LjAwIn0=';
        $hash = 'e93e7d470ea2095acbb0aa911ece90676977c1186f83c7892f80af7c22c75bed';

        $this->assertTrue(ShopierSignature::verifyOsb($res, $hash, 'osb-user', 'osb-key'));
        $this->assertTrue(ShopierSignature::verifyOsb($res, strtoupper($hash), 'osb-user', 'osb-key'));
    }

    #[Test]
    public function a_tampered_osb_body_is_refused(): void
    {
        // price 399.00 -> 1.00
        $res = base64_encode('{"orderid":"1001","price":"1.00"}');
        $hash = 'e93e7d470ea2095acbb0aa911ece90676977c1186f83c7892f80af7c22c75bed';

        $this->assertFalse(ShopierSignature::verifyOsb($res, $hash, 'osb-user', 'osb-key'));
        $this->assertFalse(ShopierSignature::verifyOsb('eyJvcmRlcmlkIjoiMTAwMSIsInByaWNlIjoiMzk5LjAwIn0=', '', 'osb-user', 'osb-key'));
    }

    // ------------------------------------------------------------ tutar (E8)

    #[Test]
    #[DataProvider('amounts')]
    public function minor_units_are_formatted_without_floats(int $minor, string $text): void
    {
        $this->assertSame($text, ShopierAmount::format($minor));
    }

    /** @return iterable<string, array{int, string}> */
    public static function amounts(): iterable
    {
        yield 'standart' => [24900, '249.00'];
        yield 'yarım indirim' => [19950, '199.50'];
        yield 'tek kuruş' => [5, '0.05'];
        yield 'on kuruş' => [10, '0.10'];
        yield 'sıfır' => [0, '0.00'];
        yield 'büyük' => [123456789, '1234567.89'];
    }

    #[Test]
    public function a_negative_amount_cannot_be_formatted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ShopierAmount::format(-1);
    }

    #[Test]
    #[DataProvider('parsedPrices')]
    public function prices_are_parsed_strictly(string $text, ?int $minor): void
    {
        $this->assertSame($minor, ShopierAmount::parse($text));
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function parsedPrices(): iterable
    {
        yield 'nokta' => ['399.00', 39900];
        yield 'virgül' => ['399,00', 39900];
        yield 'tek ondalık' => ['199.5', 19950];
        yield 'tamsayı' => ['399', 39900];
        yield 'boşluklu' => [' 249.00 ', 24900];
        yield 'binlik ayraçlı' => ['1.399,00', null];
        yield 'üç ondalık' => ['399.001', null];
        yield 'eksi' => ['-399.00', null];
        yield 'bilimsel' => ['3.99e2', null];
        yield 'boş' => ['', null];
        yield 'para birimli' => ['399.00 TL', null];
    }
}
