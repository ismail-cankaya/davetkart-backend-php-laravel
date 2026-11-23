<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaKind;
use App\Models\ContactMessage;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Rsvp;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * Saklama suresi dolan kisisel veriyi siler (Faz 10, 10.43 · K98 / S-1).
 *
 * Uc kategori, uc sure (config: davetkart.retention):
 *   1. Cop kutusundaki davetiye  -> 30 gun sonra KALICI (dosyalariyla)
 *   2. Misafir verisi           -> etkinlikten 6 ay sonra: LCV satirlari +
 *                                  misafirin foto/videosu (galeri KALIR);
 *                                  tarihsizse yayin/olusturma + 12 ay (K257)
 *   3. Iletisim mesaji          -> 12 ay sonra
 *
 * 🔴 Once DOSYA, sonra SATIR (PruneOrphanMedia deseni): satir once silinip
 * dosya silme basarisiz olsaydi dosyanin yolu kayitli tek yer de gitmis
 * olurdu. Bu sirada yarim kalan is ertesi gece satiri yeniden bulur.
 *
 * 🔴 Siparislere DOKUNMAZ: kalici silinen davetiyenin siparisi FK ile
 * `invitation_id = NULL` olur ve muhasebe kaydi olarak kalir (K82, K97).
 *
 * Zamanlayicida GECE calisir (03:45). K84: yayina alindiginda ilk kosu
 * elle ve `--dry-run` ile yapilir.
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/PurgeExpiredData.md
 */
final class PurgeExpiredData extends Command
{
    /** Tarihsiz davetiyenin yedek suresi (K257, SEC-13). config/davetkart.php Hat A'nin; sabit burada. */
    private const UNDATED_FALLBACK_MONTHS = 12;

    protected $signature = 'data:purge
                            {--dry-run : Silme, yalnizca neyin silinecegini say}';

    protected $description = 'Saklama suresi dolan kisisel veriyi siler (davetiye, misafir verisi, iletisim mesaji)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run') === true;
        $suffix = $dryRun ? ' (yazilmadi)' : '';

        $invitations = $this->purgeDeletedInvitations($dryRun);
        [$rsvps, $guestMedia, $undated] = $this->purgeGuestData($dryRun);
        $messages = $this->purgeContactMessages($dryRun);

        $this->components->info("{$invitations} davetiye kalici silindi{$suffix}.");
        $this->components->info("{$rsvps} LCV yaniti ve {$guestMedia} misafir dosyasi silindi{$suffix}.");
        $this->components->info("{$undated} tarihsiz davetiye yedek kurala girdi (yayin/olusturma + 12 ay).");
        $this->components->info("{$messages} iletisim mesaji silindi{$suffix}.");

        return self::SUCCESS;
    }

    /**
     * Cop kutusunda suresi dolan davetiyeler. Model uzerinden silinir: FK'ler
     * LCV'leri, program adimlarini ve medya satirlarini goturur; dosyalar
     * ONCE elle silinir.
     */
    private function purgeDeletedInvitations(bool $dryRun): int
    {
        $cutoff = now()->subDays(Config::integer('davetkart.retention.deleted_invitation_days'));
        $query = Invitation::onlyTrashed()->where('deleted_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;

        // lazyById: binlerce davetiye bellege bir kerede alinmaz. Silerken
        // dolasmak icin guvenli, cunku siradaki parca kimlige gore istenir.
        foreach ($query->with('media')->lazyById(100) as $invitation) {
            foreach ($invitation->media as $media) {
                Storage::disk($media->disk)->delete($media->path);
            }

            $invitation->forceDelete();
            $count++;
        }

        return $count;
    }

    /**
     * Etkinligi uzun zaman once gecmis davetiyelerin MISAFIR verisi.
     *
     * Davetiye ve sahibinin galerisi DOKUNULMAZ: o sahibin verisi, sahibi
     * isterse siler. Silinen yalnizca misafirlerden toplanan: ad, mesaj,
     * menu tercihi, yukledikleri foto/video. Tarihi olmayan (event_at NULL)
     * davetiyede yedek kural: published_at, yoksa created_at + 12 ay (K257).
     *
     * @return array{0: int, 1: int, 2: int} [LCV satiri, misafir dosyasi, tarihsiz davetiye]
     */
    private function purgeGuestData(bool $dryRun): array
    {
        $cutoff = now()->subMonths(Config::integer('davetkart.retention.guest_data_months_after_event'));
        $fallbackCutoff = now()->subMonths(self::UNDATED_FALLBACK_MONTHS);

        // NULL < tarih hicbir zaman dogru degil: tarihsiz dal ayri yazilmazsa sessizce atlanir.
        $finished = Invitation::withTrashed()
            ->where(fn (Builder $query) => $query
                ->where('event_at', '<', $cutoff)
                ->orWhere(fn (Builder $undated) => $this->undatedPastFallback($undated, $fallbackCutoff)))
            ->select('id');

        $undatedCount = $this->undatedPastFallback(Invitation::withTrashed(), $fallbackCutoff)->count();

        $rsvps = Rsvp::query()->whereIn('invitation_id', $finished);
        $media = Media::query()
            ->whereIn('invitation_id', $finished)
            ->whereIn('kind', MediaKind::guestUploadableValues());

        if ($dryRun) {
            return [$rsvps->count(), $media->count(), $undatedCount];
        }

        $fileCount = 0;

        foreach ($media->lazyById(500) as $item) {
            Storage::disk($item->disk)->delete($item->path);
            $item->delete();
            $fileCount++;
        }

        // Medya satirlari once gitti; rsvps.photo_media_id / video_media_id
        // FK'leri nullOnDelete (K60), yani LCV satirlari bu arada bozulmadi.
        $rsvpCount = $rsvps->delete();

        return [$rsvpCount, $fileCount, $undatedCount];
    }

    /**
     * Tarihsiz ve yedek suresi dolmus: COALESCE(published_at, created_at) < sinir, iki acik dalla.
     *
     * @param  Builder<Invitation>  $query
     *
     * @return Builder<Invitation>
     */
    private function undatedPastFallback(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->whereNull('event_at')
            ->where(fn (Builder $inner) => $inner
                ->where('published_at', '<', $cutoff)
                ->orWhere(fn (Builder $unpublished) => $unpublished
                    ->whereNull('published_at')
                    ->where('created_at', '<', $cutoff)));
    }

    private function purgeContactMessages(bool $dryRun): int
    {
        $cutoff = now()->subMonths(Config::integer('davetkart.retention.contact_message_months'));
        $query = ContactMessage::query()->where('created_at', '<', $cutoff);

        return $dryRun ? $query->count() : $query->delete();
    }
}
