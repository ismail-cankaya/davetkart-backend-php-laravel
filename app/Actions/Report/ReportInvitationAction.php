<?php

declare(strict_types=1);

namespace App\Actions\Report;

use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\InvitationReport;
use App\Notifications\InvitationReportedNotification;
use App\Support\IpHasher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

/**
 * Misafirin "bu davetiyeyi bildir" isteği (Faz 25, K313): yalnızca yayındaki davetiye bildirilir,
 * IP hash'lenir, adres tanımlıysa yöneticiye kuyrukta posta gider. Ayrıntı: docs/rehber/app/Actions/Report/ReportInvitationAction.md
 */
final class ReportInvitationAction
{
    /** @param  array{reason: string, details?: string|null}  $attributes */
    public function handle(string $invitationId, array $attributes, string $ip, bool $honeypotTripped): void
    {
        $invitation = Invitation::query()
            ->whereKey(strtolower($invitationId))
            ->where('status', InvitationStatus::Published)
            ->firstOrFail();

        if ($honeypotTripped) {
            return;
        }

        $report = new InvitationReport($attributes);
        $report->invitation()->associate($invitation);
        $report->ip_hash = IpHasher::hash($ip);
        $report->save();

        $to = Config::get('davetkart.reports.notify_email');
        if (is_string($to) && $to !== '') {
            Notification::route('mail', $to)->notify(new InvitationReportedNotification($report));
        }
    }
}
