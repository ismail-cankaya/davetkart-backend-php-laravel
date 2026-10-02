<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Iban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Faz 10 (10.62 · K105): IBAN biçim + mod-97.
 * Ayrıntılı açıklama: docs/rehber/app/Support/Iban.md
 */
final class IbanTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function valid(): array
    {
        return [
            'TR, bitişik' => ['TR330006100519786457841326'],
            'TR, dörtlü gruplar' => ['TR33 0006 1005 1978 6457 8413 26'],
            'TR, küçük harf' => ['tr330006100519786457841326'],
            'Almanya' => ['DE89370400440532013000'],
            'Birleşik Krallık (harfli banka kodu)' => ['GB82WEST12345698765432'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        return [
            'kontrol hanesi yanlış' => ['TR340006100519786457841326'],
            'tek hane yanlış yazılmış' => ['TR330006100519786457841327'],
            'yan yana iki hane yer değiştirmiş' => ['TR330006100519786457841362'],
            // Sağlama toplamı DOĞRU (mod-97 = 1), yalnızca uzunluk yanlış:
            // uzunluk kontrolü silinirse yalnızca bu vaka kırılır.
            'TR ama 25 karakter (sağlama toplamı doğru)' => ['TR23000610051978645784132'],
            'ülke kodu yok' => ['330006100519786457841326'],
            // Sağlama toplamı DOĞRU ama hesap kısmı 5 hane (en az 11 olmalı):
            // biçim denetimi silinirse yalnızca bu vaka kırılır.
            'çok kısa (sağlama toplamı doğru)' => ['DE1312345'],
            'yarım yazılmış' => ['TR33 0006 1005'],
            'boş' => [''],
            'rastgele metin' => ['IBAN-YOK'],
        ];
    }

    #[Test]
    #[DataProvider('valid')]
    public function it_accepts_a_valid_iban(string $iban): void
    {
        $this->assertTrue(Iban::isValid($iban));
    }

    #[Test]
    #[DataProvider('invalid')]
    public function it_rejects_an_invalid_iban(string $iban): void
    {
        $this->assertFalse(Iban::isValid($iban));
    }

    #[Test]
    public function normalization_removes_spaces_and_uppercases(): void
    {
        $this->assertSame('TR330006100519786457841326', Iban::normalize(" tr33 0006\t1005 1978 6457 8413 26 "));
    }
}
