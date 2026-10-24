<?php

declare(strict_types=1);

namespace App\Actions\GiftCode;

use App\Models\GiftCode;
use App\Support\GiftCodes\GiftCodeFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Tek kullanımlık kodları toplu üretir ve CSV'ye BİR KEZ yazar (Faz 11, D9).
 *
 * 🔴 Kodlar veritabanından geri okunamaz (D3). CSV kaybolursa kodlar da kaybolur:
 * batch kapatılıp yeniden üretilir. Kodlar konsola ve log'a basılmaz.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/GiftCode/GenerateGiftCodeBatchAction.md
 */
final class GenerateGiftCodeBatchAction
{
    public const MAX_COUNT = 1000;

    /** storage/app/private altında (local disk); web'den servis edilmez. */
    public const DIRECTORY = 'gift-codes';

    /**
     * @return array{batchId: string, path: string, count: int} `path` local diskte göreli
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException CSV yazılamadı (satırlar geri alındı)
     */
    public function handle(int $count, GiftCodeSpec $spec): array
    {
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new InvalidArgumentException('--count 1 ile '.self::MAX_COUNT.' arasinda olmali.');
        }

        $spec = $spec->singleUse();
        $batchId = strtolower((string) Str::ulid());
        $path = self::DIRECTORY.'/'.$batchId.'.csv';
        $codes = $this->uniqueCodes($count);

        // 🔴 Sıra: satırlar ve CSV AYNI transaction'da. CSV yazılamazsa satırlar
        // geri alınır (kimsenin bilmediği kodlar kalmaz); commit patlarsa CSV
        // silinir (çalışmayan kodlar dağıtılmaz).
        try {
            DB::transaction(function () use ($codes, $spec, $batchId, $path): void {
                foreach ($codes as $code) {
                    CreateGiftCodeAction::fill(new GiftCode, $code, $spec, $batchId)->save();
                }

                if (! Storage::disk('local')->put($path, $this->csv($codes, $spec, $batchId))) {
                    throw new RuntimeException("CSV yazilamadi: {$path}");
                }
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        return ['batchId' => $batchId, 'path' => $path, 'count' => $count];
    }

    /**
     * Bellek içinde tekrar yok; veritabanıyla çakışma ~2^-59 olasılık ve UNIQUE
     * kısıt onu da yakalar (bütün batch geri alınır, komut yeniden koşulur).
     *
     * @return list<string>
     */
    private function uniqueCodes(int $count): array
    {
        $codes = [];

        while (count($codes) < $count) {
            $codes[GiftCodeFormat::generate()] = true;
        }

        return array_keys($codes);
    }

    /** @param  list<string>  $codes */
    private function csv(array $codes, GiftCodeSpec $spec, string $batchId): string
    {
        $rows = [['code', 'benefit', 'tier', 'percent_off', 'amount_off_minor', 'expires_at_utc', 'label', 'batch_id']];

        foreach ($codes as $code) {
            $rows[] = [
                $code,
                $spec->benefit->value,
                $spec->tier->value ?? '',
                (string) ($spec->percentOff ?? ''),
                (string) ($spec->amountOffMinor ?? ''),
                $spec->expiresAt?->toIso8601String() ?? '',
                $spec->label ?? '',
                $batchId,
            ];
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Gecici bellek akisi acilamadi.');
        }

        foreach ($rows as $row) {
            // Etiket "=" ile başlarsa Excel formül sanmasın (CSV injection).
            fputcsv($handle, array_map(self::neutralize(...), $row), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private static function neutralize(string $cell): string
    {
        return preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'".$cell : $cell;
    }
}
