<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Account\ConfirmEmailChangeAction;
use App\Actions\GiftCode\ReserveGiftCodeAction;
use App\Actions\Invitation\DeleteInvitationAction;
use App\Actions\Invitation\PublishInvitationAction;
use App\Actions\Invitation\UpdateInvitationAction;
use App\Actions\Invitation\WithdrawUncoveredInvitationAction;
use App\Actions\Media\DeleteGalleryMediaAction;
use App\Actions\Media\StoreUploadedMediaAction;
use App\Actions\Payment\ClaimReleasedOrderAction;
use App\Actions\Payment\HandlePaymentCallbackAction;
use App\Actions\Refund\RequestRefundAction;
use App\Actions\Refund\ResolveRefundRequestAction;
use App\Actions\Refund\WithdrawRefundRequestAction;
use App\Actions\Rsvp\EnsureRsvpQuotaAction;
use App\Enums\MediaKind;
use App\Enums\OrderStatus;
use App\Enums\RefundRequestStatus;
use App\Enums\SubscriptionTier;
use App\Jobs\OptimizeUploadedImage;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Payment\PaymentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LockAssertions;
use Tests\TestCase;

/**
 * TST-02 · K187: her `lockForUpdate()` için SQL düzeyi test (envanter kılavuzda).
 * StartCheckoutAction'ın kilidi Faz 18.3'ten beri FirstPublishCheckoutTest'te sınanıyor.
 * Ayrintili aciklama: docs/rehber/tests/Support/LockAssertions.md
 */
final class LockInventoryTest extends TestCase
{
    use LockAssertions;
    use RefreshDatabase;

    #[Test]
    public function confirming_an_email_change_locks_the_user_row(): void
    {
        $user = User::factory()->create();

        $this->assertLocksRowOf('users', fn () => app(ConfirmEmailChangeAction::class)->handle([
            'user_id' => $user->id,
            'email' => 'yeni-adres@example.com',
            'old_fingerprint' => 'eski',
        ]));
    }

    #[Test]
    public function reserving_a_gift_code_locks_the_code_row(): void
    {
        $user = User::factory()->create();

        $this->assertLocksRowOf('gift_codes', fn () => DB::transaction(
            fn () => app(ReserveGiftCodeAction::class)->handle($user, 'LOCKTEST1', SubscriptionTier::Standart),
        ));
    }

    #[Test]
    public function deleting_an_invitation_locks_the_invitation_row(): void
    {
        $invitation = Invitation::factory()->create();

        $this->assertLocksRowOf('invitations', fn () => app(DeleteInvitationAction::class)->handle($invitation));
    }

    #[Test]
    public function publishing_an_invitation_locks_the_invitation_row(): void
    {
        $invitation = Invitation::factory()->create();

        $this->assertLocksRowOf('invitations', fn () => app(PublishInvitationAction::class)->handle($invitation));
    }

    #[Test]
    public function updating_an_invitation_locks_the_invitation_row(): void
    {
        $invitation = Invitation::factory()->create();

        $this->assertLocksRowOf('invitations', fn () => app(UpdateInvitationAction::class)->handle($invitation, [], null));
    }

    #[Test]
    public function withdrawing_an_uncovered_invitation_locks_the_invitation_row(): void
    {
        $invitation = Invitation::factory()->published()->create();

        $this->assertLocksRowOf('invitations', fn () => app(WithdrawUncoveredInvitationAction::class)->handle($invitation->id));
    }

    #[Test]
    public function deleting_a_gallery_photo_locks_the_invitation_row(): void
    {
        $invitation = Invitation::factory()->create();

        $this->assertLocksRowOf('invitations', fn () => app(DeleteGalleryMediaAction::class)->handle($invitation, (string) Str::ulid()));
    }

    #[Test]
    public function storing_an_upload_locks_the_invitation_row(): void
    {
        Storage::fake(Config::string('davetkart.media.disk'));
        $invitation = Invitation::factory()->create();

        $this->assertLocksRowOf('invitations', fn () => app(StoreUploadedMediaAction::class)
            ->handle($invitation, MediaKind::Gallery, UploadedFile::fake()->image('foto.jpg')));
    }

