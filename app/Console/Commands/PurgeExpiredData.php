<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaKind;
use App\Models\ContactMessage;
use App\Models\GiftCodeRedemption;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Rsvp;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Saklama suresi dolan veriyi her gece siler (K98; siparisler 10 yil, K234). Once dosya, sonra satir.
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/PurgeExpiredData.md
 */
final class PurgeExpiredData extends Command
{
    /** Tarihsiz davetiyenin yedek suresi (K257, SEC-13). config/davetkart.php Hat A'nin; sabit burada. */
    private const UNDATED_FALLBACK_MONTHS = 12;

    /** K234: islem kaydi saklama suresi (gizlilik metni "10 yil"); config'e degil buraya, K257 ile ayni neden. */
    private const ORDER_RETENTION_YEARS = 10;

    protected $signature = 'data:purge
                            {--dry-run : Silme, yalnizca neyin silinecegini say}';

    protected $description = 'Saklama suresi dolan veriyi siler (davetiye, misafir verisi, iletisim mesaji, eski siparis)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run') === true;
        $suffix = $dryRun ? ' (yazilmadi)' : '';

        $invitations = $this->purgeDeletedInvitations($dryRun);
        [$rsvps, $guestMedia, $undated] = $this->purgeGuestData($dryRun);
        $messages = $this->purgeContactMessages($dryRun);
        $orders = $this->purgeOldOrders($dryRun);

        $this->components->info("{$invitations} davetiye kalici silindi{$suffix}.");
        $this->components->info("{$rsvps} LCV yaniti ve {$guestMedia} misafir dosyasi silindi{$suffix}.");
        $this->components->info("{$undated} tarihsiz davetiye yedek kurala girdi (yayin/olusturma + 12 ay).");
        $this->components->info("{$messages} iletisim mesaji silindi{$suffix}.");
        $this->components->info("{$orders} siparis (saklama suresi dolmus) silindi{$suffix}.");

        return self::SUCCESS;
    }

    /** Cop kutusunda suresi dolan davetiyeler: dosyalar once, sonra model (FK'ler LCV/medya satirlarini goturur). */
    private function purgeDeletedInvitations(bool $dryRun): int
    {
        $cutoff = now()->subDays(Config::integer('davetkart.retention.deleted_invitation_days'));
        $query = Invitation::onlyTrashed()->where('deleted_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;

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
     * Etkinligi gecmis davetiyelerin yalnizca MISAFIR verisi (LCV + misafir dosyasi); tarihsizde K257 yedek kurali.
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

        // Medya satirlari once gitti; rsvps.*_media_id FK'leri nullOnDelete (K60).
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

    /** K234: sure siparisin yilinin SONUNDAN sayilir; iade talebi ve hediye kodu kullanimi siparisle gider (FK restrict). */
    private function purgeOldOrders(bool $dryRun): int
    {
        $cutoff = now()->subYears(self::ORDER_RETENTION_YEARS)->startOfYear();
        $orders = Order::query()->where('created_at', '<', $cutoff);

        if ($dryRun) {
            return $orders->count();
        }

        $count = 0;

        foreach ($orders->pluck('id')->chunk(500) as $ids) {
            $count += DB::transaction(function () use ($ids): int {
                RefundRequest::query()->whereIn('order_id', $ids)->delete();
                GiftCodeRedemption::query()->whereIn('order_id', $ids)->delete();

                return Order::query()->whereIn('id', $ids)->delete();
            });
        }

        return $count;
    }
}
