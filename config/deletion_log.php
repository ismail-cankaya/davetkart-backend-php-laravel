<?php

declare(strict_types=1);

// K233 (B-E1): kullanicinin baslattigi silmelerin gunlugu; veritabaninin DISINDA durur ki
// yedekten geri yuklemeyle kaybolmasin. Ayrinti: docs/rehber/config/deletion_log.md
return [
    // Uretimde s3 (sunucu diskiyle birlikte geri yuklenmez); yerelde ozel 'local' disk.
    'disk' => env('DAVETKART_DELETION_LOG_DISK', 'local'),

    // En eski yedekten (30 + 7 gun) uzun; sonra data:purge siler. Gizlilik metni: "en gec 40 gun".
    'days' => 40,
];
