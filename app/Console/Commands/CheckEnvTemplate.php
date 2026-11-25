<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 🔴 K184 (OPS-04): `config/`'in okuduğu env anahtarları ile üretim şablonunu karşılaştırır;
 * `--check` farkta çıkış 1 verir (`composer check` zincirinde, plans:export deseni).
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/CheckEnvTemplate.md
 */
final class CheckEnvTemplate extends Command
{
    /** Şablonda bilerek olmayan anahtarlar: kullanılmayan sürücüler ve Laravel/Sentry varsayılanı yeterli olanlar. */
    private const array IGNORED_PREFIXES = [
        'AUTH_', 'BEANSTALKD_', 'DB_CACHE_', 'DB_QUEUE', 'DYNAMODB_', 'LOG_SLACK_', 'MEMCACHED_',
        'PAPERTRAIL_', 'POSTMARK_', 'REDIS_', 'RESEND_', 'SENTRY_BREADCRUMBS_', 'SENTRY_TRACE_',
        'SESSION_', 'SLACK_BOT_', 'SQS_',
    ];

    private const array IGNORED_KEYS = [
        // Yalnızca yerel/test.
        'DAVETKART_TEST_DB_SUFFIX',
        // Varsayılanı üretimde doğru.
        'APP_MAINTENANCE_STORE', 'APP_PREVIOUS_KEYS', 'AWS_ENDPOINT', 'BCRYPT_LIMIT', 'BCRYPT_ROUNDS',
        'CACHE_PREFIX', 'CACHE_STORAGE_DISK', 'CACHE_STORAGE_PATH', 'DB_CHARSET', 'DB_COLLATION',
        'DB_ENCRYPT', 'DB_FOREIGN_KEYS', 'DB_SOCKET', 'DB_SSLMODE', 'DB_TRUST_SERVER_CERTIFICATE', 'DB_URL',
        'FILESYSTEM_DISK', 'GEMINI_BASE_URL', 'HASH_VERIFY', 'LOG_DEPRECATIONS_TRACE',
        'LOG_PAPERTRAIL_HANDLER', 'LOG_STDERR_FORMATTER', 'LOG_SYSLOG_FACILITY', 'MYSQL_ATTR_SSL_CA',
        'QUEUE_FAILED_DRIVER', 'SANCTUM_STATEFUL_DOMAINS', 'SANCTUM_TOKEN_PREFIX',
        'SENTRY_DSN', 'SENTRY_ENABLE_LOGS', 'SENTRY_ENABLE_METRICS', 'SENTRY_LOGS_LEVEL',
        'SENTRY_LOG_FLUSH_THRESHOLD', 'SENTRY_LOG_LEVEL', 'SENTRY_ORG_ID', 'SENTRY_PROFILES_SAMPLE_RATE',
        'SENTRY_RELEASE', 'SENTRY_SAMPLE_RATE', 'SENTRY_SPOTLIGHT', 'SENTRY_STRICT_TRACE_CONTINUATION',
        // Yalnızca SMTP/sendmail yolunda (şablonda yorum olarak anlatılıyor).
        'MAIL_EHLO_DOMAIN', 'MAIL_HOST', 'MAIL_LOG_CHANNEL', 'MAIL_PASSWORD', 'MAIL_PORT',
        'MAIL_SCHEME', 'MAIL_SENDMAIL_PATH', 'MAIL_URL', 'MAIL_USERNAME',
    ];

    protected $signature = 'env:template
        {--check : Fark varsa çıkış 1}
        {--template= : Şablon dosyası (varsayılan docs/10-URETIM-ENV-SABLONU.md)}
        {--env= : Gerçek .env dosyası: yalnızca ANAHTARLARI şablonla karşılaştırır, değer okunmaz (A-E7)}';

    protected $description = 'Compare env keys read by config/ with the production .env template';

    public function handle(): int
    {
        $templatePath = $this->templatePath();

        if (! is_file($templatePath)) {
            $this->error("Template not found: {$templatePath}");

            return self::FAILURE;
        }

        $listed = $this->keysInTemplate((string) file_get_contents($templatePath));

        $envPath = $this->option('env');

        if (is_string($envPath) && $envPath !== '') {
            return $this->compareEnvFile($envPath, $listed);
        }

        $read = $this->keysReadByConfig();

        $missing = array_values(array_filter(
            array_diff($read, $listed),
            fn (string $key): bool => ! $this->isIgnored($key),
        ));
        $extra = array_values(array_diff($listed, $read));

        foreach ($missing as $key) {
            $this->line("missing in template: {$key}");
        }

        foreach ($extra as $key) {
            $this->line("not read by config: {$key}");
        }

        if ($missing === [] && $extra === []) {
            $this->info('Env template is in sync with config/.');

            return self::SUCCESS;
        }

        return $this->option('check') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A-E7: sunucudaki `.env` ↔ şablon. Şablonda olmayan anahtar yeniden kurulumda kaybolur; tersi sunucuda eksiktir.
     *
     * @param  list<string>  $listed
     */
    private function compareEnvFile(string $envPath, array $listed): int
    {
        if (! is_file($envPath)) {
            $this->error("Env file not found: {$envPath}");

            return self::FAILURE;
        }

        // Yalnızca `ANAHTAR=` öneki eşlenir; değer hiçbir çıktıya girmez.
        preg_match_all('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=/m', (string) file_get_contents($envPath), $matches);
        $present = $this->unique($matches[1]);

        $notInTemplate = array_values(array_diff($present, $listed));
        $missingOnServer = array_values(array_diff($listed, $present));

        foreach ($notInTemplate as $key) {
            $this->line("in .env, not in template: {$key}");
        }

        foreach ($missingOnServer as $key) {
            $this->line("in template, missing in .env: {$key}");
        }

        if ($notInTemplate === [] && $missingOnServer === []) {
            $this->info('The .env keys match the template.');

            return self::SUCCESS;
        }

        return $this->option('check') ? self::FAILURE : self::SUCCESS;
    }

    private function templatePath(): string
    {
        $option = $this->option('template');

        return is_string($option) && $option !== '' ? $option : base_path('docs/10-URETIM-ENV-SABLONU.md');
    }

    /** @return list<string> */
    private function keysReadByConfig(): array
    {
        $keys = [];

        foreach (glob(config_path('*.php')) ?: [] as $file) {
            preg_match_all("/env\\('([A-Z0-9_]+)'/", (string) file_get_contents($file), $matches);
            $keys = [...$keys, ...$matches[1]];
        }

        return $this->unique($keys);
    }

    /**
     * Yalnızca ```dotenv bloğundaki `ANAHTAR=` satırları; yorumdaki örnekler sayılmaz.
     *
     * @return list<string>
     */
    private function keysInTemplate(string $markdown): array
    {
        $block = Str::between($markdown, '```dotenv', '```');
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $block, $matches);

        return $this->unique($matches[1]);
    }

    private function isIgnored(string $key): bool
    {
        return in_array($key, self::IGNORED_KEYS, true) || Str::startsWith($key, self::IGNORED_PREFIXES);
    }

    /**
     * @param  array<int, string>  $keys
     *
     * @return list<string>
     */
    private function unique(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }
}
