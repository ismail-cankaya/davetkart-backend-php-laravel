<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Account\SummarizeEntitlementsAction;
use App\Enums\EntitlementState;
use App\Enums\OrderStatus;
use App\Enums\RefundRequestStatus;
use App\Enums\SubscriptionTier;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Notifications\RefundRequestResolvedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * refunds:list / approve / reject (Faz 14, 14.6 · K141).
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/RefundCommandsTest.md
 */
final class RefundCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRM = 'Parayı Shopier panelinden geri gönderdiniz mi?';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    #[Test]
    public function the_list_shows_only_open_requests_by_default(): void
    {
        $open = RefundRequest::factory()->create(['reason' => "Ekranı sil \e[2J"]);
        $closed = RefundRequest::factory()->rejected()->create();

        // Bir çıktı satırı yalnızca BİR beklentiyi karşılar (Mockery): kimlik ve
        // açıklama aynı tablo satırında, bu yüzden iki ayrı çalıştırma.
        $this->command('refunds:list')
            ->expectsOutputToContain($open->id)
            ->doesntExpectOutputToContain($closed->id)
            ->assertSuccessful();

        $this->command('refunds:list')
            ->expectsOutputToContain('Ekranı sil \u001b[2J')
            ->doesntExpectOutputToContain("\e[2J")
            ->assertSuccessful();

        $this->command('refunds:list', ['--all' => true])
            ->expectsOutputToContain($closed->id)
            ->assertSuccessful();
    }

    #[Test]
    public function an_empty_queue_says_so(): void
    {
        $this->command('refunds:list')->expectsOutput('Karar bekleyen talep yok.')->assertSuccessful();
    }

    #[Test]
    public function approving_refunds_the_order_and_mails_the_user(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->tier(SubscriptionTier::Gold)->package()->create(['user_id' => $user->id]);
        $request = RefundRequest::factory()->forOrder($order)->create();

        $this->command('refunds:approve', ['id' => $request->id, '--note' => 'İyi günlerde kullanın'])
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->assertSuccessful();

        $request->refresh();
        $this->assertSame(RefundRequestStatus::Approved, $request->status);
        $this->assertSame('İyi günlerde kullanın', $request->admin_note);
        $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);

        // Hak sayacı: 1 Gold iade edildi, kullanılabilir 0.
        $gold = app(SummarizeEntitlementsAction::class)->handle($user)->forTier(SubscriptionTier::Gold);
        $this->assertSame(1, $gold->count(EntitlementState::Refunded));
        $this->assertSame(0, $gold->count(EntitlementState::Available));

        Notification::assertSentTo(
            $user,
            RefundRequestResolvedNotification::class,
            static fn (RefundRequestResolvedNotification $n): bool => $n->decision === RefundRequestStatus::Approved
                && $n->amountMinor === $order->amount_minor,
        );
    }

    /** 🔴 "Hayır" (ve etkileşimsiz çalıştırma) hiçbir şeyi değiştirmez. */
    #[Test]
    public function approving_without_confirmation_changes_nothing(): void
    {
        $request = RefundRequest::factory()->create();

        $this->command('refunds:approve', ['id' => $request->id])
            ->expectsConfirmation(self::CONFIRM, 'no')
            ->assertFailed();

        $this->assertSame(RefundRequestStatus::Requested, $request->refresh()->status);
        $this->assertSame(OrderStatus::Paid, Order::query()->findOrFail($request->order_id)->status);
        Notification::assertNothingSent();
    }

    /** Shopier'in iade bildirimi önce geldiyse sipariş zaten refunded: onay yine kapanır. */
    #[Test]
    public function approving_an_already_refunded_order_only_closes_the_request(): void
    {
        $order = Order::factory()->refunded()->package()->create();
        $request = RefundRequest::factory()->forOrder($order)->create();

        $this->command('refunds:approve', ['id' => $request->id])
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->assertSuccessful();

        $this->assertSame(RefundRequestStatus::Approved, $request->refresh()->status);
    }

    #[Test]
    public function rejecting_gives_the_right_back(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id]);
        $request = RefundRequest::factory()->forOrder($order)->create();

        $this->command('refunds:reject', ['id' => $request->id, '--note' => 'Süre doldu'])->assertSuccessful();

        $this->assertSame(RefundRequestStatus::Rejected, $request->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(1, app(SummarizeEntitlementsAction::class)->handle($user)->totalAvailable());

        Notification::assertSentTo(
            $user,
            RefundRequestResolvedNotification::class,
            static fn (RefundRequestResolvedNotification $n): bool => $n->decision === RefundRequestStatus::Rejected
                && $n->note === 'Süre doldu',
        );
    }

    #[Test]
    public function a_closed_request_cannot_be_resolved_again(): void
    {
        $request = RefundRequest::factory()->withdrawn()->create();

        $this->command('refunds:reject', ['id' => $request->id])
            ->expectsOutputToContain('already withdrawn')
            ->assertFailed();

        Notification::assertNothingSent();
    }

    #[Test]
    public function an_unknown_request_fails(): void
    {
        $this->command('refunds:reject', ['id' => '01jbadbadbadbadbadbadbadba'])->assertFailed();
    }

    /** Hesabı silinmiş kullanıcı: talep yine kapanır, mail gidecek kimse yok. */
    #[Test]
    public function an_anonymous_request_is_resolved_without_mail(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id]);
        $request = RefundRequest::factory()->forOrder($order)->create();
        $user->delete();

        $this->command('refunds:reject', ['id' => $request->id])->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_mail_reads_in_turkish_with_the_amount(): void
    {
        $mail = (new RefundRequestResolvedNotification(RefundRequestStatus::Approved, SubscriptionTier::Gold, 15960, null))
            ->toMail(User::factory()->make());

        $this->assertSame('DavetKart iade talebiniz onaylandı', $mail->subject);
        $this->assertStringContainsString('159,60 ₺', implode(' ', $mail->introLines));
    }

    /** @param  array<string, mixed>  $parameters */
    private function command(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
