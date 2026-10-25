<?php

declare(strict_types=1);

namespace App\Services\Payment;

use InvalidArgumentException;

/**
 * "Bu alanları bu adrese POST et" — Shopier'in klasik ödeme formu (Faz 12).
 *
 * Alanların bir kısmı imzanın içindedir (tutar, sipariş kimliği). Değişmezlik
 * bu yüzden bir kolaylık değil: nesne kurulduktan sonra bir alanın
 * değiştirilebilmesi, imzayla uyuşmayan bir form üretebilmek demekti.
 *
 * 🔴 Kurucu iki şeyi sınar:
 *   - adres mutlak bir http(s) adresi (form başka bir siteye gidiyor)
 *   - her anahtar ve her değer STRING. HTML formunda sayı diye bir şey yok;
 *     `100` ile `"100"` imzada aynı, JSON'da farklı. Tek tip, tek yorum.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/CheckoutAction.md
 */
final readonly class FormPostAction implements CheckoutAction
{
    /** @var array<string, string> */
    private array $fields;

    /**
     * @param  array<mixed>  $fields  Alan adı => değer; ikisi de string olmalı
     */
    public function __construct(private string $url, array $fields)
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['https', 'http'], true)) {
            throw new InvalidArgumentException('A form post action needs an absolute http(s) URL.');
        }

        if ($fields === []) {
            throw new InvalidArgumentException('A form post action needs at least one field.');
        }

        $clean = [];

        foreach ($fields as $name => $value) {
            if (! is_string($name) || $name === '' || ! is_string($value)) {
                throw new InvalidArgumentException('Form field names must be non-empty strings and values must be strings.');
            }

            $clean[$name] = $value;
        }

        $this->fields = $clean;
    }

    public function url(): string
    {
        return $this->url;
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
