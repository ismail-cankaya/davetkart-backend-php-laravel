<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\RefundRequestStatus;
use App\Enums\SubscriptionTier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * İade talebinin sonucu kullanıcıya (Faz 14, 14.6). Türkçe, kuyruktan (15 sn kuralı).
 *
 * Model değil DEĞERLER taşınır: kuyruk işi çalıştığında sipariş ya da talep
 * değişmiş olabilir; mail kararın verildiği anı anlatmalı.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Refund/ResolveRefundRequestAction.md §4
 */
final class RefundRequestResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly RefundRequestStatus $decision,
        public readonly SubscriptionTier $tier,
        public readonly int $amountMinor,
        public readonly ?string $note,
    ) {
        $this->locale(Config::string('davetkart.mail.locale'));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = sprintf('%d,%02d ₺', intdiv($this->amountMinor, 100), $this->amountMinor % 100);
        $plan = $this->tier->label();

        $message = (new MailMessage)->greeting('Merhaba,');

        if ($this->decision === RefundRequestStatus::Approved) {
            $message->subject('DavetKart iade talebiniz onaylandı')
                ->line("{$plan} planı için yaptığınız {$amount} tutarındaki ödemenin iadesi onaylandı.")
                ->line('Tutar, bankanıza bağlı olarak birkaç iş günü içinde kartınıza yansır.');
        } else {
            $message->subject('DavetKart iade talebiniz hakkında')
                ->line("{$plan} planı için yaptığınız iade talebi onaylanmadı. Hakkınız hesabınızda kullanılabilir durumda.");
        }

        if ($this->note !== null && $this->note !== '') {
            $message->line('Not: '.$this->note);
        }

        return $message->salutation('Sevgiler, DavetKart');
    }
}
