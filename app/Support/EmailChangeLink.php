<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * E-posta değişikliği onay bağlantısı — tablosuz, imzalı (Faz 14 · K144).
 *
 * Bağlantı üç değer taşır: kullanıcı, yeni adres ve ESKİ adresin parmak izi.
 * Laravel'in imzası (APP_KEY ile HMAC) üçünü ve son kullanma anını kilitler;
 * parmak izi bağlantıyı TEK KULLANIMLIK yapar: adres bir kez değişince eski
 * parmak izi artık eşleşmez ve aynı bağlantı ikinci kez çalışmaz.
 *
 * 🔴 İmza GÖRELİ (absolute: false): API'nin alan adı (APP_URL) frontend'in
 * gördüğünden farklı olabilir (proxy, CDN). Yol + sorgu imzalanır.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/RequestEmailChangeAction.md §3
 */
final class EmailChangeLink
{
    public const ROUTE = 'public.email-change.confirm';

    /** Frontend sayfasına giden tam bağlantı; sorgu kısmı API'ye aynen geri gönderilir. */
    public static function for(User $user, string $newEmail): string
    {
        $signed = URL::temporarySignedRoute(
            self::ROUTE,
            now()->addMinutes(Config::integer('davetkart.auth.email_change_expire_minutes')),
            [
                'user' => $user->id,
                'email' => $newEmail,
                'old' => self::fingerprint($user->email),
            ],
            absolute: false,
        );

        $query = (string) parse_url($signed, PHP_URL_QUERY);

        return Config::string('davetkart.frontend.url')
            .Config::string('davetkart.frontend.email_change_path')
            .'?'.$query;
    }

    /**
     * Adresin HMAC'i. Düz hash değil: bağlantıdaki değerden eski adres
     * sözlük saldırısıyla geri bulunamasın.
     */
    public static function fingerprint(string $email): string
    {
        return hash_hmac('sha256', $email, Config::string('app.key'));
    }
}
