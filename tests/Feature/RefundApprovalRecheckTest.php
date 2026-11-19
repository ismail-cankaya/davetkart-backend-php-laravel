<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Refund\ResolveRefundRequestAction;
use App\Enums\OrderStatus;
use App\Enums\RefundRequestStatus;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 18.4 (SEC-02 · K167): iade uygunluğu ONAY ANINDA yeniden sınanır (TOCTOU).
 *
 * Talep açılırken hak kullanılmamıştı; onaya kadar geçen sürede durum değişmiş
 * olabilir. Onay, talebin açıldığı ana değil KENDİ anına bakar.
 *
 * "Talep açıkken yayın" sırayla üretilemez (donmuş hak 402 alır); yalnızca
 * eşzamanlı iki istekle oluşur. Testler o sonucu satıra doğrudan yazar.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Refund/ResolveRefundRequestAction.md
 */
final class RefundApprovalRecheckTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRM = 'Parayı Shopier panelinden geri gönderdiniz mi?';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** 🔴 Talep açıkken hak bir davetiyeyi yayınladı (yarış): onay reddedilir. */
    #[Test]
    public function approval_is_refused_when_the_right_was_published_while_the_request_was_open(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->package()->create(['user_id' => $user->id]);
        $request = RefundRequest::factory()->forOrder($order)->create();

        // Donmuş hakkı aşan eşzamanlı yayının bıraktığı durum: bağlı ve damgalı.
        $invitation = Invitation::factory()->published()->create(['user_id' => $user->id]);
        DB::table('orders')->where('id', $order->id)->update([
            'invitation_id' => $invitation->id,
            'first_published_at' => now(),
        ]);

        $this->assertApprovalRefused($request, 'in_use');
        $this->assertNothingChanged($request, $order);
    }

    /**
     * 🔴 Talep, düzeltmeden ÖNCE açılmış olabilir (denetimin P47 senaryosu):
     * yayınlanıp serbest kalmış hakkın açık talebi onayda durur.
     */
    #[Test]
    public function approval_is_refused_for_a_released_right_that_had_published(): void
    {
        $order = Order::factory()->paid()->released()->publishedOnce()->create();
        $request = RefundRequest::factory()->forOrder($order)->create();

        $this->assertApprovalRefused($request, 'used');
        $this->assertNothingChanged($request, $order);
    }

    /** Komut, PARA SORUSUNDAN ÖNCE durur: kullanılmış hakkın parası gönderilmesin. */
    #[Test]
    public function the_approve_command_stops_before_asking_about_the_money(): void
    {
        $order = Order::factory()->paid()->released()->publishedOnce()->create();
        $request = RefundRequest::factory()->forOrder($order)->create();

        // Soru beklenmiyor: sorulsaydı test "beklenmeyen soru" diye düşerdi.
        $this->command('refunds:approve', ['id' => $request->id])
            ->expectsOutputToContain('GÖNDERMEYİN')
            ->assertFailed();

        $this->assertNothingChanged($request, $order);
    }

    /** Kullanılmış hakkın talebi ret ile kapanır; hak sahibinde kalır. */
    #[Test]
    public function a_request_for_a_used_right_can_still_be_rejected(): void
    {
        $order = Order::factory()->paid()->released()->publishedOnce()->create();
        $request = RefundRequest::factory()->forOrder($order)->create();

        $this->command('refunds:reject', ['id' => $request->id, '--note' => 'Hak kullanılmış'])->assertSuccessful();

        $this->assertSame(RefundRequestStatus::Rejected, $request->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    /** Para zaten geri gitmişse (Shopier bildirimi önce geldi) korunacak bir şey yok: talep kapanır. */
    #[Test]
    public function an_already_refunded_order_is_closed_even_if_the_right_was_used(): void
    {
        $order = Order::factory()->refunded()->released()->publishedOnce()->create();
        $request = RefundRequest::factory()->forOrder($order)->create();

        $this->command('refunds:approve', ['id' => $request->id])
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->assertSuccessful();

        $this->assertSame(RefundRequestStatus::Approved, $request->refresh()->status);
    }

    /** Regresyon (K139, K141): kullanılmamış hakkın onayı eskisi gibi çalışır. */
    #[Test]
    public function an_unused_right_is_still_approved(): void
    {
        $order = Order::factory()->paid()->package()->create();
        $request = RefundRequest::factory()->forOrder($order)->create();

        app(ResolveRefundRequestAction::class)->handle($request->id, RefundRequestStatus::Approved, null);

        $this->assertSame(RefundRequestStatus::Approved, $request->refresh()->status);
        $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);
    }

    private function assertApprovalRefused(RefundRequest $request, string $reason): void
    {
        // fail() try içinde çağrılmaz: PHPUnit'in hatası da bir RuntimeException'dır.
        $refusal = null;

        try {
            app(ResolveRefundRequestAction::class)->handle($request->id, RefundRequestStatus::Approved, null);
        } catch (RuntimeException $e) {
            $refusal = $e->getMessage();
        }

        $this->assertNotNull($refusal, 'Kullanılmış hakkın iadesi onaylanmamalıydı.');
        $this->assertStringContainsString('cannot be approved', $refusal);
        $this->assertStringContainsString("({$reason})", $refusal);
    }

    private function assertNothingChanged(RefundRequest $request, Order $order): void
    {
        $request->refresh();
        $this->assertSame(RefundRequestStatus::Requested, $request->status);
        $this->assertNull($request->resolved_at);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        Notification::assertNothingSent();
    }

    /** @param  array<string, mixed>  $parameters */
    private function command(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
