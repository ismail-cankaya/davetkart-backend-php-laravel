<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Models\Rsvp;

/**
 * Bir LCV gönderiminin sonucu: kayıt ve misafirin düzenleme kodu (Faz 10, 10.59 · K101).
 *
 * Kodun kendisi veritabanında yok (yalnızca özeti); misafire yalnızca bu
 * yanıtta ulaşır.
 */
final readonly class RsvpSubmission
{
    public function __construct(
        public Rsvp $rsvp,
        public string $editCode,
    ) {}
}
