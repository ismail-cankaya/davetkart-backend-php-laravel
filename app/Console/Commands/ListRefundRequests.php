<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RefundRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * İade (iptal) taleplerini listeler (Faz 14, 14.6). Yönetim paneli yok.
 *
 * Varsayılan: yalnızca karar bekleyenler, en eski üstte (sıra = iş kuyruğu).
 * `provider_payment_id` gösterilir: Shopier paneli ödemeyi o kimlikle tanır.
 * 🔴 Açıklama kullanıcıdan geliyor: kontrol karakterleri görünür kılınır.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/RefundCommands.md
 */
final class ListRefundRequests extends Command
{
    protected $signature = 'refunds:list
                            {--all : Kapanmış talepler de (en yeni üstte)}
                            {--limit=50 : En fazla kaç talep}';

    protected $description = 'Iade (iptal) taleplerini listeler';

    public function handle(): int
    {
        $timezone = Config::string('davetkart.default_timezone');
        $all = (bool) $this->option('all');

        $query = RefundRequest::query()
            ->with(['order', 'user'])
            ->limit(max(1, (int) $this->option('limit')));

        $all ? $query->latest() : $query->open()->oldest();

        $requests = $query->get();

        if ($requests->isEmpty()) {
            $this->info($all ? 'Talep yok.' : 'Karar bekleyen talep yok.');

            return self::SUCCESS;
        }

        $this->table(
            ['Talep', 'Tarih', 'Durum', 'Plan', 'Tutar', 'Shopier ödeme', 'E-posta', 'Açıklama'],
            $requests->map(fn (RefundRequest $request): array => [
                $request->id,
                $request->created_at?->setTimezone($timezone)->format('Y-m-d H:i'),
                $request->status->value,
                $request->order?->tier->label() ?? '-',
                $this->amount($request->order?->amount_minor),
                $request->order->provider_payment_id ?? '-',
                $request->user->email ?? '(silinmiş hesap)',
                $this->safe(Str::limit($request->reason ?? '', 60)),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /** Kuruş -> "399,00"; float yok (ShopierAmount ile aynı ilke). */
    private function amount(?int $minor): string
    {
        return $minor === null ? '-' : sprintf('%d,%02d', intdiv($minor, 100), $minor % 100);
    }

    /** Kontrol karakterlerini görünür kılar (ListContactMessages ile aynı kural). */
    private function safe(string $text): string
    {
        return (string) preg_replace_callback(
            '/[\x{00}-\x{08}\x{0B}-\x{1F}\x{7F}-\x{9F}]/u',
            static fn (array $match): string => sprintf('\u%04x', mb_ord($match[0])),
            $text,
        );
    }
}
