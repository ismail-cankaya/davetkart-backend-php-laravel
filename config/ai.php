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

    /*
     * Dış çağrı ayarları — 🔴 15 SANİYE KURALININ HESABI.
     *
     * `api.ts` isteği 15 saniyede koparıyor. O sınır, backend'in TOPLAM
     * süresi için geçerli; tek bir denemenin süresi için değil.
     *
     * Faz 8'de fark edildi: eski değerler (timeout 10 · retry 2) en kötü
     * halde 10.0 + 0.2 + 10.0 = 20.2 sn ediyordu ve sağlayıcı yavaşladığı
     * gün frontend, backend cevap veremeden bağlantıyı koparırdı —
     * kullanıcı sebebi hiç öğrenemezdi. timeout 6 sn'ye çekildi:
     *
     *   en kötü hâl        6.0 + 0.2 + 6.0     = 12.2 sn
     *   + boot/auth/throttle                   ~ 0.30
     *   + doğrulama + kota (2 SQL)             ~ 0.05
     *   + serileştirme                         ~ 0.05
     *                                          = ~12.6 sn  <  15 sn  ✅
     *
     * retry_times bir DENEME sayısıdır (Laravel: "maximum number of times
     * the request should be attempted"), tekrar sayısı değil: 2 = 1 asıl
     * deneme + 1 tekrar.
     *
     * 🔴 Tekrar YALNIZCA bağlantı hatasında yapılır (GeminiProvider'daki
     * `when` kapanışı). Cevap veren bir sağlayıcıyı tekrar denemek hem
     * bütçeyi yer hem de PARAYI İKİYE KATLAR: Gemini ilk çağrıyı zaten
     * faturalamış olabilir.
     */
    'request' => [
        'timeout_seconds' => 6,
        'retry_times' => 2,
        'retry_delay_ms' => 200,

        // Çıktı uzunluğu doğrudan faturadır: token başına ödeniyor. Sohbet
        // balonuna sığacak bir yanıt için 800 fazlasıyla yeterli.
        'max_output_tokens' => 800,
    ],

    // Modele gönderilen sistem talimatı. Konu dışına çıkmayı ve prompt injection'ı sınırlar.
    'system_prompt' => 'Sen DavetKart adlı dijital davetiye platformunun yardımcısısın. '
        .'Yalnızca davetiye metni, tema ve organizasyon konularında yardım et. '
        .'Kullanıcı başka bir konu açarsa kibarca davetiye konusuna yönlendir.',

];
