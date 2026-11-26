<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * "Hesabınıza çok sayıda hatalı giriş denendi" uyarısı (SEC-16, K265): kuyruktan, Türkçe, IP'siz, saatte en çok bir.
 * Ayrıntılı açıklama: docs/rehber/app/Notifications/SuspiciousLoginAttemptsNotification.md
 */
final class SuspiciousLoginAttemptsNotification extends Notification implements ShouldQueue
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
            ->subject('DavetKart hesabınıza çok sayıda hatalı giriş denendi')
            ->greeting('Merhaba,')
            ->line('Son bir saat içinde hesabınıza hatalı parolayla çok sayıda giriş denendi. Güvenliğiniz için bu adresle girişler bir süre durduruldu; açık oturumlarınız etkilenmedi.')
            ->line('Bu denemeleri siz yapmadıysanız parolanızı yenileyin. Parolanızı yenilediğinizde giriş hemen yeniden açılır:')
            ->action('Parolamı Yenile', $url)
            ->line('Denemeleri siz yaptıysanız en geç bir saat sonra yeniden giriş yapabilirsiniz.')
            ->salutation('Sevgiler, DavetKart');
    }
}
