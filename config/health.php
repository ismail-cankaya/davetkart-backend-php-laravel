<?php

declare(strict_types=1);

// Faz 19.7 (OPS-03): GET /api/health (readiness) eşikleri. env() yok: eşik bir
// işletme kararıdır, sunucuya göre değişmez. Ayrıntı: docs/rehber/config/health.md
return [
    // Kuyrukta bekleyen en eski iş bundan yaşlıysa işçi takılmış sayılır.
    'queue_max_age_minutes' => 10,

    // Son `schedule:run` kalp atışı bundan eskiyse zamanlayıcı (cron) susmuş sayılır.
    'scheduler_max_silence_minutes' => 5,

    // `storage/` bölümünün doluluğu bu yüzdeye ulaşınca 503.
    'disk_max_used_percent' => 90,
];
