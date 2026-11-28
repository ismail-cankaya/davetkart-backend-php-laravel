<?php

declare(strict_types=1);

namespace App\Providers;

use App\Rules\PasswordPolicy;
use App\Support\TextSlots;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

/**
 * Projeye ozel dize adli dogrulama kurallari (D6: kural nesnesi sinif adiyla raporlanir, adi sozlesmeye giremez).
 * Ayrintili aciklama: docs/rehber/app/Rules/PasswordPolicy.md
 */
final class ValidationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Validator::extend(
            PasswordPolicy::UNCOMMON,
            static fn (string $attribute, mixed $value): bool => is_string($value) && ! PasswordPolicy::isCommon($value),
        );

        // Faz 24 (K303): degistirilen davetiye metinleri sozlesmeye uyuyor mu.
        Validator::extend(
            TextSlots::RULE,
            static fn (string $attribute, mixed $value): bool => TextSlots::isValidOverrides($value),
        );
    }
}
