<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GiftCodeBenefit;
use App\Models\GiftCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Hediye kodlarını listeler (Faz 11): `gift-codes:list [--active] [--batch=]`.
 *
 * Sayım TEK sorguda (withCount alt sorgusu, N+1 yok); kod metni gösterilemez,
 * çünkü saklanmıyor (D3): yalnızca son 4 karakter.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/ListGiftCodes.md
 */
final class ListGiftCodes extends Command
{
    protected $signature = 'gift-codes:list
                            {--active : Yalnizca su an kullanilabilenler}
                            {--batch= : Yalnizca bu toplu uretimin kodlari}
                            {--limit=50 : En fazla kac kod (en yeni ustte)}';

    protected $description = 'Hediye kodlarini kullanim sayilariyla listeler';

    public function handle(): int
    {
        $timezone = Config::string('davetkart.default_timezone');
        $limit = max(1, (int) $this->option('limit'));
        $batch = $this->option('batch');

        $query = GiftCode::query()->withActiveRedemptionCount()->latest();

        if (is_string($batch) && $batch !== '') {
            if (! Str::isUlid($batch)) {
                $this->components->error('--batch bir toplu uretim kimligi (ULID) olmali.');

                return self::INVALID;
            }

            $query->where('batch_id', strtolower($batch));
        }

        if ($this->option('active') === true) {
            $query->whereNull('disabled_at')
                ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        // "Tükenmemiş" koşulu sayıma bağlı; sayım bir alt sorgu SÜTUNU olduğu
        // için WHERE'de değil burada süzülür, limit süzmeden SONRA uygulanır
        // (önce uygulansaydı tükenmiş kodlar aktifleri listeden itebilirdi).
        $codes = $this->option('active') === true
            ? $query->get()
                ->filter(static fn (GiftCode $code): bool => $code->active_redemptions_count < $code->max_redemptions)
                ->take($limit)
                ->values()
            : $query->limit($limit)->get();

        if ($codes->isEmpty()) {
            $this->components->info('Kod yok.');

            return self::SUCCESS;
        }

        $this->table(
            ['Kimlik', 'Ipucu / etiket', 'Fayda', 'Plan', 'Aktif/Max', 'Bitis', 'Durum'],
            $codes->map(fn (GiftCode $code): array => [
                $code->id,
                '…'.$code->code_hint.($code->label !== null ? ' · '.$this->safe($code->label) : ''),
                $this->benefit($code),
                $code->tier->value ?? 'hepsi',
                sprintf('%d/%d', $code->active_redemptions_count, $code->max_redemptions),
                $code->expires_at?->setTimezone($timezone)->format('Y-m-d H:i') ?? 'suresiz',
                $this->status($code),
            ])->all(),
        );

        $this->line(sprintf('%d kod (saat dilimi: %s).', $codes->count(), $timezone));

        return self::SUCCESS;
    }

    private function benefit(GiftCode $code): string
    {
        return match ($code->benefit) {
            GiftCodeBenefit::Free => 'ucretsiz',
            GiftCodeBenefit::Percent => '%'.$code->percent_off,
            // Gösterim için tam sayı bölme; float yok.
            GiftCodeBenefit::Fixed => sprintf('%d,%02d TL', intdiv((int) $code->amount_off_minor, 100), (int) $code->amount_off_minor % 100),
        };
    }

    /** Öncelik: kapalı > süresi dolmuş > tükenmiş > aktif. */
    private function status(GiftCode $code): string
    {
        return match (true) {
            $code->isDisabled() => 'kapali',
            $code->isExpired() => 'suresi doldu',
            $code->active_redemptions_count >= $code->max_redemptions => 'tukendi',
            default => 'aktif',
        };
    }

    /** Etiketteki terminal kontrol karakterleri görünür kılınır (ListContactMessages ile aynı). */
    private function safe(string $text): string
    {
        return (string) preg_replace_callback(
            '/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u',
            static fn (array $match): string => sprintf('\u%04x', mb_ord($match[0])),
            $text,
        );
    }
}
