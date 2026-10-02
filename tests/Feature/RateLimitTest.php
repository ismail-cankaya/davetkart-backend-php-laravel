<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContactSubject;
use App\Enums\ErrorCode;
use App\Enums\MediaKind;
use App\Enums\RsvpStatus;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 10 (10.60 · K104): misafir uçlarının hız sınırları.
 *
 * Sayılar sabit yazıldı (10.49'un dersi): bir düğün gecesinin ihtiyacına
 * göre seçildi ve değişirse bu test bilerek kırılmalı.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/RateLimitTest.md
 */
final class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, int}> */
    public static function weddingLimits(): array
    {
        return [
            'LCV, IP başına dakikada' => ['davetkart.rsvp.rate_limit.per_ip_per_minute', 20],
            'LCV, davetiye başına saatte' => ['davetkart.rsvp.rate_limit.per_invitation_per_hour', 300],
            'misafir medyası, IP başına dakikada' => ['davetkart.media.rate_limit.guest_per_ip_per_minute', 15],
            'misafir medyası, davetiye başına saatte' => ['davetkart.media.rate_limit.guest_per_invitation_per_hour', 150],
        ];
    }

    #[Test]
    #[DataProvider('weddingLimits')]
    public function the_limits_fit_a_crowded_wedding(string $key, int $expected): void
    {
        $this->assertSame($expected, Config::integer($key));
    }

    /**
     * 🔴 IPv6: aynı /64 içinde adres değiştirmek kovayı atlatmaz.
     *
     * Bir ev ya da mobil hat koca bir /64 alır; adres başına sayılsaydı tek
     * cihaz her istekte yeni bir adresle sınırı sonsuza dek aşardı.
     */
    #[Test]
    public function addresses_in_the_same_ipv6_slash_64_share_one_bucket(): void
    {
        Config::set('davetkart.rsvp.rate_limit.per_ip_per_minute', 1);
        $davetiye = $this->davetiye();

        $this->gonder($davetiye, '2a02:ff0:3:1a2b::10')->assertCreated();

        $this->gonder($davetiye, '2a02:ff0:3:1a2b::99')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);

        // Komşu /64 başka bir hat: kendi kovası var.
        $this->gonder($davetiye, '2a02:ff0:3:1a2c::10')->assertCreated();
    }

    /** Aynı kural misafirin medya yüklemesinde. */
    #[Test]
    public function guest_media_uploads_share_the_ipv6_slash_64_bucket(): void
    {
        Storage::fake(Config::string('davetkart.media.disk'));
        Config::set('davetkart.media.rate_limit.guest_per_ip_per_minute', 1);
        $davetiye = $this->davetiye();

        $yukle = fn (string $ip): TestResponse => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('public.invitations.media.store', $davetiye), [
                'kind' => MediaKind::RsvpPhoto->value,
                'file' => UploadedFile::fake()->image('ani.jpg', 40, 40),
            ]);

        $yukle('2a02:ff0:3:1a2b::10')->assertCreated();
        $yukle('2a02:ff0:3:1a2b::99')->assertStatus(429);
    }

    /** Aynı kural iletişim formunda. */
    #[Test]
    public function contact_messages_share_the_ipv6_slash_64_bucket(): void
    {
        Config::set('davetkart.contact.rate_limit.per_ip_per_minute', 1);

        $gonder = fn (string $ip): TestResponse => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('public.contact.store'), [
                'name' => 'Deniz Yılmaz',
                'email' => 'deniz@example.test',
                'subject' => ContactSubject::Pricing->value,
                'message' => 'Plan farklarını öğrenmek istiyorum.',
            ]);

        $gonder('2a02:ff0:3:1a2b::10')->assertNoContent();
        $gonder('2a02:ff0:3:1a2b::99')->assertStatus(429);
    }

    private function davetiye(): Invitation
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'));

        $davetiye = Invitation::factory()->published()->create([
            'event_at' => '2026-10-17 19:30:00',
            'show_rsvp' => true,
            'rsvp_deadline' => '2026-10-10',
        ]);
        Order::factory()->tier(SubscriptionTier::Standart)->forInvitation($davetiye)->paid()->create();

        return $davetiye;
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function gonder(Invitation $davetiye, string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('public.invitations.rsvps.store', $davetiye), [
                'guestName' => 'Şeyma Şen',
                'guestCount' => 2,
                'status' => RsvpStatus::Attending->value,
            ]);
    }
}
