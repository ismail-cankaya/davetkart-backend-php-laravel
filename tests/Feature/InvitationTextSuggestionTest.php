<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 25 (K310): şablon metni için asistan önerileri — altyapı (uç, sahiplik, kota, süzgeç).
 * Ayrıntı: docs/rehber/tests/Feature/InvitationTextSuggestionTest.md
 */
final class InvitationTextSuggestionTest extends TestCase
{
    use RefreshDatabase;

    /** Sağlayıcıya giden son istem (istemin sunucuda kurulduğunu sınamak için). */
    public static string $lastPrompt = '';

    #[Test]
    public function the_owner_gets_up_to_three_valid_suggestions(): void
    {
        $this->bindProvider("1. Geliyorum!\n- \"Orada olacağım\"\n• Katılıyorum\nDördüncü öneri\n");
        [$user, $invitation] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.text-suggestions', $invitation), ['key' => 'rsvp.submit', 'current' => 'Katılımımı Bildir'])
            ->assertOk()
            ->assertJsonPath('data.suggestions', ['Geliyorum!', 'Orada olacağım', 'Katılıyorum']);

        $this->assertStringContainsString('rsvp.submit', self::$lastPrompt);
        $this->assertStringContainsString('Katılımımı Bildir', self::$lastPrompt);
    }

    #[Test]
    public function suggestions_that_break_the_contract_are_dropped(): void
    {
        $this->bindProvider(str_repeat('a', 300)."\nKatılımımı Bildir\nGeliyorum\nGeliyorum\n");
        [$user, $invitation] = $this->ownedInvitation();

        // Çok uzun, şimdiki metnin aynısı ve tekrar atılır.
        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.text-suggestions', $invitation), ['key' => 'rsvp.submit', 'current' => 'Katılımımı Bildir'])
            ->assertOk()
            ->assertJsonPath('data.suggestions', ['Geliyorum']);
    }

    #[Test]
    public function a_placeholder_slot_keeps_only_suggestions_with_the_placeholder(): void
    {
        $this->bindProvider("Lütfen {date} tarihine kadar bildirin.\nLütfen yakında bildirin.\n");
        [$user, $invitation] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.text-suggestions', $invitation), ['key' => 'rsvp.deadline', 'current' => 'x {date}'])
            ->assertOk()
            ->assertJsonPath('data.suggestions', ['Lütfen {date} tarihine kadar bildirin.']);
    }

    #[Test]
    public function someone_elses_invitation_is_not_found(): void
    {
        $this->bindProvider('Geliyorum');
        [, $invitation] = $this->ownedInvitation();
        $stranger = User::factory()->create();

        $this->withToken($this->tokenFor($stranger))
            ->postJson(route('invitations.text-suggestions', $invitation), ['key' => 'rsvp.submit', 'current' => 'x'])
            ->assertNotFound();

        $this->assertDatabaseCount('assistant_usages', 0);
    }

    #[Test]
    public function an_unknown_key_is_rejected_before_the_quota(): void
    {
        $this->bindProvider('Geliyorum');
        [$user, $invitation] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.text-suggestions', $invitation), ['key' => 'hero.unknown', 'current' => 'x'])
            ->assertStatus(422);

        $this->assertDatabaseCount('assistant_usages', 0);
    }

    #[Test]
    public function suggestions_share_the_daily_assistant_quota(): void
    {
        Config::set('davetkart.assistant.daily_message_limit_per_user', 1);
        $this->bindProvider('Geliyorum');
        [$user, $invitation] = $this->ownedInvitation();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson(route('assistant.chat'), ['message' => 'merhaba'])->assertOk();

        $this->withToken($token)
            ->postJson(route('invitations.text-suggestions', $invitation), ['key' => 'rsvp.submit', 'current' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::AssistantQuotaExceeded->value);
    }

    #[Test]
    public function the_endpoint_requires_authentication(): void
    {
        [, $invitation] = $this->ownedInvitation();

        $this->postJson(route('invitations.text-suggestions', $invitation), ['key' => 'rsvp.submit', 'current' => 'x'])
            ->assertUnauthorized();
    }

    private function bindProvider(string $reply): void
    {
        $this->app->instance(AiProvider::class, new class($reply) implements AiProvider
        {
            public function __construct(private readonly string $reply) {}

            public function name(): string
            {
                return 'stub';
            }

            public function reply(string $prompt): string
            {
                InvitationTextSuggestionTest::$lastPrompt = $prompt;

                return $this->reply;
            }
        });
    }

    /** @return array{0: User, 1: Invitation} */
    private function ownedInvitation(): array
    {
        $user = User::factory()->create();

        return [$user, Invitation::factory()->create(['user_id' => $user->id])];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
