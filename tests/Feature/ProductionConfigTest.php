<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * OPS-04 · K184 (şablon ↔ config) · K185 (üretimde `log` posta sürücüsü görünür).
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/CheckEnvTemplate.md
 */
final class ProductionConfigTest extends TestCase
{
    #[Test]
    public function the_env_template_lists_every_key_config_reads(): void
    {
        $this->command(['--check' => true])
            ->expectsOutputToContain('in sync')
            ->assertSuccessful();
    }

    #[Test]
    public function the_check_fails_when_the_template_misses_a_key_config_reads(): void
    {
        $template = $this->templateCopy(fn (string $s): string => str_replace("AWS_URL=https://media.davetkart.com\n", '', str_replace("\r\n", "\n", $s)));

        $this->command(['--check' => true, '--template' => $template])
            ->expectsOutputToContain('missing in template: AWS_URL')
            ->assertFailed();
    }

    #[Test]
    public function the_check_fails_for_a_key_config_does_not_read(): void
    {
        $template = $this->templateCopy(fn (string $s): string => str_replace('APP_NAME=DavetKart', "APP_NAME=DavetKart\nBROADCAST_CONNECTION=log", $s));

        $this->command(['--check' => true, '--template' => $template])
            ->expectsOutputToContain('not read by config: BROADCAST_CONNECTION')
            ->assertFailed();
    }

    /** A-E7: sunucu `.env`'i ↔ şablon, yalnızca anahtarlar; değer çıktıya hiç girmez. */
    #[Test]
    public function a_real_env_file_is_compared_by_keys_only(): void
    {
        $block = str_replace("\r\n", "\n", Str::between((string) file_get_contents(base_path('docs/10-URETIM-ENV-SABLONU.md')), '```dotenv', '```'));
        $env = $this->templateCopy(fn (): string => str_replace("AWS_URL=https://media.davetkart.com\n", '', $block)."LEGACY_TOKEN=cok-gizli-deger\n");

        $this->command(['--check' => true, '--env' => $env])
            ->expectsOutputToContain('in .env, not in template: LEGACY_TOKEN')
            ->expectsOutputToContain('in template, missing in .env: AWS_URL')
            ->doesntExpectOutputToContain('cok-gizli-deger')
            ->assertFailed();

        $matching = $this->templateCopy(fn (): string => $block);
        $this->command(['--check' => true, '--env' => $matching])
            ->expectsOutputToContain('match the template')
            ->assertSuccessful();
    }

    #[Test]
    public function the_log_mailer_is_reported_as_critical_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        Config::set('mail.default', 'log');
        $logger = Log::spy();

        // Gercek log tasiyicisi Log::spy ile kurulamaz; Mailer'in gonderimden once attigi olay yeterli.
        event(new MessageSending((new Email)->to('misafir@example.com')));

        $logger->shouldHaveReceived('critical', ['Mail not delivered: production mailer is log', ['mailer' => 'log']]);
    }

    #[Test]
    public function the_log_mailer_is_quiet_outside_production(): void
    {
        Config::set('mail.default', 'log');
        $logger = Log::spy();

        // Gercek log tasiyicisi Log::spy ile kurulamaz; Mailer'in gonderimden once attigi olay yeterli.
        event(new MessageSending((new Email)->to('misafir@example.com')));

        $logger->shouldNotHaveReceived('critical');
    }

    #[Test]
    public function about_command_shows_the_mailer_warning_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        Config::set('mail.default', 'log');

        $about = $this->artisan('about', ['--only' => 'davetkart']);
        $this->assertInstanceOf(PendingCommand::class, $about);
        $about->expectsOutputToContain('MAIL IS NOT DELIVERED')->assertSuccessful();
    }

    /** @param  array<string, mixed>  $options */
    private function command(array $options): PendingCommand
    {
        $command = $this->artisan('env:template', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /** @param  callable(string): string  $change */
    private function templateCopy(callable $change): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dk-env-');
        file_put_contents($path, $change((string) file_get_contents(base_path('docs/10-URETIM-ENV-SABLONU.md'))));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
