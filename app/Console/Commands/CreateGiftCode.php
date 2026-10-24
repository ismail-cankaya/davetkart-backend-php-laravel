<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GiftCode\CreateGiftCodeAction;
use App\Actions\GiftCode\GiftCodeSpec;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Elle belirlenen bir hediye kodu yazar (Faz 11): `gift-codes:create DUGUN2026 ...`.
 *
 * İnce komut (controller gibi): seçenekleri okur, iş Action'da.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/CreateGiftCode.md
 */
final class CreateGiftCode extends Command
{
    protected $signature = 'gift-codes:create
                            {code : Kodun kendisi (4-32 karakter, A-Z ve 0-9; bosluk/tire atilir)}
                            {--benefit= : free | percent | fixed}
                            {--tier= : standart | gold | elit (free icin zorunlu; percent/fixed icin bos = butun planlar)}
                            {--percent= : percent icin 1-100}
                            {--amount= : fixed icin TL: 150 ya da 150.50}
                            {--max-uses=1 : Toplam kac kez kullanilabilir}
                            {--expires= : Son gun (YYYY-MM-DD, gunun sonuna kadar gecerli)}
                            {--label= : Not (orn. "Instagram cekilisi")}';

    protected $description = 'Elle belirlenen bir hediye kodu olusturur (N kez kullanilabilir)';

    public function handle(CreateGiftCodeAction $action): int
    {
        $timezone = Config::string('davetkart.default_timezone');

        try {
            $spec = GiftCodeSpec::fromOptions(
                benefit: $this->stringOption('benefit'),
                tier: $this->stringOption('tier'),
                percent: $this->stringOption('percent'),
                amount: $this->stringOption('amount'),
                maxUses: $this->stringOption('max-uses'),
                expires: $this->stringOption('expires'),
                label: $this->stringOption('label'),
                timezone: $timezone,
            );

            $code = $this->argument('code');
            $giftCode = $action->handle(is_string($code) ? $code : '', $spec);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        // Kodun kendisi yeniden basılmaz: operatör onu zaten yazdı.
        $this->components->info('Kod olusturuldu.');
        $this->table(['Kimlik', 'Ipucu', 'Fayda', 'Plan', 'Max', 'Bitis'], [[
            $giftCode->id,
            '…'.$giftCode->code_hint,
            $spec->benefit->value,
            $spec->tier->value ?? 'hepsi',
            $spec->maxRedemptions,
            $spec->expiresAt?->setTimezone($timezone)->format('Y-m-d H:i') ?? 'suresiz',
        ]]);

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }
}
