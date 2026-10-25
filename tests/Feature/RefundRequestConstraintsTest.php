<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RefundRequestStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * İade talebi tablosunun kısıtları (Faz 14, 14.2 · K138).
 *
 * Kurallar kodda DEĞİL veritabanında; testler kodu atlayıp doğrudan satır yazar.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/RefundRequestConstraintsTest.md
 */
final class RefundRequestConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /** 🔴 Bir siparişte iki AÇIK talep olamaz — eşzamanlı iki tıklamanın kalkanı. */
    #[Test]
    public function an_order_cannot_have_two_open_requests(): void
    {
        $order = Order::factory()->paid()->create();
        RefundRequest::factory()->forOrder($order)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        RefundRequest::factory()->forOrder($order)->create();
    }

    /** Kapanmış talep yenisini engellemez: reddedilen talep yeniden açılabilir. */
    #[Test]
    public function a_closed_request_does_not_block_a_new_one(): void
    {
        $order = Order::factory()->paid()->create();
        RefundRequest::factory()->forOrder($order)->rejected()->create();
        RefundRequest::factory()->forOrder($order)->withdrawn()->create();

        RefundRequest::factory()->forOrder($order)->create();

        $this->assertSame(3, $order->refundRequests()->count());
        $this->assertNotNull($order->openRefundRequest()->first());
    }

    #[Test]
    public function an_open_request_cannot_carry_a_decision_time(): void
    {
        $this->expectException(QueryException::class);

        RefundRequest::factory()->create(['resolved_at' => now()]);
    }

    #[Test]
    public function a_closed_request_must_carry_a_decision_time(): void
    {
        $this->expectException(QueryException::class);

        RefundRequest::factory()->create(['status' => RefundRequestStatus::Approved, 'resolved_at' => null]);
    }

    #[Test]
    public function an_unknown_status_is_refused(): void
    {
        $request = RefundRequest::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('refund_requests')->where('id', $request->id)->update(['status' => 'iptal']);
    }

    /** Hesap silinince talep kalır, sahibi boşalır — siparişle aynı karar (K97). */
    #[Test]
    public function deleting_the_user_anonymizes_the_request(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->paid()->create(['user_id' => $user->id]);
        $request = RefundRequest::factory()->forOrder($order)->create();

        $user->delete();

        $this->assertNull($request->refresh()->user_id);
    }

    #[Test]
    public function the_factory_copies_the_owner_from_the_order(): void
    {
        $request = RefundRequest::factory()->create();

        $this->assertSame(
            Order::query()->whereKey($request->order_id)->value('user_id'),
            $request->user_id,
        );
    }
}
