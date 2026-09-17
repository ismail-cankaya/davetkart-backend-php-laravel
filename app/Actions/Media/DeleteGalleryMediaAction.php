<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Invitation;
use App\Models\Media;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sahibin galerisinden bir fotografi kaldirir: siradan cikarir, satiri ve
 * dosyayi siler.
 *
 * 🔴 Gorunurluk bir `if` degil, sorgunun KAPSAMIDIR (P3): dosya
 * `galleryMedia()` iliskisi UZERINDEN aranir. Baska davetiyenin dosyasi da, bu
 * davetiyedeki bir LCV fotografi da bu sorgudan hic cikamaz ve ikisi de ayni
 * 404'u alir (H7). LCV medyasi bir misafirin yanitina aittir; sahibin galeri
 * ucundan silinmesi o yaniti sessizce bozardi.
 *
 * 🔴 Kilit, yuklemeyle AYNI: StoreUploadedMediaAction diziye kilit altinda
 * ekliyor. Silme kilitsiz calissaydi es zamanli bir yukleme ile ayni diziyi
 * okuyup biri digerinin degisikligini ezerdi.
 *
 * 🔴 SIRA: once VERITABANI (dizi + satir, tek transaction), sonra DOSYA.
 * Dosya sistemi transaction'a dahil degildir (F3). Ters sirada transaction
 * patlasaydi galeri, dosyasi silinmis bir satiri misafire KIRIK GORSEL olarak
 * gosterirdi. Bu siradaki en kotu durum diskte kalan bir dosyadir: kimse bir
 * sey gormez. (PruneOrphanMedia'nin sirasi bunun tersidir ve o da dogrudur:
 * orada kimsenin gormedigi veri temizleniyor; izini kaybetmek en pahali hata.)
 * Ayrintili aciklama: docs/rehber/app/Actions/Media/DeleteGalleryMediaAction.md
 */
final class DeleteGalleryMediaAction
{
    /**
     * @param  string  $mediaId  URL'deki ULID
     *
     * @throws ModelNotFoundException Bu davetiyenin galerisinde yok -> 404
     */
    public function handle(Invitation $invitation, string $mediaId): void
    {
        $media = DB::transaction(function () use ($invitation, $mediaId): Media {
            $locked = Invitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $media = $locked->galleryMedia()->whereKey($mediaId)->firstOrFail();

            $locked->gallery_media_ids = array_values(array_filter(
                $locked->galleryMediaIds(),
                fn (string $id): bool => $id !== $media->id,
            ));
            $locked->save();

            // Dizi ile tablo ayrismissa (kimlik dizide yoksa) kayit "kirli"
            // olmaz ve `updated` olayi firlamazdi: misafir cache'i silinmis
            // dosyanin URL'ini TTL dolana kadar gostermeye devam ederdi.
            if (! $locked->wasChanged()) {
                $locked->touch();
            }

            $media->delete();

            return $media;
        });

        Storage::disk($media->disk)->delete($media->path);
    }
}
