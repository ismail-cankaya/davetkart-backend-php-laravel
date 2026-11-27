<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 21.10 (TST-06): `composer test:parallel`'de veritabanına dokunan her süreç KENDİ veritabanında (`<ad>_test_<token>`).
 * Laravel bunu yalnızca veritabanı trait'i (RefreshDatabase …) kullanan sınıflarda yapar. Ayrıntı: docs/rehber/tests/Feature/ParallelTestDatabaseTest.md
 */
final class ParallelTestDatabaseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_database_test_runs_on_its_own_process_database(): void
    {
        // config/database.php'nin okuduğu ham değer (K182); testte config önbelleği yok.
        $suffix = Env::get('DAVETKART_TEST_DB_SUFFIX');
        $base = 'davetkart_test'.(is_string($suffix) && $suffix !== '' ? '_'.$suffix : '');
        $token = ParallelTesting::token();
        $expected = $token === false ? $base : $base.'_test_'.$token;

        $this->assertSame($expected, Config::string('database.connections.pgsql.database'));

        // Bağlantı da gerçekten oraya gidiyor (yalnızca config değil).
        User::factory()->create();
        $this->assertSame($expected, DB::scalar('select current_database()'));
    }
}
