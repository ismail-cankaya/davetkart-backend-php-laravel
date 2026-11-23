<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Contracts\HealthCheck;
use Illuminate\Support\Facades\DB;

/** Varsayılan bağlantı sorgu çalıştırabiliyor mu (`select 1`). */
final class DatabaseCheck implements HealthCheck
{
    public function name(): string
    {
        return 'database';
    }

    public function passes(): bool
    {
        DB::connection()->select('select 1');

        return true;
    }
}
