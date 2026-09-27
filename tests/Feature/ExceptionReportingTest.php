<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\SubscriptionTier;
use App\Exceptions\AiProviderException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\PaymentProviderException;
use App\Exceptions\PaywallViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Raporlama politikasi (Faz 10, 10.16): hangi istisna Sentry'ye ve
 * laravel.log'a gider, hangisi gitmez.
 *
 * 🔴 `Exceptions::fake()` gercek isleyicinin shouldReport()'unu SORAR
 * (ExceptionHandlerFake::shouldReport -> $this->handler->shouldReport).
 * Yani bootstrap/app.php'deki `dontReportWhen` kurali burada gercekten
 * calisir; fake onu atlamaz.
 *
 * T6: politika IKI yonlu sinanir. Yalnizca "4xx raporlanmaz" yazsaydik,
 * butun raporlamayi kapatan bir kural (fn () => true) da yesil kalirdi —
 * ve gercek 500'ler sessizce kaybolurdu. Bu, kuralin varlik sebebinin tam
 * tersi.
 * Ayrintili aciklama: docs/rehber/tests/Feature/ExceptionReportingTest.md
 */
final class ExceptionReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Exceptions::fake();
    }

    /** Yokluk: sozlesmenin ongordugu bir cevap, hata degil. */
    #[Test]
    public function a_client_side_business_exception_is_not_reported(): void
    {
        report(new InvalidCredentialsException);
        report(PaywallViolationException::noPurchase(SubscriptionTier::Gold));

        Exceptions::assertNothingReported();
    }

    /**
     * Varlik: 5xx is istisnalari raporlanmaya DEVAM eder. 502, elimizdeki
     * en kucuk 5xx kodu: `< 500` siniri `< 503`'e kaysa bu test kirilir.
     */
    #[Test]
    public function a_provider_failure_is_still_reported(): void
    {
        report(PaymentProviderException::rejected());      // 502
        report(AiProviderException::unavailable('gemini')); // 503

        Exceptions::assertReported(PaymentProviderException::class);
        Exceptions::assertReported(AiProviderException::class);
    }

    /** Varlik: kural YALNIZCA HasErrorCode'a bakar; geri kalan her sey raporlanir. */
    #[Test]
    public function an_exception_without_an_error_code_is_still_reported(): void
    {
        report(new RuntimeException('beklenmeyen'));

        Exceptions::assertReported(RuntimeException::class);
    }

    /**
     * Uctan uca: gercek bir istek, gercek bir 401. Istisna FIRLATILDI
     * (yanit kodu bunu kanitliyor) ama raporlanmadi.
     */
    #[Test]
    public function a_wrong_password_answers_401_and_reports_nothing(): void
    {
        $this->postJson(route('auth.login'), [
            'email' => 'hicyok@ornek.test',
            'password' => 'yanlis-parola',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::InvalidCredentials->value);

        Exceptions::assertNotReported(InvalidCredentialsException::class);
    }
}
