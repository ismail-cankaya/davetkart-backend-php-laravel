<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Pricing\PriceCampaignSpec;
use App\Actions\Pricing\StartPriceCampaignAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Fiyat kampanyası açar (Faz 13): `pricing:campaign:start 20 --until=2027-01-07`.
 *
 * İnce komut (controller gibi): seçenekleri okur, iş Action'da.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/PriceCampaignCommands.md
 */
final class StartPriceCampaign extends Command
{
    protected $signature = 'pricing:campaign:start
                            {percent : Indirim yuzdesi, 1-90}
                            {--tier= : standart | gold | elit (bos = butun planlar)}
                            {--from= : Baslangic gunu (YYYY-MM-DD; bos = hemen)}
                            {--until= : Son gun (YYYY-MM-DD, gunun sonuna kadar; bos = durdurulana kadar)}
                            {--label= : Not (orn. "Yilbasi kampanyasi")}';

    protected $description = 'Fiyat kampanyasi acar: secilen planlarda yuzde indirim';

    public function handle(StartPriceCampaignAction $action): int
    {
        $timezone = Config::string('davetkart.default_timezone');
        $percent = $this->argument('percent');

        try {
            $spec = PriceCampaignSpec::fromOptions(
                percent: is_string($percent) ? $percent : null,
                tier: $this->stringOption('tier'),
                from: $this->stringOption('from'),
                until: $this->stringOption('until'),
                label: $this->stringOption('label'),
                timezone: $timezone,
            );
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $campaign = $action->handle($spec);

        $this->components->info('Kampanya acildi.');
        $this->table(['Kimlik', 'Yuzde', 'Plan', 'Baslangic', 'Bitis'], [[
            $campaign->id,
            '%'.$campaign->percent,
            $campaign->tier->value ?? 'hepsi',
            $campaign->starts_at->setTimezone($timezone)->format('Y-m-d H:i'),
            $campaign->ends_at?->setTimezone($timezone)->format('Y-m-d H:i') ?? 'durdurulana kadar',
        ]]);

        // İlk yayın indirimiyle toplanmadığını operatör bilmeli (K133).
        $firstPublish = Config::integer('davetkart.pricing.first_publish_percent');

        if ($firstPublish > 0) {
            $this->components->warn("Ilk yayin indirimi (%{$firstPublish}) ile toplanmaz; ikisi birden uygunsa buyuk olan uygulanir.");
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }
}
