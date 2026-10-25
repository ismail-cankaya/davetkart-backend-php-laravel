<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Models\User;
use App\Notifications\EmailChangeConfirmationNotification;
use App\Notifications\EmailChangedNotification;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * E-posta değişikliği: istek + imzalı onay (Faz 14, 14.10 · K144).
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/EmailChangeTest.md
 */
final class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    #[Test]
    public function a_guest_cannot_request_a_change(): void
    {
        $this->postJson(route('auth.email-change.store'), ['email' => 'a@example.com', 'currentPassword' => 'x'])
            ->assertUnauthorized();
    }

    #[Test]
    public function the_request_mails_the_new_address_and_changes_nothing_yet(): void
    {
        $user = User::factory()->create(['email' => 'eski@example.com']);

        $this->requestChange($user, 'Yeni@Example.com')->assertStatus(202);

        $this->assertSame('eski@example.com', $user->refresh()->email);

        $url = $this->linkSentTo('yeni@example.com');
        $this->assertStringStartsWith(Config::string('davetkart.frontend.url').'/eposta-onay?', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('eski@example.com', $url);
    }

    #[Test]
    public function a_wrong_password_sends_nothing(): void
    {
        $user = User::factory()->create();

        $this->requestChange($user, 'yeni@example.com', 'yanlis-parola')
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.currentPassword.0.rule', 'current_password');

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_current_address_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'ayni@example.com']);

        $this->requestChange($user, 'AYNI@example.com')
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.email.0.rule', 'not_in');
    }

    #[Test]
    public function confirming_changes_the_address_and_closes_every_session(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'eski@example.com']);
        $token = $user->createToken('t')->plainTextToken;
        Password::broker()->createToken($user);

        $this->requestChange($user, 'yeni+davet@example.com')->assertStatus(202);
        $this->confirm($this->linkSentTo('yeni+davet@example.com'))->assertNoContent();

        $user->refresh();
        $this->assertSame('yeni+davet@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        $this->forgetAuthState();
        $this->withToken($token)->getJson(route('auth.me'))->assertUnauthorized();

        Notification::assertSentOnDemand(
            EmailChangedNotification::class,
            static fn (EmailChangedNotification $n, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'eski@example.com'
                && $n->newEmail === 'yeni+davet@example.com',
        );

        // Yeni adresle giriş açılıyor.
        $this->postJson(route('auth.login'), ['email' => 'yeni+davet@example.com', 'password' => UserFactory::PASSWORD])->assertOk();
    }

    /** Tek kullanımlık: adres bir kez değişince aynı bağlantı ölür. */
    #[Test]
    public function a_link_cannot_be_used_twice(): void
    {
        $user = User::factory()->create();
        $this->requestChange($user, 'yeni@example.com');
        $link = $this->linkSentTo('yeni@example.com');

        $this->confirm($link)->assertNoContent();
        $this->assertInvalid($this->confirm($link));
    }

    /**
     * 🔴 BU ADIMIN EN ÖNEMLİ TESTİ (H6).
     *
     * Adres başka hesapta kayıtlıysa istek YİNE 202 ve mail YİNE gider: istek
     * ucu bir hesap tarayıcısına dönüşmez. Çakışma onayda, aynı genel hatayla.
     */
    #[Test]
    public function a_taken_address_is_never_revealed(): void
    {
        User::factory()->create(['email' => 'dolu@example.com']);
        $user = User::factory()->create(['email' => 'benim@example.com']);

        $this->requestChange($user, 'dolu@example.com')->assertStatus(202);
        $this->assertInvalid($this->confirm($this->linkSentTo('dolu@example.com')));

        $this->assertSame('benim@example.com', $user->refresh()->email);
    }

    #[Test]
    public function a_tampered_link_is_refused(): void
    {
        $user = User::factory()->create();
        $this->requestChange($user, 'yeni@example.com');
        $link = str_replace('yeni%40example.com', 'saldirgan%40example.com', $this->linkSentTo('yeni@example.com'));

        $this->assertInvalid($this->confirm($link));
        $this->assertNotSame('saldirgan@example.com', $user->refresh()->email);
    }

    #[Test]
    public function an_expired_link_is_refused(): void
    {
        $user = User::factory()->create();
        $this->requestChange($user, 'yeni@example.com');
        $link = $this->linkSentTo('yeni@example.com');

        $this->travel(61)->minutes();

        $this->assertInvalid($this->confirm($link));
    }

    #[Test]
    public function a_link_without_parameters_gets_the_same_error(): void
    {
        $this->assertInvalid($this->postJson(route('public.email-change.confirm')));
    }

    /** @param  TestResponse<Response>  $response */
    private function assertInvalid(TestResponse $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::EmailChangeInvalid->value)
            ->assertJsonMissingPath('error.params');
    }

    /** @return TestResponse<Response> */
    private function requestChange(User $user, string $email, string $password = UserFactory::PASSWORD): TestResponse
    {
        $response = $this->withToken($user->createToken('istek')->plainTextToken)
            ->postJson(route('auth.email-change.store'), ['email' => $email, 'currentPassword' => $password]);

        $this->forgetAuthState();

        return $response;
    }

    /**
     * Frontend'in yaptığı: sayfa adresinin SORGUSUNU API'ye aynen gönderir.
     *
     * @return TestResponse<Response>
     */
    private function confirm(string $frontendUrl): TestResponse
    {
        $query = (string) parse_url($frontendUrl, PHP_URL_QUERY);

        return $this->postJson(route('public.email-change.confirm', absolute: false).'?'.$query);
    }

    private function linkSentTo(string $address): string
    {
        $url = null;

        Notification::assertSentOnDemand(
            EmailChangeConfirmationNotification::class,
            static function (EmailChangeConfirmationNotification $n, array $channels, AnonymousNotifiable $to) use ($address, &$url): bool {
                if ($to->routes['mail'] !== $address) {
                    return false;
                }

                $url = $n->url;

                return true;
            },
        );

        $this->assertIsString($url);

        return $url;
    }
}
