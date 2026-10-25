<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * "Parolanız değiştirildi" uyarısı (Faz 14, 14.8). Türkçe, kuyruktan.
 *
 * Hesabı ele geçiren biri parolayı değiştirirse asıl sahibin haberi olmalı.
 * Bağlantı sıfırlama SAYFASINA gider, bir token taşımaz: maili ele geçiren
 * biri onunla hiçbir şey yapamaz.
 */
final class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
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
        $url = Config::string('davetkart.frontend.url').'/sifremi-unuttum';

        return (new MailMessage)
            ->subject('DavetKart parolanız değiştirildi')
            ->greeting('Merhaba,')
            ->line('Hesabınızın parolası az önce değiştirildi. Diğer cihazlardaki oturumlarınız kapatıldı.')
            ->line('Bu değişikliği siz yapmadıysanız hemen yeni bir parola belirleyin:')
            ->action('Parolamı Sıfırla', $url)
            ->salutation('Sevgiler, DavetKart');
    }
}
