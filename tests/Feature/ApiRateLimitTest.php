<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SEC-06 · K186: genel API kovası kimlik anahtarlı; misafir okuması kendi kovasında.
 * Sayılar testte küçültülür (Config::set); gerçek değerler RateLimitTest::weddingLimits'te.
 * Ayrintili aciklama: docs/rehber/app/Providers/AppServiceProvider.md (K186)
 */
final class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const string SALON_IP = '203.0.113.10';

    #[Test]
    public function an_authenticated_user_is_not_throttled_by_guests_on_the_same_ip(): void
    {
        Config::set('davetkart.api.rate_limit.per_ip_per_minute', 2);
        $token = User::factory()->create()->createToken('test')->plainTextToken;

        $this->guestCatalog(self::SALON_IP)->assertOk();
        $this->guestCatalog(self::SALON_IP)->assertOk();
        $this->guestCatalog(self::SALON_IP)->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => self::SALON_IP])
            ->withToken($token)
            ->getJson(route('auth.me'))
            ->assertOk();
    }

    #[Test]
    public function two_users_behind_one_ip_have_separate_buckets(): void
    {
        Config::set('davetkart.api.rate_limit.per_user_per_minute', 1);
        $first = User::factory()->create()->createToken('test')->plainTextToken;
        $second = User::factory()->create()->createToken('test')->plainTextToken;

        $this->me($first)->assertOk();
        $this->forgetAuthState();
        $this->me($first)->assertStatus(429);
        $this->forgetAuthState();
        $this->me($second)->assertOk();
    }

    #[Test]
    public function the_public_invitation_read_has_its_own_bucket(): void
    {
        Config::set('davetkart.api.rate_limit.per_ip_per_minute', 2);
        Config::set('davetkart.api.rate_limit.public_read_per_ip_per_minute', 4);
        $invitation = Invitation::factory()->published()->create();

        foreach (range(1, 4) as $_) {
            $this->withServerVariables(['REMOTE_ADDR' => self::SALON_IP])
                ->getJson(route('public.invitations.show', $invitation))
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => self::SALON_IP])
            ->getJson(route('public.invitations.show', $invitation))
            ->assertStatus(429);

        // Dört okuma genel kovadan yemedi: katalog hâlâ açık.
        $this->guestCatalog(self::SALON_IP)->assertOk();
    }

    #[Test]
    public function an_anonymous_client_is_still_limited_by_ip(): void
    {
        Config::set('davetkart.api.rate_limit.per_ip_per_minute', 1);

        $this->guestCatalog(self::SALON_IP)->assertOk();
        $this->guestCatalog(self::SALON_IP)->assertStatus(429);
        $this->guestCatalog('198.51.100.7')->assertOk();
    }

    /** @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse> */
    private function guestCatalog(string $ip): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson(route('public.plans.index'));
    }

    /** @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse> */
    private function me(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::SALON_IP])
            ->withToken($token)
            ->getJson(route('auth.me'));
    }
}
