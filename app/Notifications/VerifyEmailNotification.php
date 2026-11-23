<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/** Kayıt sonrası doğrulama postası (K191). Kuyrukta; bağlantı EmailVerificationLink'ten. */
final class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url)
    {
        $this->locale(Config::string('davetkart.mail.locale'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hours = intdiv(Config::integer('davetkart.auth.email_verification_expire_minutes'), 60);

        return (new MailMessage)
            ->subject('DavetKart e-posta adresinizi doğrulayın')
            ->greeting('Merhaba,')
            ->line('DavetKart hesabınızı oluşturduğunuz için teşekkürler. Davetiyenizi yayınlayabilmek için e-posta adresinizi doğrulayın.')
            ->action('Adresimi Doğrula', $this->url)
            ->line("Bu bağlantı {$hours} saat boyunca geçerlidir. Süresi dolarsa hesabınızdan yeni bağlantı isteyebilirsiniz.")
            ->line('Bu hesabı siz açmadıysanız bu e-postayı yok sayabilirsiniz.')
            ->salutation('Sevgiler, DavetKart');
    }
}
