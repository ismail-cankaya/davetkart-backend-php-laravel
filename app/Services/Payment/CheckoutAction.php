<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Kullanıcının ödeme sayfasına NASIL gideceği — iki biçimli bir sonuç (tagged union).
 *
 * İki uygulaması var ve başka olamaz:
 *   RedirectAction -> tarayıcı bir adrese gider (GET)
 *   FormPostAction -> tarayıcı imzalı alanları bir adrese POST eder (Shopier)
 *
 * Neden `?string $redirectUrl` + `?array $fields` değil? İki boş alanlı tek bir
 * sınıf "ikisi de dolu" ya da "ikisi de boş" gibi ANLAMSIZ durumları temsil
 * edebilirdi; her okuyan onları ayrıca sınamak zorunda kalırdı. Ayrı sınıflar
 * o durumları YAZILAMAZ kılar (Faz 12, K118).
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/CheckoutAction.md
 */
interface CheckoutAction
{
    /** Tarayıcının gideceği (ya da formu göndereceği) adres. */
    public function url(): string;
}
