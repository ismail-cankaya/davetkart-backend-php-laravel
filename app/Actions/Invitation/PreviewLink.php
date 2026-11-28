<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use Carbon\CarbonImmutable;

/** "Telefonda aç" bağlantısı (Faz 29.4, K328): frontend adresi ve bitiş anı. */
final readonly class PreviewLink
{
    public function __construct(
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
