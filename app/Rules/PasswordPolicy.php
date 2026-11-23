<?php

declare(strict_types=1);

namespace App\Rules;

use RuntimeException;

/**
 * Parola kuralinin tek kaynagi (Faz 19.9, SEC-05 · K258): kayit, sifirlama ve degistirme bunu kullanir.
 * Ayrintili aciklama: docs/rehber/app/Rules/PasswordPolicy.md
 */
final class PasswordPolicy
{
    /** Sozlesmedeki kural adi (D6): istemci `validation.rules.uncommon_password` cevirisini gosterir. */
    public const UNCOMMON = 'uncommon_password';

    public const MIN_LENGTH = 8;

    private const LIST_PATH = 'security/common-passwords.txt';

    /** @var array<string, true>|null Surec basina bir kez yuklenir. */
    private static ?array $common = null;

    /**
     * `required` ve `string` istek sinifinda kalir; bu liste onlardan sonra gelir.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['min:'.self::MIN_LENGTH, 'max:255', self::UNCOMMON];
    }

    /** Yaygin listede mi ya da tek karakterin tekrari mi (`aaaaaaaa`)? Buyuk/kucuk harf ayrimi yok. */
    public static function isCommon(string $password): bool
    {
        $normalized = mb_strtolower($password);

        if (count(array_unique(mb_str_split($normalized))) <= 1) {
            return true;
        }

        return isset(self::commonPasswords()[$normalized]);
    }

    /** @return array<string, true> */
    private static function commonPasswords(): array
    {
        if (self::$common === null) {
            $lines = file(resource_path(self::LIST_PATH), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            if ($lines === false) {
                throw new RuntimeException('Common password list is missing: resources/'.self::LIST_PATH);
            }

            self::$common = array_fill_keys($lines, true);
        }

        return self::$common;
    }
}
