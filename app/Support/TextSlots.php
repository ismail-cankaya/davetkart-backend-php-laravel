<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Düzenlenebilir davetiye metinleri sözleşmesi (`contracts/text-slots.json`, K301).
 *
 * Sözleşme frontend'de üretilir (`npm run text-slots:export`) ve buraya AYNEN kopyalanır.
 * Ayrıntı: docs/rehber/app/Support/TextSlots.md
 */
final class TextSlots
{
    /** Hata zarfindaki kural adi (D6). */
    public const RULE = 'text_overrides';

    private const PATH = 'contracts/text-slots.json';

    /** @var array{version: int, locales: list<string>, maxOverrides: int, slots: array<string, array{group: string, scope: string, maxLength: int, multiline: bool, placeholders?: list<string>}>}|null */
    private static ?array $contract = null;

    /** @return array{group: string, scope: string, maxLength: int, multiline: bool, placeholders?: list<string>}|null */
    public static function slot(string $key): ?array
    {
        return self::contract()['slots'][$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::contract()['slots']);
    }

    /** @return list<string> */
    public static function locales(): array
    {
        return self::contract()['locales'];
    }

    public static function maxOverrides(): int
    {
        return self::contract()['maxOverrides'];
    }

    /**
     * Gelen değerin sözleşmeye uyup uymadığı. Yalnızca evet/hayır: hata zarfı alan + kural adı taşır (D6).
     */
    public static function isValidOverrides(mixed $value): bool
    {
        $value = self::restoreDots($value);

        if ($value === null) {
            return true;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return false;
        }

        foreach ($value as $locale => $texts) {
            if (! in_array($locale, self::locales(), true) || ! is_array($texts)) {
                return false;
            }

            if ($texts !== [] && array_is_list($texts)) {
                return false;
            }

            if (count($texts) > self::maxOverrides()) {
                return false;
            }

            foreach ($texts as $key => $text) {
                if (! is_string($key) || ! self::isValidText($key, $text)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Laravel doğrulama sırasında veri anahtarlarındaki noktayı geçici bir yer tutucuyla değiştirir
     * (`rsvp.submit` → `rsvp__dot__<rastgele>submit`); kural anahtarı sözleşmede bulamazdı.
     * `validated()` gerçek anahtarları geri verir; yalnızca kuralın gördüğü değer düzeltilir.
     */
    private static function restoreDots(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $restored = [];
        foreach ($value as $key => $item) {
            $restored[is_string($key) ? (string) preg_replace('/__dot__[A-Za-z0-9]{16}/', '.', $key) : $key] = self::restoreDots($item);
        }

        return $restored;
    }

    private static function isValidText(string $key, mixed $text): bool
    {
        $slot = self::slot($key);

        if ($slot === null || ! is_string($text) || trim($text) === '') {
            return false;
        }

        if (mb_strlen($text) > $slot['maxLength']) {
            return false;
        }

        // Satır sonu yalnızca çok satırlı anahtarda; diğer kontrol karakterleri hiçbir yerde (K304).
        $control = $slot['multiline'] ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        if (preg_match($control, $text) === 1) {
            return false;
        }

        foreach ($slot['placeholders'] ?? [] as $name) {
            if (substr_count($text, '{'.$name.'}') !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Saklanacak biçim: boş dil grupları düşer; hiç metin kalmadıysa null (K303).
     *
     * @param  array<string, array<string, string>>|null  $value
     *
     * @return array<string, array<string, string>>|null
     */
    public static function normalize(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $kept = array_filter($value, static fn (mixed $texts): bool => is_array($texts) && $texts !== []);

        return $kept === [] ? null : $kept;
    }

    /** @return array{version: int, locales: list<string>, maxOverrides: int, slots: array<string, array{group: string, scope: string, maxLength: int, multiline: bool, placeholders?: list<string>}>} */
    private static function contract(): array
    {
        if (self::$contract === null) {
            $raw = file_get_contents(base_path(self::PATH));

            if ($raw === false) {
                throw new RuntimeException('Text slot contract is missing: '.self::PATH);
            }

            /** @var array{version: int, locales: list<string>, maxOverrides: int, slots: array<string, array{group: string, scope: string, maxLength: int, multiline: bool, placeholders?: list<string>}>} $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::$contract = $decoded;
        }

        return self::$contract;
    }
}
