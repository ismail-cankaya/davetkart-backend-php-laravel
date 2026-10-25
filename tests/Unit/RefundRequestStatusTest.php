<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\RefundRequestStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * İade talebi durum makinesi (Faz 14, 14.2).
 * Ayrıntılı açıklama: docs/rehber/app/Enums/RefundRequestStatus.md
 */
final class RefundRequestStatusTest extends TestCase
{
    #[Test]
    public function only_the_requested_status_is_open(): void
    {
        foreach (RefundRequestStatus::cases() as $status) {
            $this->assertSame($status === RefundRequestStatus::Requested, $status->isOpen(), $status->value);
        }
    }

    #[Test]
    public function only_an_open_request_can_close(): void
    {
        $open = RefundRequestStatus::Requested;

        $this->assertTrue($open->canTransitionTo(RefundRequestStatus::Approved));
        $this->assertTrue($open->canTransitionTo(RefundRequestStatus::Rejected));
        $this->assertTrue($open->canTransitionTo(RefundRequestStatus::Withdrawn));
        $this->assertFalse($open->canTransitionTo(RefundRequestStatus::Requested));

        foreach ([RefundRequestStatus::Approved, RefundRequestStatus::Rejected, RefundRequestStatus::Withdrawn] as $closed) {
            foreach (RefundRequestStatus::cases() as $next) {
                $this->assertFalse($closed->canTransitionTo($next), "{$closed->value} -> {$next->value}");
            }
        }
    }
}
