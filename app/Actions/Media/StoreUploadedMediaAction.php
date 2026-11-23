<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Enums\MediaKind;
use App\Exceptions\MediaQuotaExceededException;
use App\Exceptions\MediaStorageUnavailableException;
use App\Jobs\OptimizeUploadedImage;
use App\Models\Invitation;
use App\Models\Media;
use App\Support\MediaDiskGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Yuklenen dosyayi diske yazar ve kaydini olusturur.
 *
 * 🔴 Katmanli savunma (Faz 5, L1 — en ucuzdan pahaliya):
 *   0. Hiz siniri     -> rota katmani, Action'a hic gelmez
 *   1. Bicim/boyut    -> FormRequest (mimetypes = ICERIKTEN)
 *   2. Kota (on)      -> diske YAZMADAN once ucuz kontrol
 *   3. Kota (kesin)   -> kilitli transaction icinde (E9)
 *   4. Rastgele ad    -> orijinal ad kullanilmaz
 *   5. Icerikten MIME -> istemcinin beyani SAKLANMAZ
 *
 * 🔴 Galeri dosyasi, satiriyla AYNI kilitli transaction'da galerinin sonuna
 * eklenir (`gallery_media_ids`). Ayri bir adim olsaydi iki sey kirilirdi:
 *   - es zamanli iki yukleme ayni diziyi okuyup birbirinin ekini EZERDI,
 *   - yukleme ile sira kaydi arasinda sekme kapanirsa dosya kotayi yer ama
 *     galeride hic gorunmezdi.
 * Istemci sirayi GONDERMEZ; otomatik kaydetme bu diziye hic dokunmaz.
 *
 * Gorunurluk ve yetki bu Action'in isi DEGIL: sahibin ucunda Gate, misafirin
 * ucunda ResolvePublicInvitationAction karar verir ve buraya COZULMUS bir
 * davetiye gelir.
 * Ayrintili aciklama: docs/rehber/app/Actions/Media/StoreUploadedMediaAction.md
 */
final class StoreUploadedMediaAction
{
    public function __construct(private readonly MediaDiskGuard $diskGuard) {}

    /**
     * @throws MediaQuotaExceededException Bu turden kota doldu -> 403
     * @throws MediaStorageUnavailableException Üretimde disk s3 değil -> 503 (K183)
     */
    public function handle(Invitation $invitation, MediaKind $kind, UploadedFile $file): Media
    {
        // K183: sahip ve misafir yüklemesi buradan geçer; yanlış diske tek bayt yazılmaz.
        $this->diskGuard->assertUploadsAllowed();

        // 🔴 MIME ve BOYUT, dosya TASINMADAN once okunur.
        // store() gecici dosyayi TASIR; sonrasinda $file->getMimeType()
        // artik var olmayan bir yolu okumaya calisir. Bu, sirasi yanlis
        // yazildiginda "bazen calisan" bir hata uretir.
        $mimeType = $file->getMimeType();

        if ($mimeType === null) {
            // 'mimetypes:' kurali bunu zaten elerdi; sessiz bir '' yerine
            // gurultulu patliyoruz (uploadedFile()'daki ayni refleks).
            throw new RuntimeException('Uploaded file has no detectable MIME type.');
        }

        // 2. KATMAN — ucuz on kontrol. Kota zaten doluysa diske hic dokunmayiz.
        // Kesin kontrol asagida, kilit altinda; bu yalnizca bosa yazma-silme
        // dongusunu onler.
        $this->assertQuotaAvailable($invitation, $kind, (int) $file->getSize());

        $disk = Config::string('davetkart.media.disk');

        // 4. KATMAN — dosya adini SUNUCU uretir.
        // store() Str::random(40) + ICERIKTEN tahmin edilen uzanti kullanir;
        // orijinal ad hicbir yere yazilmaz. Kullanicinin verdigi ad
        // '../../.env' ya da 'kotu.php' olabilir — ikisi de imkansizlasir.
        $path = $file->store('media/'.$kind->value, ['disk' => $disk]);

        if (! is_string($path)) {
            throw new RuntimeException('Uploaded file could not be stored.');
        }

        try {
            // Diskteki GERCEK boyut. $file->getSize() tasima oncesi degeri
            // verirdi; ikisi normalde ayni ama tek dogru kaynak disktir.
            $sizeBytes = Storage::disk($disk)->size($path);

            $media = DB::transaction(function () use ($invitation, $kind, $disk, $path, $mimeType, $sizeBytes): Media {
                // 3. KATMAN — kesin kota. Es zamanli iki yukleme ayni sayiyi
                // okuyup ikisi de "yer var" diyebilirdi (check-then-act, E9).
                // Kilitli satir YENIDEN OKUNUR: galeri dizisine eklerken
                // elimizdeki (istek basinda okunan) ornegin dizisi bayat olabilir.
                $locked = $this->lockInvitation($invitation);
                $this->assertQuotaAvailable($locked, $kind, $sizeBytes);

                $media = $locked->media()->make();

                // #[Fillable] BOS: her alan acikca atanir (E7 ailesi).
                $media->kind = $kind;
                $media->disk = $disk;
                $media->path = $path;

                // 5. KATMAN — istemcinin BEYAN ETTIGI tip degil, dosyanin
                // ICERIGINDEN okunan tip saklanir.
                $media->mime_type = $mimeType;

                $media->size_bytes = $sizeBytes;

                $media->save();

                // 6. Galeri sirasi — ayni kilit altinda, sona. save() bir
                // `updated` olayi uretir; misafir cache'i commit'ten sonra
                // duser (ClearInvitationCache) ve yeni fotograf gorunur.
                if ($kind->isGalleryItem()) {
                    $locked->gallery_media_ids = [...$locked->galleryMediaIds(), $media->id];
                    $locked->save();
                }

                return $media;
            });
        } catch (Throwable $e) {
            // 🔴 TELAFI. Diske yazma transaction'a DAHIL DEGILDIR: rollback
            // dosyayi geri almaz. Kota kilidi ya da veritabani hatasi olursa
            // dosyayi elle sileriz, yoksa yetim kalirdi.
            Storage::disk($disk)->delete($path);

            throw $e;
        }

        // 15 saniye kurali: kucultme kuyrukta. COMMIT'TEN SONRA gonderiliyor —
        // transaction icinde gonderilseydi is, satiri henuz gormeden kosabilirdi.
        if ($kind->isOptimizable()) {
            OptimizeUploadedImage::dispatch($media);
        }

        return $media;
    }