    /** K190 (SEC-08): misafir bayt bütçesi davetiye satırı kilitlendikten SONRA okunur. */
    #[Test]
    public function the_guest_byte_budget_is_read_under_the_invitation_lock(): void
    {
        Storage::fake(Config::string('davetkart.media.disk'));
        $invitation = Invitation::factory()->create();
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        app(StoreUploadedMediaAction::class)
            ->handle($invitation, MediaKind::RsvpPhoto, UploadedFile::fake()->image('ani.jpg'));

        $lock = array_key_first(array_filter($sql, fn (string $q): bool => str_contains($q, 'from "invitations"') && str_contains($q, 'for update')));
        $this->assertNotNull($lock, 'Davetiye satırı kilitlenmedi.');
        $sumsAfterLock = array_filter($sql, fn (string $q, int $i): bool => $i > $lock && str_contains($q, 'sum("size_bytes")'), ARRAY_FILTER_USE_BOTH);
        $this->assertCount(2, $sumsAfterLock, 'İliştirilmiş ve bekleyen bayt toplamları kilit altında okunmalı.');
    }

    #[Test]
    public function swapping_an_optimized_image_locks_the_invitation_row(): void
    {
        $disk = Config::string('davetkart.media.disk');
        Storage::fake($disk);
        $image = imagecreatetruecolor(2600, 40);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        $path = 'media/gallery/'.Str::random(40).'.jpg';
        Storage::disk($disk)->put($path, $bytes);
        $media = Media::factory()->create([
            'invitation_id' => Invitation::factory(),
            'kind' => MediaKind::Gallery,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($bytes),
        ]);

        $this->assertLocksRowOf('invitations', fn () => (new OptimizeUploadedImage($media))->handle());
    }

    #[Test]
    public function claiming_a_released_order_locks_the_order_rows(): void
    {
        $invitation = Invitation::factory()->create();

        $this->assertLocksRowOf('orders', fn () => DB::transaction(
            fn () => app(ClaimReleasedOrderAction::class)->handle($invitation, SubscriptionTier::Standart),
        ));
    }

    #[Test]
    public function a_payment_notification_locks_the_order_row(): void
    {
        $order = Order::factory()->create(['provider_ref' => 'lock-test-ref']);

        $this->assertLocksRowOf('orders', fn () => app(HandlePaymentCallbackAction::class)
            ->handle(new PaymentNotification('lock-test-ref', OrderStatus::Paid)));
    }

    #[Test]
    public function requesting_a_refund_locks_the_order_row(): void
    {
        $order = Order::factory()->paid()->create();

        $this->assertLocksRowOf('orders', fn () => app(RequestRefundAction::class)->handle($order, null));
    }

    #[Test]
    public function resolving_a_refund_request_locks_the_request_and_the_order(): void
    {
        Mail::fake();
        Notification::fake();
        $request = RefundRequest::factory()->forOrder(Order::factory()->paid()->create())->create();
        $resolve = fn () => app(ResolveRefundRequestAction::class)->handle($request->id, RefundRequestStatus::Rejected, null);

        $this->assertLocksRowOf('refund_requests', $resolve);

        $request = RefundRequest::factory()->forOrder(Order::factory()->paid()->create())->create();
        $this->assertLocksRowOf('orders', fn () => app(ResolveRefundRequestAction::class)->handle($request->id, RefundRequestStatus::Rejected, null));
    }

    #[Test]
    public function withdrawing_a_refund_request_locks_the_request_row(): void
    {
        $order = Order::factory()->paid()->create();
        RefundRequest::factory()->forOrder($order)->create();

        $this->assertLocksRowOf('refund_requests', fn () => app(WithdrawRefundRequestAction::class)->handle($order));
    }

    #[Test]
    public function checking_the_rsvp_quota_locks_the_invitation_row(): void
    {
        $invitation = Invitation::factory()->published()->create();
        Order::factory()->tier(SubscriptionTier::Standart)->forInvitation($invitation)->paid()->create();

        $this->assertLocksRowOf('invitations', fn () => app(EnsureRsvpQuotaAction::class)->handle($invitation, 1));
    }
}
