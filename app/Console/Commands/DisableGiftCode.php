<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GiftCode\DisableGiftCodeAction;
use App\Models\GiftCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Bir hediye kodunu kapatır (Faz 11): `gift-codes:disable <kimlik>`.
 *
 * Kimlik `gift-codes:list` çıktısından okunur. Kod metniyle değil kimlikle:
 * düz kod komut geçmişine ikinci kez yazılmasın.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/DisableGiftCode.md
 */
final class DisableGiftCode extends Command
{
    protected $signature = 'gift-codes:disable {id : Kodun kimligi (gift-codes:list)}';

    protected $description = 'Bir hediye kodunu kapatir (silmez)';

    public function handle(DisableGiftCodeAction $action): int
    {
        $id = $this->argument('id');
        $id = is_string($id) ? strtolower(trim($id)) : '';

        $giftCode = Str::isUlid($id) ? GiftCode::query()->find($id) : null;

        if ($giftCode === null) {
            $this->components->error('Bu kimlikle bir kod yok.');

            return self::FAILURE;
        }

        if (! $action->handle($giftCode)) {
            $this->components->info(sprintf(
                'Kod zaten kapali (%s).',
                $giftCode->disabled_at?->setTimezone(Config::string('davetkart.default_timezone'))->format('Y-m-d H:i'),
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Kod kapatildi: …%s%s', $giftCode->code_hint, $giftCode->label !== null ? ' ('.$giftCode->label.')' : ''));

        return self::SUCCESS;
    }
}
