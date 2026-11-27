<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Events\InvitationChanged;
use App\Jobs\DeleteReplacedMediaFile;
use App\Jobs\OptimizedImage;
use App\Models\Invitation;
use App\Models\Media;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Küçülmüş dosyayı YENİ yola yazar, satırı davetiye kilidi altında oraya çevirir; eski dosya bekleme süresinden sonra silinir.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Media/SwapOptimizedMediaFileAction.md
 */
final class SwapOptimizedMediaFileAction
{
    public function handle(Media $media, OptimizedImage $optimized): void
    {
        $disk = $media->disk;
        $previousPath = $media->path;
        $newPath = 'media/'.$media->kind->value.'/'.Str::random(40).'.'.$optimized->extension;

        Storage::disk($disk)->put($newPath, $optimized->bytes);

        $invitation = DB::transaction(function () use ($media, $optimized, $previousPath, $newPath): ?Invitation {
            $invitation = Invitation::query()->whereKey($media->invitation_id)->lockForUpdate()->first();

            if ($invitation === null) {
                return null;
            }

            // Satır kilit ALTINDA yeniden okunur; yol değişmişse iş ikinci kez koşuyordur ("en az bir kez" teslim).
            $locked = $invitation->media()->whereKey($media->getKey())->first();

            if ($locked === null || $locked->path !== $previousPath) {
                return null;
            }

            $locked->path = $newPath;
            $locked->mime_type = $optimized->mimeType;
            $locked->size_bytes = $optimized->sizeBytes();
            $locked->optimized_at = now();
            $locked->save();

            return $invitation;
        });

        if ($invitation === null) {
            // 🔴 TELAFİ: diske yazma transaction'a dahil değil; satır gittiyse yazdığımız dosyayı elle sileriz.
            Storage::disk($disk)->delete($newPath);

            return;
        }

        // Misafir önbelleği galeri URL'lerini taşır; `touch()` değil olay (updated_at "son kaydetme" olarak görünür).
        if ($media->kind->isGalleryItem()) {
            event(new InvitationChanged($invitation));
        }

        // 🔴 Eski dosya HEMEN silinmez: editördeki açık sekme eski URL'i tutuyor (bekleme süresi sonunda silinir).
        DeleteReplacedMediaFile::dispatch($disk, $previousPath)
            ->delay(now()->addHours(Config::integer('davetkart.media.optimize.replaced_file_grace_hours')));
    }
}
