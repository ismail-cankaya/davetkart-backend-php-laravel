<?php

declare(strict_types=1);

/**
 * AI sağlayıcı ayarları ve API anahtarı.
 *
 * Anahtar SADECE AiProvider implementasyonuna ulaşır; frontend'e asla gönderilmez.
 * Kota ve uzunluk limitleri iş kuralıdır → config/davetkart.php 'assistant' bölümünde.
 * Ayrıntılı açıklama: docs/rehber/config/ai.md
 */

return [

    'default' => env('AI_PROVIDER', 'gemini'),

    'providers' => [

        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

        // Sağlayıcı yokken/kotada arıza varken sabit yanıt döndüren yedek sürücü.
        'null' => [
            'driver' => 'null',
        ],
    ],

    // Dış çağrı ayarları. timeout, api.ts'in 15 sn sınırının altında kalmalı.
    'request' => [
        'timeout_seconds' => 10,
        'retry_times' => 2,
        'retry_delay_ms' => 200,
    ],

    // Modele gönderilen sistem talimatı. Konu dışına çıkmayı ve prompt injection'ı sınırlar.
    'system_prompt' => 'Sen DavetKart adlı dijital davetiye platformunun yardımcısısın. '
        .'Yalnızca davetiye metni, tema ve organizasyon konularında yardım et. '
        .'Kullanıcı başka bir konu açarsa kibarca davetiye konusuna yönlendir.',

];
