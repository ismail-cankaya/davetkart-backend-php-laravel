<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payment\FormPostAction;
use App\Services\Payment\RedirectAction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ödeme yönlendirme değer nesneleri (Faz 12, 12.1 · K118).
 *
 * Kurucular geçersiz durumu reddediyor mu? Değer nesnesinin bütün güvencesi
 * kurucusundadır; burada kırılırsa hiçbir okuyan onu yakalamaz.
 */
final class CheckoutActionTest extends TestCase
{
    #[Test]
    public function a_redirect_keeps_a_relative_url(): void
    {
        $this->assertSame('/odeme/basarili?order=1', (new RedirectAction('/odeme/basarili?order=1'))->url());
    }

    #[Test]
    public function a_redirect_refuses_a_blank_url(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RedirectAction('   ');
    }

    #[Test]
    public function a_form_post_keeps_its_url_and_string_fields(): void
    {
        $action = new FormPostAction('https://pay.example/form', ['a' => '1', 'b' => '']);

        $this->assertSame('https://pay.example/form', $action->url());
        $this->assertSame(['a' => '1', 'b' => ''], $action->fields());
    }

    /**
     * @param  array<mixed>  $fields
     */
    #[Test]
    #[DataProvider('invalidForms')]
    public function a_form_post_refuses_an_invalid_shape(string $url, array $fields): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FormPostAction($url, $fields);
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidForms(): iterable
    {
        yield 'göreli adres' => ['/odeme', ['a' => '1']];
        yield 'şemasız adres' => ['pay.example/form', ['a' => '1']];
        yield 'javascript şeması' => ['javascript:alert(1)', ['a' => '1']];
        yield 'alan yok' => ['https://pay.example/form', []];
        yield 'sayısal değer' => ['https://pay.example/form', ['total' => 399]];
        yield 'sayısal anahtar' => ['https://pay.example/form', ['1']];
        yield 'boş anahtar' => ['https://pay.example/form', ['' => '1']];
    }
}
