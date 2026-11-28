<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 29.4 (K328): "telefonda aç" imzalı önizleme bağlantısı.
 * Ayrıntı: docs/rehber/tests/Feature/InvitationPreviewLinkTest.md
 */
final class InvitationPreviewLinkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_owner_gets_a_signed_frontend_link_that_expires_in_a_day(): void
    {
        [$user, $invitation] = $this->owned();
        config(['davetkart.frontend.url' => 'https://davetkart.test']);
        $this->freezeTime();

        $response = $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.preview-link.store', $invitation))
            ->assertOk()
            ->assertJsonPath('data.expiresAt', now()->addDay()->toIso8601String());

        $url = (string) $response->json('data.url');
        $this->assertStringStartsWith("https://davetkart.test/preview/{$invitation->id}?", $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    #[Test]
    public function a_stranger_gets_no_link(): void
    {
        [, $invitation] = $this->owned();

        $this->withToken($this->tokenFor(User::factory()->create()))
            ->postJson(route('invitations.preview-link.store', $invitation))
            ->assertNotFound();
    }

    #[Test]
    public function the_signed_preview_shows_the_pending_changes_but_the_guest_page_does_not(): void
    {
        [$user, $invitation] = $this->owned(['title' => 'Canlı', 'show_timeline' => true], published: true);
        $token = $this->tokenFor($user);
        $this->withToken($token)->putJson(route('invitations.pending.update', $invitation), ['invitation' => [
            'title' => 'Taslak',
            'timelineEvents' => [['id' => null, 'time' => '19:00', 'title' => 'Karşılama', 'description' => '']],
        ]])->assertOk();

        $this->getJson($this->signedApiPath($user, $invitation))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJsonPath('data.invitation.title', 'Taslak')
            ->assertJsonPath('data.invitation.timelineEvents.0.title', 'Karşılama');

        $this->getJson(route('public.invitations.show', $invitation))->assertJsonPath('data.invitation.title', 'Canlı');
        $this->assertSame('Canlı', $invitation->refresh()->title);
        $this->assertSame(0, $invitation->timelineEvents()->count());
    }

    #[Test]
    public function a_draft_invitation_can_be_previewed_too(): void
    {
        [$user, $invitation] = $this->owned(['title' => 'Henüz yayında değil']);

        $this->getJson($this->signedApiPath($user, $invitation))
            ->assertOk()
            ->assertJsonPath('data.invitation.title', 'Henüz yayında değil');
        $this->getJson(route('public.invitations.show', $invitation))->assertNotFound();
    }

    /** H7: reddedilen imza 404 (ApiExceptionRenderer 403'ü 404'e çevirir: önizlemenin varlığı sızmaz). */
    #[Test]
    public function a_tampered_or_expired_signature_is_refused(): void
    {
        [$user, $invitation] = $this->owned();
        $path = $this->signedApiPath($user, $invitation);

        $this->getJson($path)->assertOk();
        $this->getJson(route('public.invitations.preview', $invitation))->assertNotFound();
        $this->getJson(preg_replace('/signature=[0-9a-f]+/', 'signature=deadbeef', $path) ?? '')->assertNotFound();

        $this->travel(25)->hours();
        $this->getJson($path)->assertNotFound();
    }

    #[Test]
    public function a_signature_for_one_invitation_does_not_open_another(): void
    {
        [$user, $invitation] = $this->owned();
        $other = Invitation::factory()->create(['user_id' => $user->id]);
        $path = $this->signedApiPath($user, $invitation);

        $this->getJson(str_replace((string) $invitation->id, (string) $other->id, $path))->assertNotFound();
    }

    /** Bağlantının sorgu dizesi, frontend sayfasının çağırdığı API yoluna eklenir. */
    private function signedApiPath(User $user, Invitation $invitation): string
    {
        $url = (string) $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.preview-link.store', $invitation))
            ->json('data.url');
        $this->forgetAuthState();

        return "/api/public/invitations/{$invitation->id}/preview?".parse_url($url, PHP_URL_QUERY);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @return array{0: User, 1: Invitation}
     */
    private function owned(array $attributes = [], bool $published = false): array
    {
        $user = User::factory()->create();
        /** @var array<string, mixed> $merged */
        $merged = array_merge(['user_id' => $user->id], $attributes);
        $factory = Invitation::factory();

        return [$user, ($published ? $factory->published() : $factory)->create($merged)];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
