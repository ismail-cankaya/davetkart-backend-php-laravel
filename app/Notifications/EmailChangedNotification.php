<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * ESKİ adrese "e-posta adresiniz değişti" uyarısı (Faz 14, 14.10 · K144).
 *
 * Yeni adres MASKELİ yazılır (y***@gmail.com): asıl sahip hangi adrese
 * geçildiğini tanıyabilsin, ama eski kutuya erişen üçüncü biri yeni adresi
 * tam öğrenmesin.
 */
final class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $newEmail)
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
        return (new MailMessage)
            ->subject('DavetKart e-posta adresiniz değiştirildi')
            ->greeting('Merhaba,')
            ->line('Hesabınızın e-posta adresi '.self::mask($this->newEmail).' olarak değiştirildi. Bütün oturumlarınız kapatıldı.')
            ->line('Bu değişikliği siz yapmadıysanız hemen bizimle iletişime geçin.')
            ->action('İletişim', Config::string('davetkart.frontend.url').'/contact')
            ->salutation('Sevgiler, DavetKart');
    }

    /** "yeni.adres@gmail.com" -> "y***@gmail.com" */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
