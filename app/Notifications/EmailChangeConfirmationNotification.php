<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Yeni adrese giden onay maili (Faz 14, 14.10). Türkçe, kuyruktan (15 sn kuralı
 * ve yanıt süresi adresin varlığını sızdırmasın).
 *
 * Bağlantı config'teki frontend adresinden kurulur, isteğin Host başlığından
 * DEĞİL (ResetPasswordNotification'daki "reset poisoning" savunması).
 */
final class EmailChangeConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url)
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
        $minutes = Config::integer('davetkart.auth.email_change_expire_minutes');

        return (new MailMessage)
            ->subject('DavetKart e-posta adresinizi onaylayın')
            ->greeting('Merhaba,')
            ->line('DavetKart hesabınızın e-posta adresini bu adresle değiştirmek istediniz.')
            ->action('Adresimi Onayla', $this->url)
            ->line("Bu bağlantı {$minutes} dakika boyunca geçerlidir. Onaylayana kadar eski adresiniz kullanılmaya devam eder.")
            ->line('Bu isteği siz yapmadıysanız bu e-postayı yok sayabilirsiniz.')
            ->salutation('Sevgiler, DavetKart');
    }
}
