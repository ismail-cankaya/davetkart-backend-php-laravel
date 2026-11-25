<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Invitation;
use App\Models\Media;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

/**
 * Hesabi siler: kisisel veri gider, odeme kaydi anonim kalir (Faz 10, 10.39 · K97).
 * Ayrintili aciklama: docs/rehber/app/Actions/Auth/DeleteAccountAction.md §A-E5-16
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
            // Ayrintili aciklama: docs/rehber/app/Actions/Auth/DeleteAccountAction.md §A-E5-57
            foreach ($invitations as $invitation) {
                $invitation->forceDelete();
            }

            // Bekleyen bir sifirlama baglantisi hesap silindikten sonra
            // calismamali; tablo e-postayi (kisisel veri) da tutuyor.
            Password::broker()->deleteToken($user);

            $user->tokens()->delete();

            // K262 (A-E5): sipariş anonim muhasebe kaydı olarak kalır; telefon kalsaydı anonim olmazdı.
            Order::query()->where('user_id', $user->id)->update(['buyer_phone' => null]);

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
