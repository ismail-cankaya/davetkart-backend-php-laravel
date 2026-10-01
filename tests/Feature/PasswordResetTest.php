<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Parola sifirlama (Faz 10, 10.32–10.36): baglanti iste + yeni parolayi kaydet.
 *
 * Iki tehdit, iki koruma:
 *   - Hesap tarama: kayitli/kayitsiz e-posta AYNI yanit (A2, 08 §3.1).
 *   - Calinmis oturum: sifirlama TUM token'lari iptal eder.
 *
 * Mail bir yerde Notification::fake() ile degil GERCEKTEN gonderilir
 * (phpunit: MAIL_MAILER=array, QUEUE_CONNECTION=sync): Turkce dil, frontend
 * baglantisi ve Host basligina karsi koruma ancak olusan HTML'de kanitlanir.
 * Ayrintili aciklama: docs/rehber/tests/Feature/PasswordResetTest.md
 */
final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ayse@ornek.test';

    private const NEW_PASSWORD = 'yepyeni-parola-2026';

    // --------------------------------------------------- BAGLANTI ISTEME

    /** 🔴 A2: hesap var mi yok mu, yanittan OKUNAMAZ — ham govde birebir ayni. */
    #[Test]
    public function a_registered_and_an_unknown_email_get_the_same_answer(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => self::EMAIL]);

        $known = $this->forgot(self::EMAIL)->assertStatus(Response::HTTP_ACCEPTED);
        $unknown = $this->forgot('hicyok@ornek.test')->assertStatus(Response::HTTP_ACCEPTED);

        $this->assertSame('', $known->getContent());
        $this->assertSame($known->getContent(), $unknown->getContent());

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    /** Kayit ve girisle AYNI normalizasyon (10.13): `İ` ile istenen sifirlama duz `i`'li hesaba gider. */
    #[Test]
    public function the_email_is_normalized_like_at_login(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ismail.cankaya@gmail.com']);

        $this->forgot("\u{0130}smail.Cankaya@gmail.com")->assertStatus(Response::HTTP_ACCEPTED);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * Uctan uca: mail gercekten olusuyor. Turkce (K96), frontend'e giden mutlak
     * baglanti (FRONTEND_URL), Laravel'in Ingilizce metinlerinden iz yok.
     */
    #[Test]
    public function the_reset_mail_is_turkish_and_links_to_the_frontend(): void
    {
        Config::set('davetkart.frontend.url', 'https://davetkart.test');
        User::factory()->create(['email' => self::EMAIL]);

        $this->forgot(self::EMAIL)->assertStatus(Response::HTTP_ACCEPTED);

        $mail = $this->onlySentMail();
        $html = (string) $mail->getHtmlBody();

        $this->assertSame('DavetKart parola sıfırlama', $mail->getSubject());
        $this->assertStringContainsString('Parolamı Sıfırla', $html);
        $this->assertStringContainsString('düğmesi çalışmazsa', $html); // lang/tr.json + bildirim dili
        $this->assertStringNotContainsString("If you're having trouble", $html);
        $this->assertStringContainsString('https://davetkart.test/sifre-sifirla?token=', $html);
        $this->assertStringContainsString('email=ayse%40ornek.test', $html);
    }

    /**
     * 🔴 Password reset poisoning: saldirgan istegi kendi Host basligiyla
     * gonderirse, kurbanin mailindeki baglanti SALDIRGANIN sitesine gitmemeli.
     */
    #[Test]
    public function the_link_ignores_the_request_host_header(): void
    {
        Config::set('davetkart.frontend.url', 'https://davetkart.test');
        User::factory()->create(['email' => self::EMAIL]);

        $this->withServerVariables(['HTTP_HOST' => 'saldirgan.example'])
            ->forgot(self::EMAIL)
            ->assertStatus(Response::HTTP_ACCEPTED);

        $html = (string) $this->onlySentMail()->getHtmlBody();

        $this->assertStringNotContainsString('saldirgan.example', $html);
        $this->assertStringContainsString('https://davetkart.test/sifre-sifirla?token=', $html);
    }

    /** 15 sn kurali ve zaman farki: mail istegi BEKLETMEZ, kuyruga gider. */
    #[Test]
    public function the_reset_mail_goes_through_the_queue(): void
    {
        $notification = new ResetPasswordNotification('abc');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('tr', $notification->locale);
    }

    /** K36: ayni e-posta + IP icin dakikada 5 istek (mail bombalama, hesap tarama). */
    #[Test]
    public function reset_link_requests_are_rate_limited(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $ignored) {
            $this->forgot(self::EMAIL)->assertStatus(Response::HTTP_ACCEPTED);
        }

        $this->forgot(self::EMAIL)
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);
    }

    // ---------------------------------------------------------- SIFIRLAMA

    /**
     * 🔴 Basari: yeni parola gecerli, eski parola GECERSIZ, eski oturumlarin
     * HEPSI kapali (calinmis bir token sifirlamadan sonra calismamali).
     */
    #[Test]
    public function a_valid_link_sets_the_new_password_and_ends_every_session(): void
    {
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create(['email' => self::EMAIL]);
        $phone = $user->createToken('api')->plainTextToken;
        $laptop = $user->createToken('api')->plainTextToken;

        $this->reset(self::EMAIL, Password::broker()->createToken($user), self::NEW_PASSWORD)
            ->assertNoContent();

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse(Hash::check(UserFactory::PASSWORD, $user->password));
        $this->assertSame(0, $user->tokens()->count());
        Event::assertDispatched(PasswordReset::class);

        foreach ([$phone, $laptop] as $token) {
            $this->forgetAuthState();
            $this->withToken($token)->getJson(route('auth.me'))->assertUnauthorized();
        }

        $this->postJson(route('auth.login'), ['email' => self::EMAIL, 'password' => self::NEW_PASSWORD])
            ->assertOk();
    }

    /** Baglanti TEK kullanimlik: ikinci deneme reddedilir, parola ikinci kez degismez. */
    #[Test]
    public function a_reset_link_works_only_once(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL]);
        $token = Password::broker()->createToken($user);

        $this->reset(self::EMAIL, $token, self::NEW_PASSWORD)->assertNoContent();
        $this->assertResetRejected($this->reset(self::EMAIL, $token, 'baska-bir-parola'));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
    }

    /** 60 dakika (auth.passwords.users.expire): suresi dolan baglanti reddedilir. */
    #[Test]
    public function an_expired_link_is_rejected(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL]);
        $token = Password::broker()->createToken($user);

        $this->travel(61)->minutes();

        $this->assertResetRejected($this->reset(self::EMAIL, $token, self::NEW_PASSWORD));
        $this->assertTrue(Hash::check(UserFactory::PASSWORD, $user->refresh()->password));
    }

    /** Mehmet'in baglantisi Ayse'nin parolasini degistiremez. */
    #[Test]
    public function a_token_issued_for_another_account_is_rejected(): void
    {
        $ayse = User::factory()->create(['email' => self::EMAIL]);
        $mehmet = User::factory()->create(['email' => 'mehmet@ornek.test']);

        $this->assertResetRejected(
            $this->reset(self::EMAIL, Password::broker()->createToken($mehmet), self::NEW_PASSWORD),
        );

        $this->assertTrue(Hash::check(UserFactory::PASSWORD, $ayse->refresh()->password));
    }

    /**
     * 🔴 H6 / T11: yanlis token ile kayitsiz e-posta AYNI yaniti alir — ham
     * govde birebir esit, `fields` yok. Ayrilsaydi form bir hesap tarayicisi olurdu.
     */
    #[Test]
    public function a_wrong_token_and_an_unknown_email_are_indistinguishable(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $wrongToken = $this->reset(self::EMAIL, str_repeat('a', 64), self::NEW_PASSWORD);
        $unknownEmail = $this->reset('hicyok@ornek.test', str_repeat('a', 64), self::NEW_PASSWORD);

        $this->assertResetRejected($wrongToken);
        $this->assertResetRejected($unknownEmail);
        $this->assertSame($wrongToken->getContent(), $unknownEmail->getContent());
    }

    /**
     * T6'nin varlik yarisi: BICIM hatasi alan alan konusur (kayitla ayni
     * min:8 kurali). Sifirlama reddi konusmaz (yukarida).
     */
    #[Test]
    public function a_too_short_password_is_a_field_error(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL]);

        $this->reset(self::EMAIL, Password::broker()->createToken($user), 'kisa')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value)
            ->assertJsonPath('error.fields.password.0.rule', 'min');
    }

    // --------------------------------------------------------- YARDIMCILAR

    /** @return TestResponse<Response> */
    private function forgot(string $email): TestResponse
    {
        return $this->postJson(route('auth.password.forgot'), ['email' => $email]);
    }

    /** @return TestResponse<Response> */
    private function reset(string $email, string $token, string $password): TestResponse
    {
        return $this->postJson(route('auth.password.reset'), [
            'email' => $email,
            'token' => $token,
            'password' => $password,
        ]);
    }

    /** @param  TestResponse<Response>  $response */
    private function assertResetRejected(TestResponse $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::PasswordResetInvalid->value)
            ->assertJsonMissingPath('error.fields');
    }

    /** `array` mail surucusunun tuttugu TEK mesaj. */
    private function onlySentMail(): Email
    {
        /** @var \Illuminate\Mail\Transport\ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();
        $messages = $transport->messages();

        $this->assertCount(1, $messages);

        $sent = $messages->first();
        $this->assertInstanceOf(SentMessage::class, $sent);

        $email = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }
}
