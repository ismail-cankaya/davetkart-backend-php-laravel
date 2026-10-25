<?php

declare(strict_types=1);

namespace App\Services\Payment;

use InvalidArgumentException;

/**
 * "Bu adrese git" — FakeGateway'in ve hediye siparişinin (Faz 11) sonucu.
 *
 * Adres göreli olabilir (`/odeme/basarili?order=…`): frontend kendi
 * rotasına gider. Boş adres ise hiçbir yere gitmemektir; kurucuda reddedilir.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/CheckoutAction.md
 */
final readonly class RedirectAction implements CheckoutAction
{
    public function __construct(private string $url)
    {
        if (trim($url) === '') {
            throw new InvalidArgumentException('A redirect action needs a URL.');
        }
    }

    public function url(): string
    {
        return $this->url;
    }
}
