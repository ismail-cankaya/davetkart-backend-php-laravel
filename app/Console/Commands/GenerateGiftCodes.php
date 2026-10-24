<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GiftCode\GenerateGiftCodeBatchAction;
use App\Actions\GiftCode\GiftCodeSpec;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Tek kullanımlık kodları toplu üretir (Faz 11): `gift-codes:generate --count=50 ...`.
 *
 * 🔴 Kodlar KONSOLA BASILMAZ (terminal geçmişi, ekran kaydı, CI log'u): yalnızca
 * CSV'nin yolu ve bir özet. CSV bir kez yazılır, kodlar veritabanından geri okunamaz.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/GenerateGiftCodes.md
 */
final class GenerateGiftCodes extends Command
{
    protected $signature = 'gift-codes:generate
                            {--count= : Kac kod (1-1000)}
                            {--benefit= : free | percent | fixed}
                            {--tier= : standart | gold | elit (free icin zorunlu)}
                            {--percent= : percent icin 1-100}
                            {--amount= : fixed icin TL: 150 ya da 150.50}
                            {--expires= : Son gun (YYYY-MM-DD)}
                            {--label= : Not}';

    protected $description = 'Tek kullanimlik hediye kodlarini toplu uretir ve CSV dosyasina yazar';

    public function handle(GenerateGiftCodeBatchAction $action): int
    {
        $timezone = Config::string('davetkart.default_timezone');
        $count = $this->stringOption('count');

        try {
            if ($count === null || preg_match('/^\d{1,4}$/', $count) !== 1) {
                throw new InvalidArgumentException('--count 1 ile '.GenerateGiftCodeBatchAction::MAX_COUNT.' arasinda bir tam sayi olmali.');
            }

            $spec = GiftCodeSpec::fromOptions(
                benefit: $this->stringOption('benefit'),
                tier: $this->stringOption('tier'),
                percent: $this->stringOption('percent'),
                amount: $this->stringOption('amount'),
                maxUses: '1',   // D9: her kod tek kullanımlık
                expires: $this->stringOption('expires'),
                label: $this->stringOption('label'),
                timezone: $timezone,
            );

            $result = $action->handle((int) $count, $spec);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $this->components->info(sprintf('%d tek kullanimlik kod uretildi.', $result['count']));
        $this->table(['Batch', 'Adet', 'Fayda', 'Plan', 'Bitis'], [[
            $result['batchId'],
            $result['count'],
            $spec->benefit->value,
            $spec->tier->value ?? 'hepsi',
            $spec->expiresAt?->setTimezone($timezone)->format('Y-m-d H:i') ?? 'suresiz',
        ]]);
        $this->line('CSV: '.Storage::disk('local')->path($result['path']));
        $this->components->warn('Kodlar yalnizca bu dosyada. Indirip guvenli bir yere koyun; veritabanindan geri okunamaz.');

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }
}
