<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Invitation;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

/**
 * Hesabi siler: kisisel veri gider, odeme kaydi anonim kalir (Faz 10, 10.39 · K97).
 *
 * 🔴 Silme YALNIZCA buradan, VERITABANININ cascade'ine birakilmadan yapilir
 * (plan tuzak #8). `users` satiri silinince FK'ler davetiyeleri, LCV'leri ve
 * medya SATIRLARINI kendiliginden siler, ama:
 *   - dosyalar diskte KALIR (bir veritabani kisiti diski bilmez),
 *   - model olaylari ATESLENMEZ: InvitationChanged -> ClearInvitationCache
 *     hic calismaz ve silinmis bir davetiye cache TTL'i dolana kadar
 *     (6 saat) misafire acik kalir.
 *
 * Sira:
 *   1. Transaction: dosya yollarini topla -> davetiyeleri MODEL uzerinden
 *      kalici sil (olaylar ateslenir) -> sifirlama token'larini, oturumlari
 *      ve kullaniciyi sil. Siparisler FK ile `user_id = NULL` olur (10.38).
 *   2. Commit'ten SONRA dosyalari sil.
 *
 * Neden dosyalar sonra? Ters sirada transaction basarisiz olursa, hesabi HALA
 * VAR olan bir kullanicinin fotograflari silinmis olurdu. Bu sirada en kotu
 * durum diskte sahipsiz bir dosyadir: veri kaybi degil, yer kaybi (loglanir).
 * Ayrintili aciklama: docs/rehber/app/Actions/Auth/DeleteAccountAction.md
 */
final class DeleteAccountAction
{
    public function handle(User $user): void
    {
        /** @var array<string, list<string>> $files disk -> yollar */
        $files = [];

        DB::transaction(function () use ($user, &$files): void {
            $invitations = Invitation::withTrashed()->where('user_id', $user->id)->get();

            // Galeri ve misafir (LCV) yuklemeleri: ikisi de davetiyeye bagli.
            $media = Media::query()
                ->whereIn('invitation_id', $invitations->modelKeys())
                ->get(['disk', 'path']);

            foreach ($media as $item) {
                $files[$item->disk][] = $item->path;
            }

            // MODEL uzerinden: `deleted` olayi -> public cache temizligi (K48).
            // forceDelete: SoftDeletes'in cop kutusu hesapla birlikte bosalir.
            // Satirin FK'leri LCV'leri, program adimlarini ve medya satirlarini
            // siler; siparislerin invitation_id'si NULL olur.
            foreach ($invitations as $invitation) {
                $invitation->forceDelete();
            }

            // Bekleyen bir sifirlama baglantisi hesap silindikten sonra
            // calismamali; tablo e-postayi (kisisel veri) da tutuyor.
            Password::broker()->deleteToken($user);

            $user->tokens()->delete();

            // FK'ler: orders.user_id -> NULL (anonim muhasebe kaydi, K97),
            // assistant_usages -> silinir.
            $user->delete();
        });

        foreach ($files as $disk => $paths) {
            if (! Storage::disk($disk)->delete($paths)) {
                // Satirlar gitti, dosyalar kaldi: veri kaybi degil, yer kaybi.
                // Sahipsiz dosyanin yolu kayitli tek yer burasi.
                Log::warning('Account deleted but some media files could not be removed', [
                    'disk' => $disk,
                    'paths' => $paths,
                ]);
            }
        }
    }
}
