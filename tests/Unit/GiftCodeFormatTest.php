<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\GiftCodes\GiftCodeFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Faz 11: hediye kodunun biçimi.
 * Ayrıntılı açıklama: docs/rehber/app/Support/GiftCodes/GiftCodeFormat.md
 */
final class GiftCodeFormatTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function spellings(): array
    {
        return [
            'küçük harf' => ['dugun2026', 'DUGUN2026'],
            'tireli üretilmiş kod' => ['DK-7KQ2-M9XA-P4TR', 'DK7KQ2M9XAP4TR'],
            'boşluk, sekme ve küçük harf' => ["  dk 7kq2\tm9xa-p4tr ", 'DK7KQ2M9XAP4TR'],
            'ardışık tireler' => ['DK--7KQ2', 'DK7KQ2'],
        ];
    }

    #[Test]
    #[DataProvider('spellings')]
    public function every_spelling_normalizes_to_one_form(string $input, string $expected): void
    {
        $this->assertSame($expected, GiftCodeFormat::normalize($input));
    }

    #[Test]
    public function the_hash_ignores_spelling_but_not_content(): void
    {
        $this->assertSame(
            GiftCodeFormat::hash('DK-7KQ2-M9XA-P4TR'),
            GiftCodeFormat::hash('dk7kq2 m9xa p4tr'),
        );
        $this->assertNotSame(GiftCodeFormat::hash('DUGUN2026'), GiftCodeFormat::hash('DUGUN2027'));
        $this->assertSame(hash('sha256', 'DUGUN2026'), GiftCodeFormat::hash('dugun-2026'));
        $this->assertSame(64, strlen(GiftCodeFormat::hash('x')));
    }

    #[Test]
    public function the_hint_is_the_last_four_normalized_characters(): void
    {
        $this->assertSame('P4TR', GiftCodeFormat::hint('dk-7kq2-m9xa-p4tr'));
        $this->assertSame('2026', GiftCodeFormat::hint('DUGUN2026'));
    }

    #[Test]
    public function generated_codes_have_the_display_shape_and_a_safe_alphabet(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = GiftCodeFormat::generate();

            $this->assertMatchesRegularExpression(
                '/^DK-[2-9A-HJKMNP-TV-Z]{4}-[2-9A-HJKMNP-TV-Z]{4}-[2-9A-HJKMNP-TV-Z]{4}$/',
                $code,
            );
            // Karışan karakterler hiç yok.
            $this->assertDoesNotMatchRegularExpression('/[01OILU]/', substr($code, 3));
        }
    }

    #[Test]
    public function generated_codes_do_not_repeat(): void
    {
        $codes = [];

        for ($i = 0; $i < 1000; $i++) {
            $codes[] = GiftCodeFormat::generate();
        }

        $this->assertCount(1000, array_unique($codes));
    }

    #[Test]
    public function the_alphabet_has_thirty_distinct_symbols(): void
    {
        $this->assertSame(30, strlen(GiftCodeFormat::ALPHABET));
        $this->assertCount(30, array_unique(str_split(GiftCodeFormat::ALPHABET)));
    }

    /** @return array<string, array{string, bool}> */
    public static function customCodes(): array
    {
        return [
            'düz kod' => ['DUGUN2026', true],
            'küçük harf ve tire normalize edilir' => ['dugun-2026', true],
            'tam 4 karakter' => ['ABCD', true],
            'tam 32 karakter' => [str_repeat('A', 32), true],
            '3 karakter kısa' => ['ABC', false],
            '33 karakter uzun' => [str_repeat('A', 33), false],
            'Türkçe harf' => ['DÜĞÜN2026', false],
            'noktalama' => ['DUGUN_2026', false],
            'boş' => ['   ', false],
        ];
    }

    #[Test]
    #[DataProvider('customCodes')]
    public function custom_codes_are_validated_after_normalization(string $code, bool $valid): void
    {
        $this->assertSame($valid, GiftCodeFormat::isValidCustom($code));
    }
}
