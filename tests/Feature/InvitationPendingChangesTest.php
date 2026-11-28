<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\SubscriptionTier;
use App\Events\InvitationChanged;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 27.3 (K323, K324): yayındaki davetiyenin taslak katmanı.
 * Ayrıntı: docs/rehber/tests/Feature/InvitationPendingChangesTest.md
 */
final class InvitationPendingChangesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_published_invitation_keeps_studio_changes_away_from_guests(): void
    {
        [$user, $invitation] = $this->published(['title' => 'Canlı başlık']);

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['title' => 'Taslak başlık', 'venue' => $invitation->venue]])
            ->assertOk()
            ->assertJsonPath('data.hasPendingChanges', true)
            ->assertJsonPath('data.pendingChanges.invitation', ['title' => 'Taslak başlık'])
            ->assertJsonPath('data.invitation.title', 'Canlı başlık');

        $this->assertSame('Canlı başlık', $invitation->refresh()->title);
        $this->getJson(route('public.invitations.show', $invitation))
            ->assertOk()
            ->assertJsonPath('data.invitation.title', 'Canlı başlık')
            ->assertJsonMissingPath('data.pendingChanges')
            ->assertJsonMissingPath('data.invitation.pendingChanges');
    }

    #[Test]
    public function saving_a_draft_neither_fires_the_cache_event_nor_touches_updated_at(): void
    {
        [$user, $invitation] = $this->published();
        $before = $invitation->refresh()->updated_at;
        Event::fake([InvitationChanged::class]);
        $this->travel(5)->minutes();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['title' => 'Taslak']])
            ->assertOk();

        Event::assertNotDispatched(InvitationChanged::class);
        $this->assertEquals($before, $invitation->refresh()->updated_at);
    }

    #[Test]
    public function a_value_equal_to_the_live_one_is_not_a_pending_change(): void
    {
        [$user, $invitation] = $this->published(['title' => 'Aynı']);

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['title' => 'Aynı']])
            ->assertOk()
            ->assertJsonPath('data.hasPendingChanges', false)
            ->assertJsonPath('data.pendingChanges', null);

        $this->assertNull($invitation->refresh()->pending_changes);
    }

    #[Test]
    public function drafts_merge_and_going_back_to_the_live_value_clears_the_field(): void
    {
        [$user, $invitation] = $this->published(['title' => 'Canlı', 'venue' => 'Eski mekân']);
        $token = $this->tokenFor($user);

        $this->withToken($token)->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['title' => 'Yeni']])->assertOk();
        $this->withToken($token)
            ->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['venue' => 'Yeni mekân']])
            ->assertJsonPath('data.pendingChanges.invitation', ['title' => 'Yeni', 'venue' => 'Yeni mekân']);
        $this->withToken($token)
            ->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['title' => 'Canlı']])
            ->assertJsonPath('data.pendingChanges.invitation', ['venue' => 'Yeni mekân']);
    }

    #[Test]
    public function publishing_applies_the_draft_and_empties_it(): void
    {
        [$user, $invitation] = $this->published(['title' => 'Canlı']);
        $token = $this->tokenFor($user);

        $this->withToken($token)->putJson(route('invitations.pending.update', $invitation), ['invitation' => [
            'title' => 'Yayınlanan',
            'timelineEvents' => [['id' => null, 'time' => '19:00', 'title' => 'Karşılama', 'description' => '']],
        ]])->assertOk();
        $this->assertSame(0, $invitation->timelineEvents()->count());

        $this->withToken($token)
            ->postJson(route('invitations.pending.publish', $invitation))
            ->assertOk()
            ->assertJsonPath('data.hasPendingChanges', false)
            ->assertJsonPath('data.invitation.title', 'Yayınlanan');

        $fresh = $invitation->refresh();
        $this->assertNull($fresh->pending_changes);
        $this->assertSame(['Karşılama'], $fresh->timelineEvents()->pluck('title')->all());
        $this->getJson(route('public.invitations.show', $invitation))->assertJsonPath('data.invitation.title', 'Yayınlanan');
    }

    /** 🔴 Taslak paket kapısını atlatamaz: yayınlamada 402, canlı değişmez, taslak kalır. */
    #[Test]
    public function publishing_a_draft_above_the_owned_plan_is_refused_and_the_draft_stays(): void
    {
        [$user, $invitation] = $this->published(['title' => 'Canlı'], SubscriptionTier::Gold);
        $token = $this->tokenFor($user);

        $this->withToken($token)->putJson(route('invitations.pending.update', $invitation), ['invitation' => [
            'title' => 'Taslak',
            'textOverrides' => ['tr' => ['rsvp.submit' => 'Geliyorum!']],
        ]])->assertOk();

        $this->withToken($token)
            ->postJson(route('invitations.pending.publish', $invitation))
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaywallTierInsufficient->value)
            ->assertJsonPath('error.params.requiredTier', SubscriptionTier::Elit->value);

        $fresh = $invitation->refresh();
        $this->assertSame('Canlı', $fresh->title);
        $this->assertFalse($fresh->has_custom_texts);
        $this->assertSame('Taslak', $fresh->pending_changes['attributes']['title'] ?? null);
    }

    #[Test]
    public function discarding_drops_the_draft_without_touching_the_live_invitation(): void
    {
        [$user, $invitation] = $this->published(['title' => 'Canlı']);
        $token = $this->tokenFor($user);
        $this->withToken($token)->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['title' => 'Taslak']]);

        $this->withToken($token)
            ->deleteJson(route('invitations.pending.destroy', $invitation))
            ->assertOk()
            ->assertJsonPath('data.hasPendingChanges', false)
            ->assertJsonPath('data.invitation.title', 'Canlı');

        $this->assertNull($invitation->refresh()->pending_changes);
    }

    /** K324: hızlı düzenleme canlıya yazar; yazdığı alan taslaktan düşer, diğerleri kalır. */
    #[Test]
    public function a_direct_update_drops_the_written_fields_from_the_draft(): void
    {
        [$user, $invitation] = $this->published(['title' => 'Canlı', 'venue' => 'Eski']);
        $token = $this->tokenFor($user);
        $this->withToken($token)->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['title' => 'Taslak', 'venue' => 'Taslak mekân']]);

        $this->withToken($token)
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['title' => 'Hızlı düzeltme']])
            ->assertOk()
            ->assertJsonPath('data.pendingChanges.invitation', ['venue' => 'Taslak mekân']);

        $this->assertSame('Hızlı düzeltme', $invitation->refresh()->title);
    }

    #[Test]
    public function a_draft_invitation_writes_directly_because_no_guest_sees_it(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->create(['user_id' => $user->id, 'title' => 'Eski']);

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['title' => 'Yeni']])
            ->assertOk()
            ->assertJsonPath('data.hasPendingChanges', false)
            ->assertJsonPath('data.invitation.title', 'Yeni');

        $this->assertNull($invitation->refresh()->pending_changes);
    }

    #[Test]
    public function only_the_owner_reaches_the_draft(): void
    {
        [, $invitation] = $this->published();
        $stranger = $this->tokenFor(User::factory()->create());

        $this->withToken($stranger)->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['title' => 'X']])->assertNotFound();
        $this->withToken($stranger)->postJson(route('invitations.pending.publish', $invitation))->assertNotFound();
        $this->withToken($stranger)->deleteJson(route('invitations.pending.destroy', $invitation))->assertNotFound();
    }

    #[Test]
    public function the_draft_is_validated_like_a_normal_update(): void
    {
        [$user, $invitation] = $this->published();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.pending.update', $invitation), ['invitation' => ['title' => str_repeat('a', 121)]])
            ->assertUnprocessable();

        $this->assertNull($invitation->refresh()->pending_changes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @return array{0: User, 1: Invitation}
     */
    private function published(array $attributes = [], SubscriptionTier $tier = SubscriptionTier::Elit): array
    {
        $user = User::factory()->create();
        /** @var array<string, mixed> $merged */
        $merged = array_merge(['user_id' => $user->id], $attributes);
        $invitation = Invitation::factory()->published()->create($merged);
        Order::factory()->paid()->tier($tier)->forInvitation($invitation)->create();

        return [$user, $invitation];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
