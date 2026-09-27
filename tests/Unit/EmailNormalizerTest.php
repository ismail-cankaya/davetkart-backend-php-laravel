<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EmailNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * E-postanin kanonik bicimi (Faz 10, 10.12 · D-5 · denetim K-3).
 *
 * Saf fonksiyon: Laravel ayaga kalkmaz, veritabanina dokunulmaz.
 * Uctan uca kanit (kayit -> giris) AuthTest'te (10.15).
 *
 * 🔴 Beklenen degerler `bin2hex` ile de karsilastiriliyor: `i` ile `i̇`
 * ekranda AYNI gorunur. Hata mesajinda yalnizca metin olsaydi kirmizi bir
 * test "ismail@… beklendi, ismail@… geldi" derdi.
 * Ayrintili aciklama: docs/rehber/tests/Unit/EmailNormalizerTest.md
 */
final class EmailNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function turkishCapitalI(): array
    {
        return [
            'İ, tek kod noktasi (U+0130, Turkce klavye)' => ["\u{0130}smail@Gmail.com", 'ismail@gmail.com'],
            'I + birlesen nokta (U+0049 U+0307, NFD)' => ["I\u{0307}smail@gmail.com", 'ismail@gmail.com'],
            'i + birlesen nokta (Faz 9 oncesi DB satiri)' => ["i\u{0307}smail@gmail.com", 'ismail@gmail.com'],
            'yerel kisimda ve alan adinda birden cok İ' => ["\u{0130}P\u{0130}N@\u{0130}ZM\u{0130}R.test", 'ipin@izmir.test'],
        ];
    }

    #[Test]
    #[DataProvider('turkishCapitalI')]
    public function it_folds_every_form_of_the_turkish_capital_i_into_a_plain_i(string $input, string $expected): void
    {
        $actual = EmailNormalizer::normalize($input);

        $this->assertSame(bin2hex($expected), bin2hex($actual));
        $this->assertSame($expected, $actual);
    }

    /** Faz 2'den beri yapilan isler hala yapiliyor: kirpma ve ASCII kucultme. */
    #[Test]
    public function it_trims_and_lowercases_plain_ascii(): void
    {
        $this->assertSame('ayse@ornek.test', EmailNormalizer::normalize("  AYSE@Ornek.TEST \n"));
    }

    /**
     * T6'nin "yokluk" yarisi: noktasiz `ı` ayri bir HARFTIR, `i`'ye
     * donusturulmez. Donusturulseydi `ısmail@` ile `ismail@` — iki farkli
     * adres — ayni hesaba duserdi.
     */
    #[Test]
    public function it_leaves_the_dotless_i_alone(): void
    {
        $this->assertSame("\u{0131}smail@gmail.com", EmailNormalizer::normalize("\u{0131}smail@gmail.com"));
    }

    /** Diger Turkce harfler zaten tek kod noktasina iner; kural yalnizca İ'ye dokunur. */
    #[Test]
    public function it_lowercases_other_turkish_letters_to_single_code_points(): void
    {
        $this->assertSame('şğüöç@ornek.test', EmailNormalizer::normalize('ŞĞÜÖÇ@Ornek.test'));
    }

    /** Kanonik bicim sabit bir noktadir: iki kez uygulamak bir sey degistirmez. */
    #[Test]
    public function it_is_idempotent(): void
    {
        $once = EmailNormalizer::normalize("\u{0130}smail@Gmail.com");

        $this->assertSame($once, EmailNormalizer::normalize($once));
    }
}
