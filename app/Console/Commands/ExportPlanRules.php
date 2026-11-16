<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SubscriptionTier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * config/davetkart.php'deki plan kurallarindan makine okunabilir sozlesme uretir (K146).
 *
 * Frontend bu dosyanin kopyasiyla kendi kural kopyalarini denetler (`npm run verify:plans`).
 * Fiyat YOK: o API'den gelir (K128). Desen errors:export ile ayni: tek yonlu uretim + --check.
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/ExportPlanRules.md
 */
final class ExportPlanRules extends Command
{
    protected $signature = 'plans:export
                            {--path=contracts/plan-rules.json : Cikti dosyasi}
                            {--check : Yazma, yalnizca guncel mi diye bak}';

    protected $description = 'Plan kurallarini (plan sirasi, LCV siniri, modul ve sablon planlari) JSON olarak disari aktarir';

    public function handle(): int
    {
        $json = json_encode(
            $this->buildContract(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";

        /** @var string $path */
        $path = $this->option('path');
        $absolute = base_path($path);

        if ($this->option('check') === true) {
            return $this->check($absolute, $json);
        }

        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $json);

        $this->components->info(sprintf('Plan kurallari disari aktarildi: %s', $path));
        $this->line('  Frontend kopyasini yenile: davetkart-frontent/src/contracts/plan-rules.json');

        return self::SUCCESS;
    }

    /**
     * Sozlesmenin tamami config'ten turer; elle bakim yapilmaz.
     *
     * Zaman damgasi YOK ve anahtarlar sirali: ayni kurallar bayt bayt ayni dosyayi uretir.
     *
     * @return array{moduleTiers: array<string, string>, presetTiers: array<string, string>, rsvpLimits: array<string, int|null>, tierOrder: list<string>, whiteLabelTier: string}
     */
    private function buildContract(): array
    {
        // Plan sirasi config'teki rank'ten gelir, enum'un yazilis sirasindan degil.
        $tiers = SubscriptionTier::cases();
        usort($tiers, static fn (SubscriptionTier $a, SubscriptionTier $b): int => $a->rank() <=> $b->rank());

        $rsvpLimits = [];

        foreach ($tiers as $tier) {
            $rsvpLimits[$tier->value] = $tier->rsvpLimit();
        }

        ksort($rsvpLimits, SORT_STRING);

        return [
            'moduleTiers' => $this->tierMap('davetkart.module_tiers'),
            'presetTiers' => $this->tierMap('davetkart.preset_tiers'),
            'rsvpLimits' => $rsvpLimits,
            'tierOrder' => array_map(static fn (SubscriptionTier $tier): string => $tier->value, $tiers),
            'whiteLabelTier' => $this->tier(
                'davetkart.branding.white_label_tier',
                Config::get('davetkart.branding.white_label_tier'),
            ),
        ];
    }

    /**
     * Bir "ad -> plan" haritasini dogrulayip ada gore siralar.
     *
     * @return array<string, string>
     */
    private function tierMap(string $key): array
    {
        $map = [];

        /** @var array<string, mixed> $configured */
        $configured = Config::array($key);

        foreach ($configured as $name => $value) {
            $map[$name] = $this->tier("{$key}.{$name}", $value);
        }

        // Config'te satir tasimak dosyada diff uretmesin.
        ksort($map, SORT_STRING);

        return $map;
    }

    /** Taninmayan plan adi sozlesmeye yazilmaz, gurultulu patlar (TierResolver ile ayni refleks). */
    private function tier(string $key, mixed $value): string
    {
        $tier = is_string($value) ? SubscriptionTier::tryFrom($value) : null;

        if ($tier === null) {
            throw new RuntimeException("Configuration error: {$key} is not a valid tier.");
        }

        return $tier->value;
    }

    /** Kalite kapisi: dosya config'le bayt bayt ayni degilse basarisiz cikis kodu doner. */
    private function check(string $absolute, string $expected): int
    {
        if (! File::exists($absolute)) {
            $this->components->error('Sozlesme dosyasi yok. `php artisan plans:export` calistir.');

            return self::FAILURE;
        }

        if (File::get($absolute) !== $expected) {
            $this->components->error('Plan kurallari sozlesmesi guncel degil. `php artisan plans:export` calistir.');

            return self::FAILURE;
        }

        $this->components->info('Plan kurallari sozlesmesi guncel.');

        return self::SUCCESS;
    }
}