    /**
     * Bu davetiyede bu turden kac dosya var?
     *
     * COUNT(*) DOGRU metrik: sinir 'kac dosya', 'kac bayt' degil. (Faz 5'in
     * LCV kotasi bunun tersiydi — orada sinir misafir SAYISIYDI ve SUM
     * gerekiyordu. Metrigi sinirin tanimi belirler, aliskanlik degil.)
     */
    private function assertQuotaAvailable(Invitation $invitation, MediaKind $kind, int $incomingBytes): void
    {
        if ($kind->isGuestUploadable()) {
            $this->assertGuestBudgetAvailable($invitation, $kind, $incomingBytes);

            return;
        }

        $limit = $kind->maxPerInvitation();
        $used = $invitation->media()->where('kind', $kind)->count();

        if ($used < $limit) {
            return;
        }

        // H9: sinir yalnizca SAHIBE soylenir; misafir kolu forGuest() ile asagida.
        throw MediaQuotaExceededException::forOwner($limit);
    }

    /**
     * K190 (SEC-08): misafirin üç sınırı. Adet ve bayt yalnızca LCV'ye İLİŞTİRİLMİŞ
     * dosyaları sayar; iliştirilmemişler kendi küçük kotasına girer (yetim DoS'u).
     * Ayrıntı: kılavuz §Faz 19. Sınır misafire söylenmez (H9).
     */
    private function assertGuestBudgetAvailable(Invitation $invitation, MediaKind $kind, int $incomingBytes): void
    {
        $guestMedia = fn () => $invitation->media()->whereIn('kind', MediaKind::guestUploadableValues());

        $attachedOfKind = $guestMedia()->where('kind', $kind)->attachedToRsvp()->count();
        $attachedBytes = (int) $guestMedia()->attachedToRsvp()->sum('size_bytes');
        $pendingFiles = $guestMedia()->awaitingRsvp()->count();
        $pendingBytes = (int) $guestMedia()->awaitingRsvp()->sum('size_bytes');

        $exceeded = $attachedOfKind >= $kind->maxPerInvitation()
            || $attachedBytes + $incomingBytes > Config::integer('davetkart.media.guest_budget.attached_bytes_per_invitation')
            || $pendingFiles >= Config::integer('davetkart.media.guest_budget.pending_files_per_invitation')
            || $pendingBytes + $incomingBytes > Config::integer('davetkart.media.guest_budget.pending_bytes_per_invitation');

        if ($exceeded) {
            throw MediaQuotaExceededException::forGuest();
        }
    }

    /**
     * Ust kaydin satirini kilitler ve kilitli, TAZE ornegi dondurur.
     *
     * PostgreSQL'in varsayilan READ COMMITTED seviyesinde SELECT'ler birbirini
     * beklemez; var olmayan satirlar da kilitlenemez (phantom read). Bu yuzden
     * kilitlenebilecek tek ortak nesne UST KAYITTIR — Faz 5'teki kota kilidiyle
     * ayni desen.
     *
     * firstOrFail: davetiye bu arada silinmisse 404 olur ve cagiran taraftaki
     * telafi diske yazilan dosyayi siler.
     */
    private function lockInvitation(Invitation $invitation): Invitation
    {
        return Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
    }
}
