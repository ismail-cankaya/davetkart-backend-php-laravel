<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\EmailChangeConfirmationNotification;
use App\Support\EmailChangeLink;
use Illuminate\Support\Facades\Notification;

/**
 * Yeni adrese bir onay bağlantısı gönderir; adres HENÜZ değişmez (Faz 14, 14.10 · K144).
 *
 * 🔴 Bu Action veritabanına HİÇ bakmaz — adres başka bir hesapta kayıtlı olsa
 * da olmasa da aynı yolu izler, aynı sürede biter ve aynı 202'yi döndürür.
 * Kontrol yapılsaydı yanıt süresi bile adresin kayıtlı olup olmadığını
 * sızdırırdı (LoginUserAction'ın zamanlama savunmasıyla aynı aile).
 *
 * Tablo yok: bekleyen değişiklik bağlantının kendisinde, imzalı (EmailChangeLink).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/RequestEmailChangeAction.md
 */
final class RequestEmailChangeAction
{
    public function handle(User $user, string $newEmail): void
    {
        // Yeni adres henüz bir kullanıcı değil: on-demand bildirim.
        Notification::route('mail', $newEmail)
            ->notify(new EmailChangeConfirmationNotification(EmailChangeLink::for($user, $newEmail)));
    }
}
