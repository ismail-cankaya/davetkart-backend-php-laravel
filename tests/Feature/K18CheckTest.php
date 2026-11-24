<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\K18Inspector;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Faz 19.28 (ARC-03, K259): K18 cırcırı. Ayrinti: docs/rehber/tests/Feature/K18CheckTest.md */
final class K18CheckTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/k18-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->root.'/app/Demo');
        File::ensureDirectoryExists($this->root.'/docs/rehber/app/Demo');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function a_new_file_with_a_long_block_and_no_guide_fails(): void
    {
        $this->source('app/Demo/Long.php', "// bir\n// iki\n// uc\n// dort\n\$x = 1;\n");

        $problems = (new K18Inspector($this->root))->inspect('app/Demo/Long.php');

        $this->assertSame([
            'kilavuz yok: docs/rehber/app/Demo/Long.md',
            'satir 2: 4 satirlik yorum blogu (en cok 3)',
        ], $problems);
    }

    #[Test]
    public function a_short_commented_file_with_a_guide_passes(): void
    {
        $this->source('app/Demo/Short.php', "/**\n * bir\n * iki\n * uc\n */\nfinal class Short {}\n");
        File::put($this->root.'/docs/rehber/app/Demo/Short.md', "# Short\n");

        $this->assertSame([], (new K18Inspector($this->root))->inspect('app/Demo/Short.php'));
    }

    #[Test]
    public function type_annotations_in_docblocks_are_not_counted(): void
    {
        $source = "<?php\n/**\n * Kisa aciklama.\n *\n * @template T of object\n * @param array<string, int> \$a\n"
            ." * @return array{\n *     id: int,\n *     name: string,\n * }\n * @phpstan-assert-if-true string \$a\n */\nfunction f() {}\n";

        $this->assertSame([], (new K18Inspector($this->root))->longBlocks($source));
    }

    #[Test]
    public function blank_lines_split_blocks_and_code_ends_them(): void
    {
        $source = "<?php\n// a\n// b\n\n// c\n// d\n\$x = 1;\n// e\n// f\n";

        $this->assertSame([], (new K18Inspector($this->root))->longBlocks($source));
        $this->assertSame([[2, 4]], (new K18Inspector($this->root))->longBlocks("<?php\n// a\n# b\n/* c */\n// d\n"));
    }

    #[Test]
    public function only_code_roots_are_checked(): void
    {
        $this->assertTrue(K18Inspector::applies('app/Actions/X.php'));
        $this->assertTrue(K18Inspector::applies('database/migrations/2026_x.php'));
        $this->assertFalse(K18Inspector::applies('tests/Feature/X.php'));
        $this->assertFalse(K18Inspector::applies('app/views/x.blade.md'));
    }

    #[Test]
    public function the_command_passes_for_a_clean_file_and_fails_for_a_long_block(): void
    {
        $this->artisan('k18:check', ['paths' => ['app/Rules/PasswordPolicy.php', 'tests/Feature/K18CheckTest.php']])
            ->expectsOutput('k18: 1 dosya, 0 ihlal')
            ->assertExitCode(0);

        $file = 'database/k18-'.bin2hex(random_bytes(4)).'.php';
        File::put(base_path($file), "<?php\n// a\n// b\n// c\n// d\n");
        try {
            $this->artisan('k18:check', ['paths' => [$file]])->expectsOutput('k18: 1 dosya, 2 ihlal')->assertExitCode(1);
        } finally {
            File::delete(base_path($file));
        }
    }

    #[Test]
    public function an_unknown_base_fails_closed(): void
    {
        $this->artisan('k18:check', ['--base' => 'no-such-ref-k18'])->assertExitCode(1);
    }

    private function source(string $path, string $body): void
    {
        File::put($this->root.'/'.$path, "<?php\n".$body);
    }
}
