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

    // Saat dilimi belirtmemis davetiyelerin varsayilani (K63).
    // Bir IS TERCIHIDIR (E6): pazar degisirse kod degismemeli.
    'default_timezone' => env('DAVETKART_DEFAULT_TIMEZONE', 'Europe/Istanbul'),

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

        // 🔴 Misafirin yukleme ucu icin hiz siniri (6.16). LCV metninden AYRI
        // ve daha DAR: orada honeypot ilk savunmaydi, dosya yuklemede oyle bir
        // katman YOK (bkz. StoreGuestMediaAction kilavuzu §2). Ustelik bir
        // istek yuzlerce KB degil onlarca MB tasiyor.
        'rate_limit' => [
            'guest_per_ip_per_minute' => 5,
            'guest_per_invitation_per_hour' => 40,
        ],

        // 🔴 Bir misafir yuklemesinin, hicbir LCV yanitina baglanmadan diskte
        // bekleyebilecegi sure (saat). Suresi dolan yuklemeler
        // `media:prune-orphans` tarafindan silinir.
        //
        // Neden 24? Misafir dosyayi yukler, sonra formu doldurur — arada
        // dakikalar gecer, saatler degil. 24 saat comert bir tampon: bir
        // sekmeyi acik unutan kullaniciyi bile korur. Kisaltmak disk kazandirir
        // ama bir misafirin fotografini elinden alma riskini buyutur; bu
        // takasta yanlis tarafa dusmek UCUZ olan taraftir.
        'orphan_grace_hours' => 24,

        // Kuyruktaki OptimizeUploadedImage isinin ayarlari. Telefon kameralari
        // 4000+ piksel uretiyor; galeride 2000 fazlasiyla yeterli.
        'optimize' => [
            'max_width_px' => 2000,
            'jpeg_quality' => 82,
        ],

        'gallery' => [
            'max_size_kb' => 5120,
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_per_invitation' => 30,
        ],
        // 🔴 max_per_invitation her turde ZORUNLU: LCV medyasini kimligi
        // bilinmeyen misafir yukluyor. Hiz siniri "ne siklikta"ya bakar, kota
        // "ne kadar"a — ikisi birbirinin yerine gecmez (L3). Bu sinir olmasa
        // gonderim yapmadan yuklenen "yetim" dosyalarla disk doldurulabilirdi.
        'rsvp_photo' => [
            'max_size_kb' => 2048,
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_per_invitation' => 200,
        ],
        'rsvp_video' => [
            'max_size_kb' => 20480,
            'mimes' => ['video/mp4', 'video/quicktime'],
            'max_per_invitation' => 100,
        ],
    ],

    // Siparis politikasi.
    'orders' => [
        // 🔴 Yayinlanmis bir davetiye silinince, o davetiye icin odenmis TEKIL
        // siparisin hakki geri alinabilir mi? Pencere YAYIN anindan itibaren
        // sayilir; kapaliysa hak yanar (DeleteInvitationAction).
        //
        // env() BILEREK YOK — default_timezone'dan farkli olarak bu bir ORTAM
        // farki degil, kullaniciya verilmis bir TICARI SOZ. env'e baglansaydi
        // staging'de 30, uretimde 3 olabilir ve ikisi sessizce ayrisirdi;
        // "kac gun" sorusunun tek bir dogru cevabi var ve o cevap repoda,
        // degisiklik gecmisiyle birlikte durmali.
        'release_window_days' => 3,
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

        // 🔴 Hız sınırı kotanın YERİNE GEÇMEZ (L3). Kota "bugün kaç mesaj"a
        // bakar ve veritabanında sayılır (cache silinse bile durur); bu kova
        // "ne sıklıkta"ya bakar ve en ucuz katman olarak en başta durur (L1).
        // Kotasız bir dakikada 30 çağrı, günlük bütçeyi tek seferde yakardı.
        'rate_limit' => [
            'per_user_per_minute' => 6,
        ],
    ],

    // İletişim formu: sistemin DÖRDÜNCÜ auth'suz yazma yolu.
    'contact' => [
        'max_message_chars' => 2000,

        // LCV'den (10/dk) daha DAR: meşru bir kullanıcı iletişim formunu
        // günde bir kez doldurur, LCV gönderimi ise bir davetiye için onlarca
        // misafirden gelir. Üst kaynak (davetiye) kovası YOK — bu formun
        // bağlı olduğu bir davetiye yok; tek anahtar IP.
        'rate_limit' => [
            'per_ip_per_minute' => 3,
            'per_ip_per_hour' => 10,
        ],
    ],

];
