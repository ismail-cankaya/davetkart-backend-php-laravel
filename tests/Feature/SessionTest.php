<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aktif oturumlar (Faz 14, 14.9 · K143).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Auth/IssueApiTokenAction.md §6
 */
final class SessionTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    #[Test]
    public function login_records_the_device(): void
    {
        $user = User::factory()->create();

        $this->withHeader('User-Agent', self::SAFARI_IPHONE)
            ->postJson(route('auth.login'), ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertOk();

        $this->assertSame(self::SAFARI_IPHONE, PersonalAccessToken::query()->sole()->getAttribute('user_agent'));
    }

    #[Test]
    public function register_records_the_device(): void
    {
        $this->withHeader('User-Agent', self::CHROME_WINDOWS)
            ->postJson(route('auth.register'), [
                'firstName' => 'A', 'lastName' => 'B', 'email' => 'yeni@example.com', 'password' => 'parola-123',
            ])
            ->assertCreated();

        $this->assertSame(self::CHROME_WINDOWS, PersonalAccessToken::query()->sole()->getAttribute('user_agent'));
    }

    #[Test]
    public function a_huge_user_agent_is_truncated(): void
    {
        $user = User::factory()->create();

        $this->withHeader('User-Agent', str_repeat('x', 2000))
            ->postJson(route('auth.login'), ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertOk();

        $this->assertSame(255, mb_strlen((string) PersonalAccessToken::query()->sole()->getAttribute('user_agent')));
    }

    #[Test]
    public function the_list_names_devices_and_marks_the_current_one(): void
    {
        $user = User::factory()->create();
        $phone = $this->login($user, self::SAFARI_IPHONE);
        $laptop = $this->login($user, self::CHROME_WINDOWS);

        $this->forgetAuthState();
        $rows = $this->withToken($laptop)->getJson(route('auth.sessions.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->collect('data');
        $current = $rows->firstWhere('current', true);
        $other = $rows->firstWhere('current', false);

        $this->assertIsArray($current);
        $this->assertSame(['Chrome', 'Windows'], [$current['browser'], $current['system']]);
        $this->assertIsArray($other);
        $this->assertSame(['Safari', 'iOS'], [$other['browser'], $other['system']]);
        $this->assertSame(['id', 'browser', 'system', 'createdAt', 'lastUsedAt', 'current'], array_keys($current));
        $this->assertNotSame('', $phone);
    }

    /** 🔴 Token hash'i ve ham User-Agent dışarı çıkmaz. */
    #[Test]
    public function no_secret_leaves_the_server(): void
    {
        $user = User::factory()->create();
        $token = $this->login($user, self::CHROME_WINDOWS);
        $hash = PersonalAccessToken::query()->sole()->token;

        $this->forgetAuthState();
        $body = (string) $this->withToken($token)->getJson(route('auth.sessions.index'))->getContent();

        $this->assertStringNotContainsString($hash, $body);
        $this->assertStringNotContainsString('AppleWebKit', $body);
    }

    #[Test]
    public function an_expired_token_is_not_listed(): void
    {
        $user = User::factory()->create();
        $user->createToken('eski')->accessToken->forceFill(['created_at' => now()->subDays(31)])->save();
        $token = $user->createToken('yeni')->plainTextToken;

        $this->withToken($token)->getJson(route('auth.sessions.index'))->assertJsonCount(1, 'data');
    }

    #[Test]
    public function one_session_can_be_closed(): void
    {
        $user = User::factory()->create();
        $phone = $this->login($user, self::SAFARI_IPHONE);
        $laptop = $this->login($user, self::CHROME_WINDOWS);
        $phoneId = PersonalAccessToken::findToken($phone)?->id;
        $this->assertNotNull($phoneId);

        $this->forgetAuthState();
        $this->withToken($laptop)->deleteJson(route('auth.sessions.destroy', $phoneId))->assertNoContent();

        $this->forgetAuthState();
        $this->withToken($phone)->getJson(route('auth.me'))->assertUnauthorized();
        $this->forgetAuthState();
        $this->withToken($laptop)->getJson(route('auth.me'))->assertOk();
    }

    /** H7: başkasının oturum kimliği 404 ve o oturum açık kalır. */
    #[Test]
    public function another_users_session_cannot_be_closed(): void
    {
        $victim = User::factory()->create();
        $victimToken = $victim->createToken('t');
        $attacker = User::factory()->create()->createToken('t')->plainTextToken;

        $this->withToken($attacker)
            ->deleteJson(route('auth.sessions.destroy', $victimToken->accessToken->id))
            ->assertNotFound();

        $this->assertModelExists($victimToken->accessToken);
    }

    #[Test]
    public function all_other_sessions_can_be_closed(): void
    {
        $user = User::factory()->create();
        $this->login($user, self::SAFARI_IPHONE);
        $this->login($user, self::SAFARI_IPHONE);
        $laptop = $this->login($user, self::CHROME_WINDOWS);
        $foreign = User::factory()->create()->createToken('t');

        $this->forgetAuthState();
        $this->withToken($laptop)->deleteJson(route('auth.sessions.destroy-others'))->assertNoContent();

        $this->assertSame(1, $user->tokens()->count());
        $this->assertModelExists($foreign->accessToken);
        $this->forgetAuthState();
        $this->withToken($laptop)->getJson(route('auth.me'))->assertOk();
    }

    #[Test]
    public function a_guest_sees_no_sessions(): void
    {
        $this->getJson(route('auth.sessions.index'))->assertUnauthorized();
    }

    private function login(User $user, string $agent): string
    {
        $token = $this->withHeader('User-Agent', $agent)
            ->postJson(route('auth.login'), ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertOk()
            ->json('token');

        $this->assertIsString($token);

        return $token;
    }
}
