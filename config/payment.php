<?php

declare(strict_types=1);

/**
 * Ödeme sağlayıcı ayarları ve sırları.
 *
 * Sırlar SADECE bu dosya üzerinden okunur; Service sınıfı dışında kimse erişmez.
 * Ayrıntılı açıklama: docs/rehber/config/payment.md
 */

return [

    // Aktif sağlayıcı. Servis sağlayıcıda bu anahtara göre Gateway sınıfı bind edilir.
    'default' => env('PAYMENT_PROVIDER', 'fake'),

    'providers' => [

        // Geliştirme/test sağlayıcısı. Dış ağ çağrısı yapmaz, her ödemeyi başarılı sayar.
        'fake' => [
            'driver' => 'fake',
        ],

        'iyzico' => [
            'driver' => 'iyzico',
            'api_key' => env('IYZICO_API_KEY'),
            'secret_key' => env('IYZICO_SECRET_KEY'),
            'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
            'webhook_secret' => env('IYZICO_WEBHOOK_SECRET'),
        ],
    ],

    // Ödeme sonrası kullanıcının döneceği frontend rotaları.
    'return_urls' => [
        'success' => env('PAYMENT_SUCCESS_URL', '/odeme/basarili'),
        'failure' => env('PAYMENT_FAILURE_URL', '/odeme/hata'),
    ],

    // Webhook doğrulama. İmzasız webhook = "ödeme başarılı" POST'unu herkes atabilir.
    'webhook' => [
        'signature_header' => 'X-Signature',
        'tolerance_seconds' => 300, // replay attack penceresi
    ],

    // Ödenmemiş order'ın geçerlilik süresi (dakika). Süre dolunca 'failed' işaretlenir.
    'order_expires_after_minutes' => 30,

];
