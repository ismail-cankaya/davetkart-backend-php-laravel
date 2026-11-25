<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InvitationStatus;
use App\Models\Invitation;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * K261 (A-E2): içeriği boş, hiç yayınlanmamış, siparişsiz ve medyasız taslakları siler (soft delete, K98 sonra temizler).
 * Tek seferlik bakım komutu, zamanlayıcıda yok (K84); önce `--dry-run`.
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/PruneEmptyDrafts.md
 */
final class PruneEmptyDrafts extends Command
{
    protected $signature = 'invitations:prune-empty-drafts
                            {--older-than=7 : Son düzenlemesi en az bu kadar gün önce olan taslaklar}
                            {--dry-run : Silme, yalnızca kaç taslağın silineceğini söyle}';

    protected $description = 'İçeriği boş, yayınlanmamış taslakları siler (taslak tavanını boşaltır)';

    public function handle(): int
    {
        $days = filter_var($this->option('older-than'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($days === false) {
            $this->components->error('--older-than en az 1 olan bir tam sayı olmalı.');

            return self::INVALID;
        }

        $before = now()->subDays($days);

        if ($this->option('dry-run') === true) {
            $this->components->info(sprintf('%d boş taslak silinecek (silinmedi).', $this->emptyDrafts($before)->count()));

            return self::SUCCESS;
        }

        // Koşullar silme anında SQL'de yeniden sınanır: o an içerik yazılan taslak elenir.
        $deleted = $this->emptyDrafts($before)->delete();
        $this->components->info(sprintf('%d boş taslak silindi.', $deleted));

        return self::SUCCESS;
    }

    /**
     * K192'deki "gerçek içerik" kuralının tersi: isim, tarih, mekân yok.
     *
     * @return Builder<Invitation>
     */
    private function emptyDrafts(CarbonInterface $before): Builder
    {
        return Invitation::query()
            ->where('status', '!=', InvitationStatus::Published)
            ->whereNull('published_at')
            ->whereNull('event_at')
            ->where(fn (Builder $q) => $q->whereNull('names')->orWhereRaw("trim(names) = ''"))
            ->where(fn (Builder $q) => $q->whereNull('venue')->orWhereRaw("trim(venue) = ''"))
            ->where('updated_at', '<', $before)
            ->whereDoesntHave('orders')
            ->whereDoesntHave('media');
    }
}
