<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Console\ServeCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `php artisan serve` gecici klasoru PHP sunucusuna aktarmali.
 *
 * 🔴 Aktarmazsa Windows'ta her dosya yuklemesi, istek Laravel'e ulasmadan
 * "unable to create a temporary file" ile duser. Bu hata ne bir birim ne bir
 * HTTP testinde gorunur: test istemcisi dosyayi PHP'nin yukleme katmanindan
 * gecirmez. Bu yuzden yapilandirmanin kendisi sinanir; AppServiceProvider
 * yeniden duzenlenirken ayar sessizce kaybolmasin.
 * Ayrintili aciklama: docs/rehber/tests/Feature/LocalServerEnvironmentTest.md
 */
final class LocalServerEnvironmentTest extends TestCase
{
    #[Test]
    public function artisan_serve_passes_the_temp_directory_to_the_php_server(): void
    {
        foreach (['TEMP', 'TMP', 'TMPDIR'] as $variable) {
            $this->assertContains($variable, ServeCommand::$passthroughVariables);
        }
    }

    /** Laravel'in kendi listesi korunur; yalnizca eklenir, ezilmez. */
    #[Test]
    public function the_framework_defaults_are_kept(): void
    {
        foreach (['APP_ENV', 'PATH', 'SYSTEMROOT'] as $variable) {
            $this->assertContains($variable, ServeCommand::$passthroughVariables);
        }

        $this->assertSame(
            array_values(array_unique(ServeCommand::$passthroughVariables)),
            ServeCommand::$passthroughVariables,
        );
    }
}
