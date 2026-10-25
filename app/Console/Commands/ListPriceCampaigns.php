<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Kampanyaları kullanım sayılarıyla listeler (Faz 13): `pricing:campaign:list`.
 *
 * Kullanım sayısı TEK alt sorguda (withCount, N+1 yok): bu kampanyadan
 * indirim almış ve ödenmiş sipariş sayısı.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/PriceCampaignCommands.md
 */
final class ListPriceCampaigns extends Command
{
    protected $signature = 'pricing:campaign:list
                            {--active : Yalnizca su an gecerli olanlar}
                            {--limit=50 : En fazla kac kampanya (en yeni ustte)}';

    protected $description = 'Fiyat kampanyalarini ve su anki fiyatlari listeler';

    public function handle(): int
    {
        $timezone = Config::string('davetkart.default_timezone');
        $now = now()->toImmutable();

        $query = PriceCampaign::query()
            ->withCount(['orders as paid_orders_count' => static fn ($orders) => $orders->whereNotNull('paid_at')])
            ->latest()
            ->limit(max(1, (int) $this->option('limit')));

        if ($this->option('active') === true) {
            $query->activeAt($now);
        }

        $campaigns = $query->get();

        if ($campaigns->isEmpty()) {
            $this->components->info('Kampanya yok.');
        } else {
            $this->table(
                ['Kimlik', 'Yuzde', 'Plan', 'Baslangic', 'Bitis', 'Odenen siparis', 'Durum', 'Not'],
                $campaigns->map(fn (PriceCampaign $campaign): array => [
                    $campaign->id,
                    '%'.$campaign->percent,
                    $campaign->tier->value ?? 'hepsi',
                    $campaign->starts_at->setTimezone($timezone)->format('Y-m-d H:i'),
                    $campaign->ends_at?->setTimezone($timezone)->format('Y-m-d H:i') ?? 'durdurulana kadar',
                    (int) $campaign->getAttribute('paid_orders_count'),
                    $this->status($campaign, $now),
                    $this->safe($campaign->label ?? ''),
                ])->all(),
            );
        }

        $firstPublish = Config::integer('davetkart.pricing.first_publish_percent');
        $this->line($firstPublish > 0
            ? "  Ilk yayin indirimi: %{$firstPublish} (config/davetkart.php -> pricing.first_publish_percent)"
            : '  Ilk yayin indirimi: kapali');

        return self::SUCCESS;
    }

    private function status(PriceCampaign $campaign, CarbonImmutable $now): string
    {
        return match (true) {
            $campaign->stopped_at !== null => 'durduruldu',
            $campaign->isActiveAt($now) => 'GECERLI',
            $campaign->starts_at > $now => 'baslamadi',
            default => 'bitti',
        };
    }

    /** Terminal kaçış dizilerini temizler: not alanı operatör girdisi (ListGiftCodes ile aynı). */
    private function safe(string $value): string
    {
        return (string) preg_replace_callback(
            '/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u',
            static fn (array $match): string => sprintf('\u%04x', mb_ord($match[0])),
            $value,
        );
    }
}
