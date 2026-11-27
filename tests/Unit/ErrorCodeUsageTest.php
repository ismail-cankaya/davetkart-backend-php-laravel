<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ErrorCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Faz 21.6 (ARC-06, K270): katalogdaki her kod `app/` içinde en az bir yerde kullanılır; söz verilip tutulmayan kod yok (B4).
 * Ayrıntılı açıklama: docs/rehber/tests/Unit/ErrorCodeUsageTest.md
 */
final class ErrorCodeUsageTest extends TestCase
{
    #[Test]
    public function every_error_code_is_used_somewhere_in_app(): void
    {
        $source = $this->appSourceWithoutTheCatalogue();

        $unused = array_values(array_filter(
            ErrorCode::cases(),
            static fn (ErrorCode $code): bool => ! str_contains($source, 'ErrorCode::'.$code->name),
        ));

        $this->assertSame([], array_map(static fn (ErrorCode $code): string => $code->value, $unused));
    }

    /**
     * Silinen kodlar geri gelmesin; gelirse fırlatan kodla ve bu listeden çıkarılarak gelir.
     * 22.1 (K276) `EMAIL_NOT_VERIFIED`'ı da sildi: katalog 28 → 27.
     */
    #[Test]
    public function the_removed_codes_stay_removed(): void
    {
        $values = array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases());

        foreach (['TOKEN_EXPIRED', 'INVITATION_LOCKED', 'SLUG_TAKEN', 'EMAIL_NOT_VERIFIED'] as $removed) {
            $this->assertNotContains($removed, $values);
        }
        $this->assertCount(27, $values);
    }

    private function appSourceWithoutTheCatalogue(): string
    {
        $root = dirname(__DIR__, 2).'/app';
        $source = '';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php' || str_ends_with($path, '/app/Enums/ErrorCode.php')) {
                continue;
            }
            $source .= (string) file_get_contents($file->getPathname());
        }

        return $source;
    }
}
