<?php

declare(strict_types=1);

namespace App\Support\Media;

use Illuminate\Support\Facades\Config;

/**
 * Bellek sınırını iş süresince `optimize.memory_limit`'e yükseltir ve bitince GERİ yükler; sınırı asla düşürmez.
 * Ayrıntılı açıklama: docs/rehber/app/Support/Media/MemoryBudget.md
 */
final class MemoryBudget
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     *
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        $budget = Config::string('davetkart.media.optimize.memory_limit');
        $current = ini_get('memory_limit');

        // Sınırsız (-1) ya da bütçenin üstündeyse dokunma: iş bulunduğu ortamın sınırını düşürmemeli.
        $shouldRaise = is_string($current)
            && ini_parse_quantity($current) > 0
            && ini_parse_quantity($current) < ini_parse_quantity($budget);

        if (! $shouldRaise) {
            return $callback();
        }

        ini_set('memory_limit', $budget);

        try {
            return $callback();
        } finally {
            ini_set('memory_limit', $current);
        }
    }
}
