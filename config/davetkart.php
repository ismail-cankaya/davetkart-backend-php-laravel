<?php

declare(strict_types=1);

/**
 * DavetKart iş kuralı sabitleri (plan fiyatları, kotalar, limitler).
 *
 * Kural: env() SADECE bu dosyada çağrılır; kod içinde config('davetkart...') kullanılır.
 * Ayrıntılı açıklama: docs/rehber/config/davetkart.md
 */

return [

    // Plan tanımları. rank = kapsama karşılaştırması, rsvp_limit null = sınırsız.
    'tiers' => [
        'standart' => ['rank' => 0, 'price' => 249, 'rsvp_limit' => 100],
        'gold' => ['rank' => 1, 'price' => 399, 'rsvp_limit' => null],
        'elit' => ['rank' => 2, 'price' => 549, 'rsvp_limit' => null],
    ],

    'currency' => 'TRY',

    // Modül → gereken plan. TierResolver bu haritayı okur; listelenmeyen modül 'standart' sayılır.
    'module_tiers' => [
        'show_gallery' => 'elit',
        'show_gift' => 'elit',
        'show_envelope' => 'gold',
        'show_timeline' => 'gold',
        'show_timer' => 'standart',
        'show_rsvp' => 'standart',
    ],

    // LCV limitleri. Kota SUM(guest_count) ile kıyaslanır, COUNT(*) ile değil.
    'rsvp' => [
        'max_guests_per_entry' => 10,
        'rate_limit' => [
            'per_ip_per_minute' => 10,
            'per_invitation_per_hour' => 60,
        ],
        'poll_interval_seconds' => 15,
    ],

    // Yükleme limitleri. mimes, uzantıya değil dosya içeriğine bakan kuralda kullanılır.
    'media' => [
        'disk' => env('DAVETKART_MEDIA_DISK', 'public'),

        'gallery' => [
            'max_size_kb' => 5120,
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_per_invitation' => 30,
        ],
        'rsvp_photo' => [
            'max_size_kb' => 2048,
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'rsvp_video' => [
            'max_size_kb' => 20480,
            'mimes' => ['video/mp4', 'video/quicktime'],
        ],
    ],

    // Public davetiye cache'i. Tazelik TTL ile değil, event ile sağlanır.
    'cache' => [
        'public_invitation_ttl' => 60 * 60 * 6, // saniye
        'key_prefix' => 'davetkart',
    ],

    'auth' => [
        'login_rate_limit_per_minute' => 5, // brute-force savunması
        'token_name' => 'davetkart-spa',
    ],

    // AI çağrısı ücretli; kotasız bırakmak finansal risktir.
    'assistant' => [
        'daily_message_limit_per_user' => 30,
        'max_prompt_chars' => 2000,
    ],

];
