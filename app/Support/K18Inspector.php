<?php

declare(strict_types=1);

namespace App\Support;

/**
 * K18 kuralini tek dosyada olcer (Faz 19.28, ARC-03 · K259): 3 satirdan uzun yorum blogu ve eksik kilavuz.
 * Ayrintili aciklama: docs/rehber/app/Support/K18Inspector.md
 */
final class K18Inspector
{
    public const MAX_LINES = 3;

    /** Denetlenen kokler; kilavuz yolu `docs/rehber/<ayni-yol>.md`. */
    public const ROOTS = ['app/', 'routes/', 'config/', 'database/'];

    /** Tip ek aciklamasi sayilan docblock etiketleri (PHPStan/Psalm on ekleri ayrica). */
    private const TYPE_TAGS = [
        'param', 'return', 'var', 'property', 'property-read', 'property-write', 'template', 'template-covariant',
        'template-contravariant', 'extends', 'implements', 'use', 'mixin', 'method', 'throws', 'phpstan', 'psalm',
    ];

    public function __construct(private readonly string $root) {}

    public static function applies(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return str_ends_with($path, '.php') && array_any(self::ROOTS, fn (string $r): bool => str_starts_with($path, $r));
    }

    public static function guidePath(string $path): string
    {
        return 'docs/rehber/'.substr(str_replace('\\', '/', $path), 0, -4).'.md';
    }

    /** @return list<string> Insan okunur ihlaller; bos liste = temiz. */
    public function inspect(string $path): array
    {
        $problems = [];

        if (! is_file($this->root.'/'.self::guidePath($path))) {
            $problems[] = 'kilavuz yok: '.self::guidePath($path);
        }

        foreach ($this->longBlocks((string) file_get_contents($this->root.'/'.$path)) as [$line, $count]) {
            $problems[] = "satir {$line}: {$count} satirlik yorum blogu (en cok ".self::MAX_LINES.')';
        }

        return $problems;
    }

    /** @return list<array{int, int}> [baslangic satiri, icerik satiri] */
    public function longBlocks(string $source): array
    {
        $blocks = [];
        $start = $end = 0;
        $count = 0;

        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                if (is_array($token) && $token[0] === T_WHITESPACE && substr_count($token[1], "\n") <= 1) {
                    continue;
                }
                $this->close($blocks, $start, $count);
                $count = 0;

                continue;
            }

            $lines = explode("\n", rtrim($token[1], "\n"));
            if ($count === 0 || $token[2] > $end + 1) {
                $this->close($blocks, $start, $count);
                $start = $token[2];
                $count = 0;
            }
            $count += $this->contentLines($lines);
            $end = $token[2] + count($lines) - 1;
        }
        $this->close($blocks, $start, $count);

        return $blocks;
    }

    /** @param list<array{int, int}> $blocks */
    private function close(array &$blocks, int $start, int $count): void
    {
        if ($count > self::MAX_LINES) {
            $blocks[] = [$start, $count];
        }
    }

    /** @param list<string> $lines */
    private function contentLines(array $lines): int
    {
        $count = 0;
        $depth = 0;

        foreach ($lines as $raw) {
            $text = trim((string) preg_replace('#^\s*(/\*\*?|\*/|\*|//|\#)#', '', $raw));
            $text = trim((string) preg_replace('#\*/$#', '', $text));
            $isTag = $depth > 0 || preg_match('/^@([a-z-]+)/', $text, $m) === 1 && $this->isTypeTag($m[1]);
            if ($isTag) {
                $depth = max(0, $depth + substr_count($text, '{') + substr_count($text, '<') + substr_count($text, '(')
                    - substr_count($text, '}') - substr_count($text, '>') - substr_count($text, ')'));
            } elseif ($text !== '') {
                $count++;
            }
        }

        return $count;
    }

    private function isTypeTag(string $tag): bool
    {
        return in_array($tag, self::TYPE_TAGS, true) || str_starts_with($tag, 'phpstan-') || str_starts_with($tag, 'psalm-');
    }
}
