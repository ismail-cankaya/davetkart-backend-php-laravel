<?php

declare(strict_types=1);

/**
 * Ödeme sağlayıcı ayarları ve sırları.
 *
 * Sırlar SADECE bu dosya üzerinden okunur; Service sınıfı dışında kimse erişmez.
 * Ayrıntılı açıklama: docs/rehber/config/payment.md
 */

return [

    // Aktif sağlayıcı: 'fake' | 'shopier'. Bilinmeyen değer 503 verir (K70).
    'default' => env('PAYMENT_PROVIDER', 'fake'),

    'providers' => [

        // Geliştirme/test sağlayıcısı. Dış ağ çağrısı yapmaz, her ödemeyi başarılı sayar.
        'fake' => [
            'driver' => 'fake',
        ],

        // Faz 12: Shopier klasik "API ile ödeme" akışı (K94, K117–K125).
        'shopier' => [
            'driver' => 'shopier',

            // 🔴 İkisinden biri boşsa sürücü KURULMAZ -> 503 (K123). Boş sırla
            // hesaplanan HMAC'i herkes üretebilir.
            'api_key' => env('SHOPIER_API_KEY'),
            'api_secret' => env('SHOPIER_API_SECRET'),

            // Shopier panelindeki site sırası; tek siteli hesapta 1.
            'website_index' => (int) env('SHOPIER_WEBSITE_INDEX', 1),

            // Sabit: ortamdan ortama değişen bir değer değil (test ortamı yok, §A-f).
            'payment_url' => 'https://www.shopier.com/ShowProduct/api_pay4.php',

            // 🔴 'osb' (varsayılan, fail-safe) | 'callback' (K117).
            //   osb      -> siparişi YALNIZCA imzalı OSB bildirimi 'paid' yapar;
            //               tarayıcı callback'i yalnızca yönlendirir
            //   callback -> imzası doğrulanmış tarayıcı callback'i de durumu işler.
            //               'status' imzasız olduğu için bilinçli bir risk (K117)
            'confirmation' => env('SHOPIER_CONFIRMATION', 'osb'),

            // Otomatik Sipariş Bildirimi kimlikleri. 'osb' modunda zorunlu.
            'osb' => [
                'username' => env('SHOPIER_OSB_USERNAME'),
                'key' => env('SHOPIER_OSB_KEY'),
            ],

            // Dijital ürün: TCKN, telefon ve adres bizde yok (users tablosu). Shopier
            // alanları istiyor; yer tutucu gider (K125). §A-e doğrulanınca güncelle.
            'buyer_placeholders' => [
                'id_nr' => '11111111110',
                'phone' => '05000000000',
                'address' => 'Dijital urun - teslimat adresi yok',
                'city' => 'Istanbul',
                'country' => 'Turkey',
                'postcode' => '34000',
            ],
        ],
    ],

    // Ödeme sonrası kullanıcının döneceği frontend rotaları.
    // 🔴 Shopier'de MUTLAK adres olmalı: callback ucu kullanıcıyı buraya 303 ile
    // gönderir; göreli adres API'nin alan adına çözülürdü.
    'return_urls' => [
        'success' => env('PAYMENT_SUCCESS_URL', '/odeme/basarili'),
        'failure' => env('PAYMENT_FAILURE_URL', '/odeme/hata'),
    ],

    // Ödenmemiş order'ın geçerlilik süresi (dakika). Süre dolunca `orders:expire`
    // onu 'expired' işaretler (Faz 10, K89 — 'failed' DEĞİL: geç gelen ödeme
    // siparişi yine 'paid' yapabilir).
    'order_expires_after_minutes' => 30,

];
