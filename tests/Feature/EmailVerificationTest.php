<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\Pricing\FirstPublishEligibility;
use App\Support\EmailVerificationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** Faz 19 (19.14 · K191, SEC-09): kayıt sonrası e-posta doğrulaması ve ödeme/yayın kapısı. */
final class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Config::set('davetkart.tiers.gold.price', 399);
        Config::set('davetkart.pricing.first_publish_percent', 60);
    }

    // ------------------------------------------------------------ KAYIT VE BAĞLANTI

    #[Test]
    public function registering_queues_a_verification_mail_and_still_opens_a_session(): void
    {
        $this->postJson(route('auth.register'), [
            'firstName' => 'Ayşe',
            'lastName' => 'Yıldırım',
            'email' => 'ayse@ornek.test',
            'password' => 'Guclu-Parola-2026',
        ])
            ->assertCreated()
            ->assertJsonPath('user.emailVerified', false)
            ->assertJsonStructure(['token']);

        $user = User::query()->where('email', 'ayse@ornek.test')->sole();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    #[Test]
    public function a_verification_link_works_once_and_expires(): void
    {
        $user = User::factory()->unverified()->create();
        $link = EmailVerificationLink::for($user);

        $this->confirm($link)->assertNoContent();
        $this->assertNotNull($user->fresh()?->email_verified_at);

        $this->confirm($link)
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::EmailVerificationInvalid->value);

        $late = User::factory()->unverified()->create();
        $expiring = EmailVerificationLink::for($late);
        $this->travel(Config::integer('davetkart.auth.email_verification_expire_minutes') + 1)->minutes();

        $this->confirm($expiring)->assertStatus(422);
        $this->assertNull($late->fresh()?->email_verified_at);
    }

    /** Plandan sapma (K191): EmailChangeLink deseni hiçbir sır saklamaz; bağlantı ham adresi de taşımaz. */
    #[Test]
    public function the_link_stores_no_secret_and_carries_no_raw_address(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'gizli@ornek.test']);
        $before = $user->fresh()?->getAttributes();

        $link = EmailVerificationLink::for($user);

        $this->assertStringNotContainsString('gizli', $link);
        $this->assertMatchesRegularExpression('/[?&]address=[0-9a-f]{64}(&|$)/', $link);
        $this->assertMatchesRegularExpression('/[?&]signature=[0-9a-f]{64}(&|$)/', $link);
        $this->assertSame($before, $user->fresh()?->getAttributes());
    }

    #[Test]
    public function a_link_for_a_changed_address_or_a_tampered_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'eski@ornek.test']);
        $link = EmailVerificationLink::for($user);

        $this->confirm(str_replace('user='.$user->id, 'user='.($user->id + 1), $link))->assertStatus(422);

        $user->email = 'yeni@ornek.test';
        $user->save();

        $this->confirm($link)
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::EmailVerificationInvalid->value);
        $this->assertNull($user->fresh()?->email_verified_at);
    }

    // ------------------------------------------------------------ YENİDEN GÖNDERME

    #[Test]
    public function resending_is_rate_limited(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        foreach (range(1, 3) as $_) {
            $this->withToken($token)->postJson(route('auth.email-verification.store'))->assertNoContent();
        }

        $this->withToken($token)->postJson(route('auth.email-verification.store'))
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 3);
    }

    #[Test]
    public function resending_to_a_verified_account_sends_nothing(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('auth.email-verification.store'))
            ->assertNoContent();

        Notification::assertNothingSent();
    }

    // ------------------------------------------------------------ KAPI (D2) VE İNDİRİM (D3)

    #[Test]
    public function an_unverified_account_cannot_start_a_checkout(): void
    {
        $user = User::factory()->unverified()->create();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('payments.checkout'), ['tier' => 'gold'])
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::EmailNotVerified->value);

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function an_unverified_account_cannot_publish(): void
    {
        $user = User::factory()->unverified()->create();
        $invitation = Invitation::factory()->for($user)->create();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('invitations.publish', $invitation))
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::EmailNotVerified->value);

        $this->assertNotSame(InvitationStatus::Published, $invitation->fresh()?->status);
    }

    /** Önizleme (fiyat ucu) ve checkout aynı kuralı okur (K163): doğrulanmamışa indirim yok. */
    #[Test]
    public function an_unverified_account_gets_no_first_publish_discount(): void
    {
        $user = User::factory()->unverified()->create();
        $invitation = Invitation::factory()->for($user)->create();

        $this->assertSame(39900, $this->quotedGold($user, $invitation));
        $this->assertFalse(app(FirstPublishEligibility::class)->isEligible($user, $invitation->id));

        $user->email_verified_at = now();
        $user->save();

        $this->assertSame(15960, $this->quotedGold($user, $invitation));
        $this->assertTrue(app(FirstPublishEligibility::class)->isEligible($user, $invitation->id));
    }

    /** @return TestResponse<Response> */
    private function confirm(string $frontendLink): TestResponse
    {
        $this->forgetAuthState();

        return $this->postJson(route('public.email-verification.confirm').'?'.parse_url($frontendLink, PHP_URL_QUERY));
    }

    private function quotedGold(User $user, Invitation $invitation): int
    {
        $this->forgetAuthState();

        return (int) $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson(route('pricing.show', ['invitation' => $invitation->id]))
            ->assertOk()
            ->json('data.plans.1.totalMinor');
    }
}
