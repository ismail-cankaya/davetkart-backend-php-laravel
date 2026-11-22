<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use LogicException;
use Throwable;

/**
 * TST-02 (K187): kilitlerin testle korunması. Üç katman: SQL'de `for update` (ucuz, kesin),
 * ikinci bağlantıyla `55P03` (gerçek bekleme), alt süreçle gerçek araya girme (yarış).
 * Ayrintili aciklama: docs/rehber/tests/Support/LockAssertions.md
 */
trait LockAssertions
{
    /**
     * Kapanış çalışırken `<table>` için `for update` yürüdü mü? İş kuralı hatası kilitten
     * SONRA gelir; o yüzden yutulur ama mesajda gösterilir.
     */
    protected function assertLocksRowOf(string $table, Closure $action): void
    {
        /** @var list<string> $statements */
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $thrown = null;

        try {
            $action();
        } catch (Throwable $e) {
            $thrown = $e::class.': '.$e->getMessage();
        }

        $pattern = '/from "'.preg_quote($table, '/').'"\s.*\bfor update\b/s';
        $locked = array_filter($statements, static fn (string $sql): bool => preg_match($pattern, $sql) === 1);

        $this->assertNotEmpty($locked, "\"{$table}\" satırı kilitlenmedi (lockForUpdate yok)."
            .($thrown !== null ? " Action şunu fırlattı: {$thrown}" : ''));
    }

    /**
     * Başka bir bağlantı satırı kilitliyken Action beklemeli: `lock_timeout` 200 ms → 55P03.
     * Kilitsiz Action beklemeden geçer ve test kırmızı olur.
     */
    protected function assertWaitsForRowLock(string $table, string $id, Closure $action): void
    {
        $race = $this->raceConnection();
        $race->beginTransaction();
        $race->select('select 1 from "'.$table.'" where "id" = ? for update', [$id]);

        try {
            DB::transaction(static function () use ($action): void {
                DB::statement("SET LOCAL lock_timeout = '200ms'");
                $action();
            });

            $this->fail("\"{$table}\" kilitliyken Action beklemedi (lockForUpdate yok).");
        } catch (QueryException $e) {
            $this->assertSame('55P03', (string) $e->getCode(), $e->getMessage());
        } finally {
            $race->rollBack();
        }
    }

    /** Test veritabanının ikinci bağlantısı. Ad test deseninde değilse DURUR (asla geliştirme DB'si). */
    protected function raceConnection(): Connection
    {
        /** @var array<string, mixed> $config */
        $config = Config::array('database.connections.pgsql');
        $this->assertTestDatabase($config);
        Config::set('database.connections.pgsql_race', $config);
        DB::purge('pgsql_race');

        return DB::connection('pgsql_race');
    }

    /**
     * Kilidi AYRI bir süreçte alır, `holdMs` tutar, sonra COMMIT eder. Ana süreç bu arada
     * bloklanan sorguyu koşturabilir: tek süreçte üretilemeyen gerçek araya girme.
     *
     * @param  list<string>  $statements  Transaction içinde sırayla koşar (test kimlikleri, kullanıcı girdisi yok)
     */
    protected function holdRowLockInAnotherProcess(array $statements, int $holdMs): InvokedProcess
    {
        /** @var array<string, mixed> $config */
        $config = Config::array('database.connections.pgsql');
        $this->assertTestDatabase($config);

        $process = Process::env([
            'DK_RACE_DSN' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'] ?? '', $config['port'] ?? '', $config['database'] ?? ''),
            'DK_RACE_USER' => (string) ($config['username'] ?? ''),
            'DK_RACE_PASS' => (string) ($config['password'] ?? ''),
            'DK_RACE_SQL' => (string) json_encode($statements),
            'DK_RACE_HOLD_MS' => (string) $holdMs,
        ])->start([PHP_BINARY, base_path('tests/Support/hold-row-lock.php')]);

        $deadline = microtime(true) + 10;

        while (! str_contains($process->output(), 'locked')) {
            if (! $process->running() || microtime(true) > $deadline) {
                $this->fail('Kilit süreci kilidi alamadı: '.$process->output().$process->errorOutput());
            }

            usleep(10_000);
        }

        return $process;
    }

    /** @param  array<string, mixed>  $config */
    private function assertTestDatabase(array $config): void
    {
        $database = $config['database'] ?? null;

        if (! is_string($database) || preg_match('/\Adavetkart_test(_[a-z0-9_]+)?\z/', $database) !== 1) {
            throw new LogicException('Race connection refused: not a test database.');
        }
    }
}
