<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\K18Inspector;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * K259 (ARC-03): tabandan sonra degisen dosyalarda K18 cırcırı; ihlalde cikis 1 (`composer check`).
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/CheckK18.md
 */
final class CheckK18 extends Command
{
    /** Cırcırın baslangici: 19.90'dan sonraki `faz-19-audit-fixes` ucu. */
    public const BASELINE = '0743874b716f60e9d0866a01fce0ca5baca5d7dd';

    protected $signature = 'k18:check
        {paths?* : Yalnizca bu dosyalar (git kullanilmaz)}
        {--base= : Taban referansi (varsayilan cırcırın baslangici)}';

    protected $description = 'Check K18 (short comments, a guide per file) in files changed since the ratchet base';

    public function handle(): int
    {
        $paths = $this->argument('paths');
        $paths = is_array($paths) ? array_values(array_filter($paths, is_string(...))) : [];
        $base = $this->option('base');
        $files = $paths === [] ? $this->changedFiles(is_string($base) && $base !== '' ? $base : self::BASELINE) : $paths;

        if ($files === null) {
            return self::FAILURE;
        }

        $inspector = new K18Inspector(base_path());
        $checked = $failed = 0;

        foreach (array_filter($files, K18Inspector::applies(...)) as $file) {
            if (! is_file(base_path($file))) {
                continue;
            }
            $checked++;
            foreach ($inspector->inspect($file) as $problem) {
                $failed++;
                $this->line("{$file}: {$problem}");
            }
        }

        $this->line("k18: {$checked} dosya, {$failed} ihlal");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string>|null Taban bulunamazsa null (kapali basarisizlik). */
    private function changedFiles(string $base): ?array
    {
        $mergeBase = $this->git(['merge-base', $base, 'HEAD']);
        if ($mergeBase === null) {
            $this->error("k18: taban bulunamadi ({$base}). CI'da tam gecmis gerekir (fetch-depth: 0).");

            return null;
        }

        $changed = $this->git(['diff', '--name-only', '--diff-filter=ACMR', trim($mergeBase)]);
        $untracked = $this->git(['ls-files', '--others', '--exclude-standard']);

        return array_values(array_unique(array_filter(explode("\n", ($changed ?? '')."\n".($untracked ?? '')))));
    }

    /** @param list<string> $args */
    private function git(array $args): ?string
    {
        $process = new Process(['git', ...$args], base_path());
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}
