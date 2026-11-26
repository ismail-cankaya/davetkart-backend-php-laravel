<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Contracts\HealthCheck;
use Illuminate\Database\Migrations\Migrator;

/**
 * Bekleyen göç yok: kod şemanın önüne geçmemiş (Faz 20, OPS-10 · K263).
 * Ayrıntılı açıklama: docs/rehber/app/Support/Health/SchemaCheck.md
 */
final readonly class SchemaCheck implements HealthCheck
{
    public function __construct(private Migrator $migrator) {}

    public function name(): string
    {
        return 'schema';
    }

    public function passes(): bool
    {
        if (! $this->migrator->repositoryExists()) {
            return false;
        }

        // `migrate:status` ile aynı yollar: uygulamanınkiler + paketlerin kaydettikleri.
        $files = $this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]);

        return array_diff(array_keys($files), $this->migrator->getRepository()->getRan()) === [];
    }
}
