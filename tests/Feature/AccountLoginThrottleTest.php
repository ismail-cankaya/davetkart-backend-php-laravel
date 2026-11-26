<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Exceptions\AccountLoginThrottledException;
use App\Models\User;
use App\Notifications\SuspiciousLoginAttemptsNotification;
use App\Support\AccountLoginThrottle;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Faz 21.1 (SEC-16, K265): hesap başına, IP'den bağımsız başarısız giriş kovası (saatte 30).
 * Her deneme ayrı bir IP'den gelir: IP kovaları (5/dk, 20/dk) hiç dolmaz, 429 yalnızca hesap kovasından gelebilir.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/AccountLoginThrottleTest.md
 */
final class AccountLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    /** Sayı bilerek sabit yazıldı: değişirse bu test kırılmalı (RateLimitTest deseni). */
    private const LIMIT = 30;

    private const EMAIL = 'hedef@ornek.test';

    private int $ip = 0;

    #[Test]
    public function login_attempts_from_many_ips_share_an_account_bucket(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->failTimes(self::EMAIL, self::LIMIT);

        $this->login(self::EMAIL, 'yanlis-parola')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value)
            ->assertHeader('Retry-After');

        $retryAfter = $this->login(self::EMAIL, 'yanlis-parola')->json('error.params.retryAfter');
        $this->assertIsInt($retryAfter);
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(3600, $retryAfter);
    }

    /** Dolu kovada doğru parola da bekler: yoksa saldırgan doğru tahmini 200'den anlardı. */
    #[Test]
    public function a_full_account_bucket_refuses_even_the_right_password(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->failTimes(self::EMAIL, self::LIMIT);

        $this->login(self::EMAIL, UserFactory::PASSWORD)
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);
    }

    #[Test]
    public function the_bucket_belongs_to_one_account_only(): void
    {
        User::factory()->create(['email' => self::EMAIL]);
        User::factory()->create(['email' => 'komsu@ornek.test']);

        $this->failTimes(self::EMAIL, self::LIMIT);

        $this->login('komsu@ornek.test', 'yanlis-parola')->assertUnauthorized();
        $this->login('komsu@ornek.test', UserFactory::PASSWORD)->assertOk();
    }

    /** §A-4: doğru parolayla giren sahip kendi kovasını doldurmaz. */
    #[Test]
    public function successful_logins_are_not_counted(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        foreach (range(1, self::LIMIT) as $ignored) {
            $this->login(self::EMAIL, UserFactory::PASSWORD)->assertOk();
        }

        // Başarılı girişler sayılsaydı bu, otuz birinci deneme olurdu: 429.
        $this->login(self::EMAIL, 'yanlis-parola')->assertUnauthorized();
    }

    /** Kayıtsız adres de aynı kovayı doldurur: 429'un varlığı adresin kayıtlı olduğunu söylemez. */
    #[Test]
    public function an_unknown_address_fills_its_bucket_the_same_way(): void
    {
        $this->failTimes('yok@ornek.test', self::LIMIT);

        $this->login('yok@ornek.test', 'yanlis-parola')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);
    }

    #[Test]
    public function both_forms_of_the_turkish_capital_i_fill_one_bucket(): void
    {
        $this->failTimes('ismail@ornek.test', self::LIMIT);

        $this->login("\u{0130}smail@ornek.test", 'yanlis-parola')->assertStatus(429);
    }

    /** Sınıfın kendi sözü: çağıran normalize etmeyi unutsa da (ör. sıfırlama yolu) iki biçim tek kova. */
    #[Test]
    public function the_throttle_normalizes_the_address_itself(): void
    {
        $throttle = new AccountLoginThrottle;

        foreach (range(1, self::LIMIT) as $ignored) {
            $throttle->recordFailure("\u{0130}smail@Ornek.test");
        }

        $this->expectException(AccountLoginThrottledException::class);
        $throttle->ensureOpen('ismail@ornek.test');
    }

    #[Test]
    public function the_bucket_empties_after_an_hour(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->failTimes(self::EMAIL, self::LIMIT);
        $this->login(self::EMAIL, UserFactory::PASSWORD)->assertStatus(429);

        $this->travel(61)->minutes();

        $this->login(self::EMAIL, UserFactory::PASSWORD)->assertOk();
    }

    /** "Hesap kilitlenmez": sahibin her zaman bir yolu var; parolayı sıfırlamak kovayı boşaltır. */
    #[Test]
    public function a_password_reset_empties_the_bucket(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL]);

        $this->failTimes(self::EMAIL, self::LIMIT);
        $this->login(self::EMAIL, UserFactory::PASSWORD)->assertStatus(429);

        $token = Password::broker()->createToken($user);
        $this->postJson(route('auth.password.reset'), [
            'email' => self::EMAIL,
            'token' => $token,
            'password' => 'yeni-ve-uzun-bir-parola-42',
        ])->assertSuccessful();

        $this->login(self::EMAIL, 'yeni-ve-uzun-bir-parola-42')->assertOk();
    }

    /** §A-3: kova ilk dolduğunda sahibine tek posta; dolmadan önce ve dolduktan sonraki 429'larda yok. */
    #[Test]
    public function the_owner_is_warned_once_when_the_bucket_fills(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => self::EMAIL]);

        $this->failTimes(self::EMAIL, self::LIMIT - 1);
        Notification::assertNothingSent();

        $this->login(self::EMAIL, 'yanlis-parola')->assertUnauthorized();
        Notification::assertSentToTimes($user, SuspiciousLoginAttemptsNotification::class, 1);

        $this->login(self::EMAIL, 'yanlis-parola')->assertStatus(429);
        $this->login(self::EMAIL, UserFactory::PASSWORD)->assertStatus(429);
        Notification::assertSentToTimes($user, SuspiciousLoginAttemptsNotification::class, 1);
    }

    /** Saatte en çok bir posta: kova aynı saat içinde yeniden dolsa da ikinci posta gitmez. */
    #[Test]
    public function at_most_one_warning_per_hour(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => self::EMAIL]);

        $this->failTimes(self::EMAIL, self::LIMIT);
        $this->resetPassword($user, 'birinci-yeni-parola-42');
        $this->failTimes(self::EMAIL, self::LIMIT);

        Notification::assertSentToTimes($user, SuspiciousLoginAttemptsNotification::class, 1);

        $this->travel(61)->minutes();
        $this->failTimes(self::EMAIL, self::LIMIT);

        Notification::assertSentToTimes($user, SuspiciousLoginAttemptsNotification::class, 2);
    }

    #[Test]
    public function the_warning_is_queued_turkish_and_carries_no_ip(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL]);
        $notification = new SuspiciousLoginAttemptsNotification;

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('tr', $notification->locale);

        $mail = $notification->toMail($user);
        $text = implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines, (string) $mail->actionUrl]);

        $this->assertStringContainsString('/sifremi-unuttum', (string) $mail->actionUrl);
        $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}(\.\d{1,3}){3}\b/', $text);
        $this->assertStringNotContainsString('IP', $text);
    }

    private function failTimes(string $email, int $times): void
    {
        foreach (range(1, $times) as $ignored) {
            $this->login($email, 'yanlis-parola')->assertUnauthorized();
        }
    }

    private function resetPassword(User $user, string $password): void
    {
        $this->postJson(route('auth.password.reset'), [
            'email' => $user->email,
            'token' => Password::broker()->createToken($user),
            'password' => $password,
        ])->assertSuccessful();
    }

    /**
     * Her istek yeni bir IP'den: 10.21.x.y.
     *
     * @return TestResponse<Response>
     */
    private function login(string $email, string $password): TestResponse
    {
        $this->ip++;
        $address = sprintf('10.21.%d.%d', intdiv($this->ip, 250), $this->ip % 250 + 1);

        return $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->postJson(route('auth.login'), ['email' => $email, 'password' => $password]);
    }
}
