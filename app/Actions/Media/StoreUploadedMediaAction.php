<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Enums\MediaKind;
use App\Exceptions\MediaQuotaExceededException;
use App\Jobs\OptimizeUploadedImage;
use App\Models\Invitation;
use App\Models\Media;
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
 * Gorunurluk ve yetki bu Action'in isi DEGIL: sahibin ucunda Gate, misafirin
 * ucunda ResolvePublicInvitationAction karar verir ve buraya COZULMUS bir
 * davetiye gelir.
 * Ayrintili aciklama: docs/rehber/app/Actions/Media/StoreUploadedMediaAction.md
 */
final class StoreUploadedMediaAction
{
    /**
     * @throws MediaQuotaExceededException Bu turden kota doldu -> 403
     */
    public function handle(Invitation $invitation, MediaKind $kind, UploadedFile $file): Media
    {
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
        $this->assertQuotaAvailable($invitation, $kind);

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
            $media = DB::transaction(function () use ($invitation, $kind, $disk, $path, $mimeType): Media {
                // 3. KATMAN — kesin kota. Es zamanli iki yukleme ayni sayiyi
                // okuyup ikisi de "yer var" diyebilirdi (check-then-act, E9).
                $this->lockInvitation($invitation);
                $this->assertQuotaAvailable($invitation, $kind);

                $media = $invitation->media()->make();

                // #[Fillable] BOS: her alan acikca atanir (E7 ailesi).
                $media->kind = $kind;
                $media->disk = $disk;
                $media->path = $path;

                // 5. KATMAN — istemcinin BEYAN ETTIGI tip degil, dosyanin
                // ICERIGINDEN okunan tip saklanir.
                $media->mime_type = $mimeType;

                // Diskteki GERCEK boyut. $file->getSize() tasima oncesi degeri
                // verirdi; ikisi normalde ayni ama tek dogru kaynak disktir.
                $media->size_bytes = Storage::disk($disk)->size($path);

                $media->save();

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
    private function assertQuotaAvailable(Invitation $invitation, MediaKind $kind): void
    {
        $limit = $kind->maxPerInvitation();
        $used = $invitation->media()->where('kind', $kind)->count();

        if ($used < $limit) {
            return;
        }

        // H9: sinir yalnizca SAHIBE soylenir. Misafirin yukledigi turlerde
        // ic sayac disari cikmaz — ve kurucu private oldugu icin bu
        // yanlislikla degistirilemez (6.5).
        throw $kind->isGuestUploadable()
            ? MediaQuotaExceededException::forGuest()
            : MediaQuotaExceededException::forOwner($limit);
    }

    /**
     * Ust kaydin satirini kilitler.
     *
     * PostgreSQL'in varsayilan READ COMMITTED seviyesinde SELECT'ler birbirini
     * beklemez; var olmayan satirlar da kilitlenemez (phantom read). Bu yuzden
     * kilitlenebilecek tek ortak nesne UST KAYITTIR — Faz 5'teki kota kilidiyle
     * ayni desen.
     */
    private function lockInvitation(Invitation $invitation): void
    {
        Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();
    }
}
