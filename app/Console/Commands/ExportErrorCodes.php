<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ErrorCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * ErrorCode enum'undan makine okunabilir katalog uretir (08 §6).
 *
 * Frontend bu dosyadan ceviri anahtarlarini turetir ve eksikleri tespit eder.
 * Tek yonlu uretim: iki repo birbirine BAGLANMAZ, dosya kopyalanir.
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/ExportErrorCodes.md
 */
final class ExportErrorCodes extends Command
{
    protected $signature = 'errors:export
                            {--path=contracts/error-codes.json : Cikti dosyasi}
                            {--check : Yazma, yalnizca guncel mi diye bak}';

    protected $description = 'ErrorCode enum katalogunu JSON olarak disari aktarir';

    public function handle(): int
    {
        $catalog = $this->buildCatalog();
        $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        /** @var string $path */
        $path = $this->option('path');
        $absolute = base_path($path);

        if ($this->option('check') === true) {
            return $this->check($absolute, $json);
        }

        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $json);

        $this->components->info(sprintf(
            '%d hata kodu disari aktarildi: %s',
            count($catalog['codes']),
            $path,
        ));

        return self::SUCCESS;
    }

    /**
     * Katalogun tamami enum'dan turer — elle bakim yapilmaz.
     *
     * @return array{generatedAt: string, count: int, codes: array<string, array{status: int, params: list<string>, retryable: bool}>}
     */
    private function buildCatalog(): array
    {
        $codes = [];

        foreach (ErrorCode::cases() as $case) {
            $codes[$case->value] = [
                'status' => $case->status(),
                'params' => $case->allowedParams(),
                'retryable' => $case->isRetryable(),
            ];
        }

        // Kod adina gore sirala: ciktinin diff'i enum sirasindan bagimsiz kalsin.
        ksort($codes);

        return [
            'generatedAt' => now()->toIso8601String(),
            'count' => count($codes),
            'codes' => $codes,
        ];
    }

    /** CI kapisi: katalog kodla uyumsuzsa basarisiz cikis kodu doner. */
    private function check(string $absolute, string $expected): int
    {
        if (! File::exists($absolute)) {
            $this->components->error('Katalog dosyasi yok. `php artisan errors:export` calistir.');

            return self::FAILURE;
        }

        if ($this->withoutTimestamp(File::get($absolute)) !== $this->withoutTimestamp($expected)) {
            $this->components->error('Katalog guncel degil. `php artisan errors:export` calistir.');

            return self::FAILURE;
        }

        $this->components->info('Katalog guncel.');

        return self::SUCCESS;
    }

    /** generatedAt her kosuda degisir; karsilastirmadan cikarilir. */
    private function withoutTimestamp(string $json): string
    {
        return (string) preg_replace('/^\s*"generatedAt".*$/m', '', $json);
    }
}
