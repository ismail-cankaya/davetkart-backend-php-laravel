<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Parola sifirlama maili — Turkce, kuyruktan, frontend'e giden bagla
 * (Faz 10, 10.34).
 *
 * Laravel'in ResetPassword'u uc yerde degisti:
 *
 *   1. Dil: `davetkart.mail.locale` (K96, Turkce). Sablonun hazir metinleri
 *      ("Hello!", alt not) lang/tr.json'dan gelir.
 *   2. Baglanti: Laravel `route('password.reset')` arar (bizde yok, saf API).
 *      Bunun yerine FRONTEND_URL/sifre-sifirla?token=…&email=…
 *   3. Kuyruk: ShouldQueue. 15 sn kurali, ve kayitli/kayitsiz e-posta
 *      yanit suresinden ayirt edilemesin (SendPasswordResetLinkAction).
 *
 * 🔴 Baglanti istegin Host basligindan URETILMEZ, config'ten gelir. Baslik
 * istemcinin elinde; saldirgan kendi alan adini yazip kurban adina sifirlama
 * isteseydi, kurbanin gelen kutusuna saldirganin sitesine giden bir baglanti
 * duserdi (password reset poisoning).
 *
 * Neden statik ResetPassword::createUrlUsing() degil? Baglanti ile mail
 * metni ayni sinifta durur ve ortada global bir statik durum kalmaz.
 * Ayrintili aciklama: docs/rehber/app/Notifications/ResetPasswordNotification.md
 */
final class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(#[SensitiveParameter] string $token)
    {
        parent::__construct($token);

        $this->locale(Config::string('davetkart.mail.locale'));
    }

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = Config::integer('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('DavetKart parola sıfırlama')
            ->greeting('Merhaba,')
            ->line('Hesabınız için bir parola sıfırlama isteği aldık.')
            ->action('Parolamı Sıfırla', $url)
            ->line("Bu bağlantı {$minutes} dakika boyunca geçerlidir ve yalnızca bir kez kullanılabilir.")
            ->line('Bu isteği siz yapmadıysanız bu e-postayı yok sayabilirsiniz; parolanız değişmez.')
            ->salutation('Sevgiler, DavetKart');
    }

    /**
     * @param  mixed  $notifiable
     */
    protected function resetUrl($notifiable): string
    {
        if (! $notifiable instanceof CanResetPassword) {
            throw new InvalidArgumentException('Password reset needs a CanResetPassword notifiable.');
        }

        $query = http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return Config::string('davetkart.frontend.url')
            .Config::string('davetkart.frontend.password_reset_path')
            .'?'.$query;
    }
}
