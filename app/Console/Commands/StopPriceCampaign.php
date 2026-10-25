<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Pricing\StopPriceCampaignAction;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Bir kampanyayı hemen durdurur (Faz 13): `pricing:campaign:stop <kimlik>`.
 *
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/PriceCampaignCommands.md
 */
final class StopPriceCampaign extends Command
{
    protected $signature = 'pricing:campaign:stop
                            {id : Kampanya kimligi (pricing:campaign:list)}';

    protected $description = 'Bir fiyat kampanyasini hemen durdurur (satir silinmez)';

    public function handle(StopPriceCampaignAction $action): int
    {
        $id = $this->argument('id');

        try {
            $action->handle(is_string($id) ? $id : '');
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $this->components->info('Kampanya durduruldu. Yeni siparisler liste fiyatindan (ya da ilk yayin indirimiyle) acilir.');

        return self::SUCCESS;
    }
}
