<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InvitationReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Yöneticiye "bir davetiye bildirildi" postası (Faz 25, K313). Kuyrukta gider (15 saniye kuralı);
 * IP ve misafir kimliği yoktur. Ayrıntı: docs/rehber/app/Notifications/InvitationReportedNotification.md
 */
final class InvitationReportedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly InvitationReport $report) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('DavetKart: davetiye bildirildi ('.$this->report->reason->value.')')
            ->line('Davetiye: '.$this->report->invitation_id)
            ->line('Neden: '.$this->report->reason->value)
            ->line('Ayrıntı: '.($this->report->details ?? '—'));
    }
}
