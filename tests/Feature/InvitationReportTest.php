<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InvitationReportReason;
use App\Models\Invitation;
use App\Models\InvitationReport;
use App\Notifications\InvitationReportedNotification;
use App\Support\IpHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 25 (K313): misafirin "bu davetiyeyi bildir" ucu.
 * Ayrıntı: docs/rehber/tests/Feature/InvitationReportTest.md
 */
final class InvitationReportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_guest_reports_a_published_invitation(): void
    {
        Notification::fake();
        Config::set('davetkart.reports.notify_email', 'ops@example.test');
        $invitation = Invitation::factory()->published()->create();

        $this->postJson(route('public.invitations.report', $invitation), ['reason' => 'phishing', 'details' => 'IBAN istiyor'], ['REMOTE_ADDR' => '203.0.113.9'])
            ->assertNoContent();

        $report = InvitationReport::query()->sole();
        $this->assertSame($invitation->id, $report->invitation_id);
        $this->assertSame(InvitationReportReason::Phishing, $report->reason);
        $this->assertSame(IpHasher::hash('203.0.113.9'), $report->ip_hash);
        $this->assertDatabaseMissing('invitation_reports', ['ip_hash' => '203.0.113.9']);

        Notification::assertSentTo(new AnonymousNotifiable, InvitationReportedNotification::class, static function ($notification, array $channels, AnonymousNotifiable $notifiable): bool {
            return $notifiable->routes['mail'] === 'ops@example.test';
        });
    }

    #[Test]
    public function without_an_address_the_report_is_only_stored(): void
    {
        Notification::fake();
        Config::set('davetkart.reports.notify_email', null);
        $invitation = Invitation::factory()->published()->create();

        $this->postJson(route('public.invitations.report', $invitation), ['reason' => 'spam'])->assertNoContent();

        $this->assertDatabaseCount('invitation_reports', 1);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_draft_cannot_be_reported(): void
    {
        $invitation = Invitation::factory()->create();

        $this->postJson(route('public.invitations.report', $invitation), ['reason' => 'spam'])->assertNotFound();
        $this->assertDatabaseCount('invitation_reports', 0);
    }

    #[Test]
    public function an_unknown_reason_is_rejected(): void
    {
        $invitation = Invitation::factory()->published()->create();

        $this->postJson(route('public.invitations.report', $invitation), ['reason' => 'boring'])->assertStatus(422);
        $this->assertDatabaseCount('invitation_reports', 0);
    }

    #[Test]
    public function the_honeypot_swallows_the_report_silently(): void
    {
        $invitation = Invitation::factory()->published()->create();

        $this->postJson(route('public.invitations.report', $invitation), ['reason' => 'spam', 'website' => 'http://bot'])->assertNoContent();
        $this->assertDatabaseCount('invitation_reports', 0);
    }

    #[Test]
    public function deleting_the_invitation_deletes_its_reports(): void
    {
        $invitation = Invitation::factory()->published()->create();
        $this->postJson(route('public.invitations.report', $invitation), ['reason' => 'other'])->assertNoContent();

        $invitation->forceDelete();

        $this->assertDatabaseCount('invitation_reports', 0);
    }
}
