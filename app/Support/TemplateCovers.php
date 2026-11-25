<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * K235 (B-E4): sablon kimligi -> kapagin frontend'deki sabit yolu; dizin yoksa ya da bozuksa null (genel poster).
 * Ayrintili aciklama: docs/rehber/app/Support/TemplateCovers.md
 */
final class TemplateCovers
{
    private const string PATH_PATTERN = '#^/covers/[a-z0-9-]+\.(jpg|jpeg|png|webp)$#';

    /** @var array<string, string>|null */
    private ?array $index = null;

    public function pathFor(string $preset): ?string
    {
        $path = $this->index()[$preset] ?? null;

        return $path !== null && preg_match(self::PATH_PATTERN, $path) === 1 ? $path : null;
    }

    /** @return array<string, string> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $file = Config::string('covers.index');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return $this->index = is_array($data)
            ? array_filter($data, fn (mixed $v, mixed $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH)
            : [];
    }
}
