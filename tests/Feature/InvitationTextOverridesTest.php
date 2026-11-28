<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\InvitationStatus;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Pricing\TierResolver;
use App\Support\TextSlots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 24 (K302–K305): davetiyenin dili ve değiştirilen sabit şablon metinleri.
 * Ayrıntı: docs/rehber/tests/Feature/InvitationTextOverridesTest.md
 */
final class InvitationTextOverridesTest extends TestCase
{
    use RefreshDatabase;

    // ——— Kayıt ve okuma ———

    #[Test]
    public function the_owner_saves_and_reads_back_custom_texts(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => [
                'textOverrides' => ['tr' => ['rsvp.submit' => 'Geliyorum!', 'hero.tagline' => 'Sonunda']],
            ]])
            ->assertOk()
            ->assertJsonPath('data.invitation.locale', 'tr')
            ->assertJsonPath('data.invitation.textOverrides.tr', ['rsvp.submit' => 'Geliyorum!', 'hero.tagline' => 'Sonunda']);

        $fresh = $invitation->refresh();
        $this->assertTrue($fresh->has_custom_texts);
        $this->assertSame(['tr' => ['rsvp.submit' => 'Geliyorum!', 'hero.tagline' => 'Sonunda']], $fresh->text_overrides);
    }

    #[Test]
    public function an_invitation_without_custom_texts_answers_an_empty_object(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        $response = $this->withToken($this->tokenFor($user))
            ->getJson(route('invitations.show', $invitation))
            ->assertOk()
            ->assertJsonPath('data.invitation.locale', 'tr');

        // Dizi ([]) değil nesne ({}): frontend anahtar → metin haritası bekler.
        $this->assertStringContainsString('"textOverrides":{}', (string) $response->getContent());
    }

    #[Test]
    public function an_empty_map_resets_every_text_and_clears_the_flag(): void
    {
        [$user, $invitation] = $this->ownedInvitation();
        $invitation->update(['text_overrides' => ['tr' => ['rsvp.submit' => 'Geliyorum!']]]);
        $this->assertTrue($invitation->refresh()->has_custom_texts);

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['textOverrides' => ['tr' => []]]])
            ->assertOk();

        $fresh = $invitation->refresh();
        $this->assertFalse($fresh->has_custom_texts);
        $this->assertSame([], $fresh->text_overrides);
        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'text_overrides' => null]);
    }

    #[Test]
    public function omitting_the_field_leaves_the_texts_alone(): void
    {
        [$user, $invitation] = $this->ownedInvitation();
        $invitation->update(['text_overrides' => ['tr' => ['rsvp.submit' => 'Geliyorum!']]]);

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['title' => 'Yeni başlık']])
            ->assertOk();

        $this->assertSame(['tr' => ['rsvp.submit' => 'Geliyorum!']], $invitation->refresh()->text_overrides);
    }

    #[Test]
    public function the_flag_follows_the_texts_at_fill_time_not_at_save_time(): void
    {
        $invitation = Invitation::factory()->make();

        $invitation->fill(['text_overrides' => ['tr' => ['gift.note' => 'Not']]]);
        $this->assertTrue($invitation->has_custom_texts);

        $invitation->fill(['text_overrides' => null]);
        $this->assertFalse($invitation->has_custom_texts);
    }

    #[Test]
    public function the_flag_cannot_be_written_directly(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['hasCustomTexts' => true]])
            ->assertOk();

        $this->assertFalse($invitation->refresh()->has_custom_texts);
        $this->assertNotContains('has_custom_texts', $invitation->getFillable());
    }

    // ——— Doğrulama ———

    /** @param  array<mixed>  $overrides */
    #[Test]
    #[DataProvider('invalidOverrides')]
    public function invalid_texts_are_rejected_with_the_text_overrides_rule(array $overrides): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        $response = $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['textOverrides' => $overrides]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);

        $this->assertSame(TextSlots::RULE, $response->json('error.fields')['invitation.textOverrides'][0]['rule'] ?? null);

        $this->assertFalse($invitation->refresh()->has_custom_texts);
    }

    /** @return array<string, array{array<mixed>}> */
    public static function invalidOverrides(): array
    {
        return [
            'bilinmeyen anahtar' => [['tr' => ['hero.unknown' => 'x']]],
            'desteklenmeyen dil' => [['en' => ['rsvp.submit' => 'Coming']]],
            'boş metin' => [['tr' => ['rsvp.submit' => '   ']]],
            'sınır aşımı' => [['tr' => ['rsvp.submit' => str_repeat('a', 400)]]],
            'tek satırlıkta satır sonu' => [['tr' => ['rsvp.submit' => "Gel\niyorum"]]],
            'kontrol karakteri' => [['tr' => ['gift.note' => "Not\u{0007}"]]],
            'yer tutucu eksik' => [['tr' => ['rsvp.deadline' => 'Lütfen bildirin']]],
            'yer tutucu iki kez' => [['tr' => ['rsvp.deadline' => '{date} ve {date}']]],
            'metin değil' => [['tr' => ['rsvp.submit' => 42]]],
            'liste' => [['tr' => ['Geliyorum']]],
        ];
    }

    #[Test]
    public function a_multiline_slot_accepts_a_line_break_and_the_placeholder_once(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['textOverrides' => ['tr' => [
                'gift.note' => "Varlığınız yeter.\nYine de hesap aşağıda.",
                'rsvp.deadline' => 'Lütfen {date} tarihine kadar haber verin.',
            ]]]])
            ->assertOk();
    }

    #[Test]
    public function html_is_stored_as_plain_text(): void
    {
        [$user, $invitation] = $this->ownedInvitation();
        $text = '<b>Geliyorum</b>';

        $response = $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['textOverrides' => ['tr' => ['rsvp.submit' => $text]]]])
            ->assertOk();

        $this->assertSame($text, $response->json('data.invitation.textOverrides.tr')['rsvp.submit'] ?? null);
    }

    #[Test]
    public function an_unsupported_locale_is_rejected(): void
    {
        [$user, $invitation] = $this->ownedInvitation();

        $response = $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['locale' => 'en']])
            ->assertStatus(422);

        $this->assertSame('in', $response->json('error.fields')['invitation.locale'][0]['rule'] ?? null);
    }

    // ——— Paket (K305) ———

    #[Test]
    public function custom_texts_require_the_elit_tier(): void
    {
        $invitation = Invitation::factory()->create(['text_overrides' => ['tr' => ['rsvp.submit' => 'Geliyorum!']]]);

        $this->assertSame(SubscriptionTier::Elit, app(TierResolver::class)->requiredFor($invitation));
    }

    #[Test]
    public function a_gold_order_cannot_publish_an_invitation_with_custom_texts(): void
    {
        [$user, $invitation] = $this->ownedInvitation(['text_overrides' => ['tr' => ['hero.tagline' => 'Sonunda']]]);
        Order::factory()->paid()->tier(SubscriptionTier::Gold)->forInvitation($invitation)->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaywallTierInsufficient->value)
            ->assertJsonPath('error.params.requiredTier', SubscriptionTier::Elit->value);

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'status' => InvitationStatus::Saved->value]);
    }

    #[Test]
    public function an_elit_order_publishes_an_invitation_with_custom_texts(): void
    {
        [$user, $invitation] = $this->ownedInvitation(['text_overrides' => ['tr' => ['hero.tagline' => 'Sonunda']]]);
        Order::factory()->paid()->tier(SubscriptionTier::Elit)->forInvitation($invitation)->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('invitations.publish', $invitation))
            ->assertOk()
            ->assertJsonPath('data.status', InvitationStatus::Published->value);
    }

    /** 🔴 Yayındaki Gold davetiyeye sonradan özel metin eklemek: 402 ve hiçbir satır yazılmaz. */
    #[Test]
    public function adding_custom_texts_to_a_published_gold_invitation_is_refused(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->published()->create(['user_id' => $user->id]);
        Order::factory()->paid()->tier(SubscriptionTier::Gold)->forInvitation($invitation)->create();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => [
                'title' => 'Değişmemeli',
                'textOverrides' => ['tr' => ['rsvp.submit' => 'Geliyorum!']],
            ]])
            ->assertStatus(402)
            ->assertJsonPath('error.code', ErrorCode::PaywallTierInsufficient->value)
            ->assertJsonPath('error.params.requiredTier', SubscriptionTier::Elit->value);

        $fresh = $invitation->refresh();
        $this->assertFalse($fresh->has_custom_texts);
        $this->assertNotSame('Değişmemeli', $fresh->title);
    }

    /** Kullanıcının kendi içeriği paket istemez (İsmail, 27 Kasım): başlık, isimler, mekân. */
    #[Test]
    public function editing_own_content_on_a_published_standart_invitation_needs_no_upgrade(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->published()->create(['user_id' => $user->id]);
        Order::factory()->paid()->tier(SubscriptionTier::Standart)->forInvitation($invitation)->create();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => [
                'title' => 'Nikâhımıza Davetlisiniz',
                'names' => 'Deniz & Can',
                'venue' => 'Kuzguncuk',
            ]])
            ->assertOk();
    }

    #[Test]
    public function removing_custom_texts_never_needs_an_upgrade(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->published()->create([
            'user_id' => $user->id,
            'text_overrides' => ['tr' => ['rsvp.submit' => 'Geliyorum!']],
        ]);
        Order::factory()->paid()->tier(SubscriptionTier::Elit)->forInvitation($invitation)->create();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('invitations.update', $invitation), ['invitation' => ['textOverrides' => null]])
            ->assertOk();

        $this->assertFalse($invitation->refresh()->has_custom_texts);
    }

    // ——— Misafir ———

    #[Test]
    public function the_guest_sees_the_custom_texts(): void
    {
        $invitation = Invitation::factory()->published()->create([
            'text_overrides' => ['tr' => ['rsvp.submit' => 'Geliyorum!']],
        ]);

        $response = $this->getJson(route('public.invitations.show', $invitation))
            ->assertOk()
            ->assertJsonPath('data.invitation.locale', 'tr');

        $this->assertSame('Geliyorum!', $response->json('data.invitation.textOverrides.tr')['rsvp.submit'] ?? null);
    }

    // ——— Yardımcılar ———

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array{0: User, 1: Invitation}
     */
    private function ownedInvitation(array $overrides = []): array
    {
        $user = User::factory()->create();

        /** @var array<string, mixed> $attributes */
        $attributes = array_merge(['user_id' => $user->id], $overrides);

        return [$user, Invitation::factory()->create($attributes)];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
