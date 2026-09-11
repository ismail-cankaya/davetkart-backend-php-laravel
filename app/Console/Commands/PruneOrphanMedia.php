<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaKind;
use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Hicbir LCV yanitina baglanmamis misafir yuklemelerini siler.
 *
 * 🔴 Faz 6 bu deligi GORMUS ve yalnizca YAVASLATMISTI (config/davetkart.php):
 *   "Bu sinir olmasa gonderim yapmadan yuklenen 'yetim' dosyalarla disk
 *    doldurulabilirdi."
 * Kota (max_per_invitation) doldurmayi yavaslatir ama TEMIZLEMEZ: misafir
 * dosyayi yukler, form gonderilmez, satir ve dosya sonsuza kadar kalir.
 * Sinir bir sayaci korur, bir DISKI korumaz.
 *
 * YETIM TANIMI (uc kosul birden):
 *   1. Tur misafir yuklemesi            -> gallery HARIC (o, davetiyenin
 *                                          galerisinin KENDISIDIR, yetim olamaz)
 *   2. Hicbir rsvps satiri isaret etmiyor
 *   3. Odeme suresi degil BEKLEME suresi dolmus (varsayilan 24 saat)
 *
 * Ucuncu kosul olmasaydi komut, misafir formu doldururken dosyasini silerdi.
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/PruneOrphanMedia.md
 */
final class PruneOrphanMedia extends Command
{
    protected $signature = 'media:prune-orphans
                            {--dry-run : Silme, yalnizca kac dosya etkilenecegini soyle}';

    protected $description = 'Hicbir LCV yanitina baglanmamis misafir yuklemelerini siler';

    public function handle(): int
    {
        if ($this->option('dry-run') === true) {
            $this->components->info(sprintf(
                '%d yetim yukleme bulundu (silinmedi).',
                $this->orphans()->count(),
            ));

            return self::SUCCESS;
        }

        $deleted = 0;

        // 🔴 chunkById: tek seferde binlerce satiri BELLEGE almiyoruz. Duz
        // chunk() kullanilsaydi, silme sirasinda sayfa sinirlari kayar ve her
        // sayfada birkac satir ATLANIRDI — klasik "silerken sayfalama" hatasi.
        // chunkById son GORULEN id'den devam eder, offset'ten degil.
        $this->orphans()->chunkById(100, function ($chunk) use (&$deleted): void {
            foreach ($chunk as $media) {
                $this->purge($media);
                $deleted++;
            }
        });

        $this->components->info(sprintf('%d yetim yukleme silindi.', $deleted));

        return self::SUCCESS;
    }

    /**
     * 🔴 SIRA: once DOSYA, sonra SATIR.
     *
     * F3 (dosya sistemi transaction'a dahil degildir) burada bir SIRA karari
     * dogurur ve iki yanlis gidisin daha ucuzu secilir:
     *
     *   Satir once silinseydi + dosya silme patlasaydi
     *     -> dosya diskte KALIR ve onu isaret eden hicbir kayit yoktur.
     *        Bir daha asla bulunamaz; disk sizintisi KALICI.
     *
     *   Dosya once silinir + satir silme patlarsa
     *     -> satir dosyasiz kalir. Kimse ona bakmiyor (zaten yetim) ve
     *        komutun BIR SONRAKI kosusu ayni satiri tekrar bulur:
     *        Storage::delete() olmayan dosyada da true doner, satir silinir.
     *        Hata KENDI KENDINI onarir.
     *
     * Geri alinamayan is en sona konur (L7); burada geri alinamaz olan
     * "izini kaybetmek"tir, "silmek" degil.
     */
    private function purge(Media $media): void
    {
        // Disk SATIRDAN okunuyor, config'ten DEGIL (F4). S3'e gocten sonra
        // eski satirlar hala kendi diskinden silinir.
        Storage::disk($media->disk)->delete($media->path);

        $media->delete();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Media>
     */
    private function orphans(): \Illuminate\Database\Eloquent\Builder
    {
        $graceHours = (int) config('davetkart.media.orphan_grace_hours');

        return Media::query()
            ->whereIn('kind', MediaKind::guestUploadableValues())
            ->where('created_at', '<', now()->subHours($graceHours))
            ->whereNotExists(function (QueryBuilder $query): void {
                // 🔴 HAM tablo sorgusu, Rsvp modeli DEGIL — ve bu bilincli:
                // model sorgusu soft delete suzgecini uygular, yani SILINMIS
                // bir LCV'nin fotografi "yetim" gorunurdu. Ham sorgu silinmis
                // satirlari da sayar ve yanlis yonde hata yapmaz: fazla dosya
                // saklamak, birinin fotografini yanlislikla silmekten iyidir.
                $query->select(DB::raw('1'))
                    ->from('rsvps')
                    ->where(function (QueryBuilder $inner): void {
                        $inner->whereColumn('rsvps.photo_media_id', 'media.id')
                            ->orWhereColumn('rsvps.video_media_id', 'media.id');
                    });
            });
    }
}
