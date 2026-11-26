<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\HashManager;
use SensitiveParameter;

/**
 * Test yardımcısı (Faz 21.2): gerçek HashManager'a devreder, `make()` çağrılarını sayar.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/RegistrationEnumerationBrakesTest.md (son bölüm)
 */
final class CountingHasher implements Hasher
{
    public int $makes = 0;

    public function __construct(private readonly HashManager $real) {}

    /**
     * @param  string  $hashedValue
     *
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        return $this->real->info($hashedValue);
    }

    /**
     * @param  string  $value
     * @param  array<string, mixed>  $options
     */
    public function make(#[SensitiveParameter] $value, array $options = []): string
    {
        $this->makes++;

        return $this->real->make($value, $options);
    }

    /**
     * @param  string  $value
     * @param  string  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        return $this->real->check($value, $hashedValue, $options);
    }

    /**
     * @param  string  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->real->needsRehash($hashedValue, $options);
    }

    /** `hashed` cast'i düz parolada önce bunu sorar (false → make). */
    public function isHashed(string $value): bool
    {
        return $this->real->isHashed($value);
    }
}
