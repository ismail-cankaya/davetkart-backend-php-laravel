<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Giris basarisiz. 🔴 SEBEBI NE ISTEMCIYE NE DE KODA soylenir (H6).
 *
 * Kurucu PARAMETRE ALMAZ: "kullanici yok" ile "parola yanlis" durumlari icin
 * farkli mesaj uretmek YAPISAL OLARAK imkansizdir. Enumeration savunmasi
 * hatirlanmaya degil, sinifin sekline baglanmistir.
 * Ayrintili aciklama: docs/rehber/app/Exceptions/InvalidCredentialsException.md
 */
final class InvalidCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Login failed: invalid email or password.');
    }
}
