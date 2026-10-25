<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PUT /api/auth/password (Faz 14, 14.8 · K142).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/ChangePasswordAction.md §5
 */
final class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    #[Test]
    public function a_guest_cannot_change_a_password(): void
    {
        $this->putJson(route('auth.password.update'), ['currentPassword' => 'x', 'password' => 'yeni-parola-1'])
            ->assertUnauthorized();
    }

    #[Test]
    public function the_password_changes_and_only_this_session_survives(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('bu-cihaz')->plainTextToken;
        $other = $user->createToken('diger-cihaz')->plainTextToken;

        $this->withToken($current)
            ->putJson(route('auth.password.update'), ['currentPassword' => UserFactory::PASSWORD, 'password' => 'yeni-parola-123'])
            ->assertNoContent();

        $this->assertTrue(Hash::check('yeni-parola-123', $user->refresh()->password));
        $this->assertSame(['bu-cihaz'], $user->tokens()->pluck('name')->all());

        // Uçtan uca: diğer cihazın token'ı artık 401, bu cihazınki çalışıyor.
        $this->forgetAuthState();
        $this->withToken($other)->getJson(route('auth.me'))->assertUnauthorized();
        $this->forgetAuthState();
        $this->withToken($current)->getJson(route('auth.me'))->assertOk();

        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    #[Test]
    public function a_wrong_current_password_changes_nothing(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;

        $this->withToken($user->createToken('t')->plainTextToken)
            ->putJson(route('auth.password.update'), ['currentPassword' => 'yanlis-parola', 'password' => 'yeni-parola-123'])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.currentPassword.0.rule', 'current_password');

        $this->assertSame($hash, $user->refresh()->password);
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_new_password_follows_the_registration_rules(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->putJson(route('auth.password.update'), ['currentPassword' => UserFactory::PASSWORD, 'password' => 'kisa'])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.password.0.rule', 'min');

        $this->forgetAuthState();
        $this->withToken($token)
            ->putJson(route('auth.password.update'), ['currentPassword' => UserFactory::PASSWORD, 'password' => UserFactory::PASSWORD.'x'])
            ->assertNoContent();
    }

    #[Test]
    public function the_same_password_is_refused(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->putJson(route('auth.password.update'), ['currentPassword' => UserFactory::PASSWORD, 'password' => UserFactory::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.password.0.rule', 'different');
    }

    /** Eski parola zamanında istenmiş bir sıfırlama bağlantısı yeni parolayı ezemez. */
    #[Test]
    public function a_pending_reset_link_is_invalidated(): void
    {
        $user = User::factory()->create();
        Password::broker()->createToken($user);
        $this->assertSame(1, DB::table('password_reset_tokens')->count());

        $this->withToken($user->createToken('t')->plainTextToken)
            ->putJson(route('auth.password.update'), ['currentPassword' => UserFactory::PASSWORD, 'password' => 'yeni-parola-123'])
            ->assertNoContent();

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }
}
