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
        // Faz 10 (10.60 · K104): 300 kişilik bir düğün davetiyesi aynı akşam
        // gönderilir; eski sayılar (IP 10/dk, davetiye 60/saat) ilk saatte
        // gelen yanıtların bir kısmını 429 ile geri çeviriyordu. Salonda
        // herkes aynı Wi-Fi'da, yani aynı IP'de.
        'rate_limit' => [
            'per_ip_per_minute' => 20,
            'per_invitation_per_hour' => 300,
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
        // Faz 10 (10.60 · K104): salon Wi-Fi'ı (tek IP) ve düğün gecesi için
        // büyütüldü (eski: IP 5/dk, davetiye 40/saat). LCV'den yine dar.
        'rate_limit' => [
            'guest_per_ip_per_minute' => 15,
            'guest_per_invitation_per_hour' => 150,
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

        // Kuyruktaki OptimizeUploadedImage isinin ayarlari.
        'optimize' => [
            // 🔴 EN UZUN KENAR, genislik DEGIL. Genisligi sinirlamak dikey
            // fotografi serbest birakiyordu: 3000x4000'lik bir kare 2000x2667
            // olarak kaliyordu — 5,3 MP, yani hedeflenenin bir buçuk kati.
            // 2000 nereden? Galeri en fazla 448 CSS px genisliginde gosteriliyor
            // (frontend Gallery.tsx, max-w-md); 3x ekranda bile 1344 px yeter.
            'max_edge_px' => 2000,

            // 🔴 Cozme (decode) belleginin ust siniri BU SATIRDIR: GD piksel
            // basina ~4 bayt ister, yani 8192x8192 ≈ 67 MP ≈ 270 MB. Dogrulama
            // bunu asan dosyayi hic kabul etmez (MediaRequest) ve is de ikinci
            // kez sorar. Kucuk bir dosyanin devasa piksel acmasi ("sikistirma
            // bombasi") misafir ucunda bir DoS yolu olurdu.
            'max_dimension_px' => 8192,

            // Hedef cikti boyutu. Kalite bu degerin altina inene kadar
            // basamak basamak dusurulur.
            'target_kb' => 2048,

            'jpeg_quality' => 82,
            'webp_quality' => 80,

            // Kalitenin inebilecegi taban. Altinda gorunur bozulma basliyor;
            // hedefe inilemezse dosya buyuk kalir, kalite feda edilmez.
            'min_quality' => 60,

            // 🔴 Is, gorseli cozerken bu bellegi ister ve isi bitince eski
            // degeri geri yukler. CLI varsayilani 128M'dir; 24 MP'lik bir
            // telefon fotografi (99 MB) isciyi oracikta cokertirdi.
            'memory_limit' => '512M',

            // 🔴 Optimize edilen dosya YENI bir yola yazilir; eski dosya hemen
            // silinmez. Editorun elindeki eski URL bu sure boyunca calismaya
            // devam eder — sayfa yenilenince yeni URL gelir.
            'replaced_file_grace_hours' => 24,
        ],

        'gallery' => [
            // 🔴 15 MB, "kullanicinin yukleyebilecegi en buyuk fotograf" degil
            // "sunucunun kabul ettigi en buyuk dosya"dir. Tarayici dosyayi
            // gondermeden once 2 MB'in altina indiriyor (frontend
            // utils/compressImage.ts); bu sinir o katman atlandiginda devrede.
            //
            // ⚠️ PHP'nin upload_max_filesize ve post_max_size degerleri bu
            // sinirdan BUYUK olmali, yoksa dosya Laravel'e hic ulasmaz
            // (docs/06, docs/10).
            'max_size_kb' => 15360,
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_per_invitation' => 30,
        ],
        // 🔴 max_per_invitation her turde ZORUNLU: LCV medyasini kimligi
        // bilinmeyen misafir yukluyor. Hiz siniri "ne siklikta"ya bakar, kota
        // "ne kadar"a — ikisi birbirinin yerine gecmez (L3). Bu sinir olmasa
        // gonderim yapmadan yuklenen "yetim" dosyalarla disk doldurulabilirdi.
        'rsvp_photo' => [
            // Galeriyle AYNI sinir: misafirin telefonu sahibin telefonundan
            // daha kucuk fotograf uretmiyor. Kota (max_per_invitation) ve hiz
            // siniri misafir tarafindaki farki zaten tasiyor.
            'max_size_kb' => 15360,
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

    // Faz 10 (10.30): frontend'in kok adresi. Maildeki baglantilar MUTLAK
    // olmak zorunda (parola sifirlama). Adres istegin Host basligindan DEGIL
    // buradan okunur: baslik istemcinin elinde (trustedproxy.md §4, B6).
    // Gelistirmede Vite 3000'de, API'yi proxy'liyor (frontend vite.config.ts).
    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:3000'),
        'password_reset_path' => '/sifre-sifirla',
    ],

    // Kullaniciya giden mail (K96): TEK dil, Turkce. K21'in (API tek dil,
    // metin dondurmez) BILINCLI istisnasi: mail kullanicinin dogrudan okudugu
    // bir metin ve kullanicinin dil tercihi bugun saklanmiyor.
    'mail' => [
        'locale' => 'tr',
    ],

    // Faz 10 (10.42 · K98 / S-1): saklama sureleri. KVKK'nin veri en aza
    // indirme ilkesi: amaci biten kisisel veri saklanmaz. `data:purge` her
    // gece bunlari uygular. env() YOK: ortam farki degil, kullaniciya verilen
    // bir soz (KVKK aydinlatma metni bu sayilari yazacak).
    'retention' => [
        // Cop kutusundaki (soft delete) davetiye bu sureden sonra KALICI silinir,
        // dosyalariyla birlikte. O zamana kadar geri getirilebilir.
        'deleted_invitation_days' => 30,

        // Etkinlikten bu kadar ay sonra MISAFIR verisi silinir: LCV satirlari
        // (ad, mesaj, menu tercihi) ve misafirin yukledigi foto/video.
        // Davetiyenin kendisi ve sahibinin galerisi kalir.
        'guest_data_months_after_event' => 6,

        // Iletisim formu mesajlari (ad, e-posta, mesaj).
        'contact_message_months' => 12,
    ],

];
