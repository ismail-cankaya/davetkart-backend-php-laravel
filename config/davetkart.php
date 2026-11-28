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

    // Faz 13: fiyat indirimleri. Yukaridaki 'price' LISTE fiyatidir; musteriye
    // gosterilen ve kartindan cekilen tutar App\Actions\Pricing\QuotePriceAction'da
    // Ayrıntı: docs/rehber/config/davetkart.md §K18-1
    'pricing' => [
        // Kullanicinin ILK yayini (hic odenmis siparisi yok — hediye kodlu
        // ucretsiz siparis dahil, K131). 0 = kapali. Ust sinir 90: tutar
        // Ayrıntı: docs/rehber/config/davetkart.md §K18-2
        'first_publish_percent' => 60,
    ],

    // Faz 10 (10.66 · K102): "Logosuz ozel yayin" (fiyat karti, Elit). Bu plan
    // ve ustu odenmis davetiyede misafir sayfasi "DavetKart ile hazirlandi"
    // imzasini gostermez.
    'branding' => [
        'white_label_tier' => 'elit',
    ],

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
        // Faz 24 (K305): sabit sablon metinlerini degistirmek en ust pakette.
        // Kolon bir modul degil ama ayni soruyu sorar: "bu davetiye hangi plani ister?"
        'has_custom_texts' => 'elit',
    ],

    // Faz 10 (10.66 · K102): tema -> gereken en dusuk plan. Fiyat karti Standart'a
    // "Temel sablon koleksiyonu", Gold ve Elit'e "Premium tema koleksiyonu" diyor.
    // Ayrıntı: docs/rehber/config/davetkart.md §K18-3
    'preset_tiers' => [
        'dugun-gokyuzu' => 'gold',
        'dugun-mum-isigi' => 'gold',
        'dugun-gul-yapraklari' => 'gold',
        'dugun-deniz-isiltisi' => 'gold',
        'dugun-sahil' => 'gold',
        'dugun-onyx' => 'gold',
        'kina-bordo' => 'gold',
        'nisan-sampanya' => 'gold',
        'sunnet-yildiz' => 'gold',
        'dogum-gunu-konfeti' => 'gold',
        'mezuniyet-lacivert' => 'gold',
        'baby-shower-kabarcik' => 'gold',
        'parti-aurora' => 'gold',
        // Faz 16: premium sinematik koleksiyon (kodla uretilmis videolar,
        // frontend scripts/video/<id>/). Frontend minimumTier listesiyle birebir.
        'dugun-saray-kapisi' => 'gold',
        'dugun-yasemin' => 'gold',
        'dugun-kristal-balo' => 'gold',
        'nisan-iplik' => 'gold',
        'kina-al-duvak' => 'gold',
        'sunnet-sehzade' => 'gold',
        'dogum-gunu-havai-fisek' => 'gold',
        'mezuniyet-kep' => 'gold',
        'baby-ay-besigi' => 'gold',
        'parti-havuz' => 'gold',
        // Faz 17: premium koleksiyon. Dördü kodla üretilmiş videolu, beşi canlı
        // WebGL / sensörlü etkileşimli (İsmail'in onayı, 2026-11-05: premium
        // Ayrıntı: docs/rehber/config/davetkart.md §K18-4
        'kurumsal-lansman' => 'gold',
        'kina-bugulu-ayna' => 'gold',
        'sunnet-ucan-hali' => 'gold',
        'parti-lazer' => 'gold',
        'dugun-kelebek' => 'gold',
        'dugun-karanlik-oda' => 'gold',
        'nisan-koi' => 'gold',
        'parti-kokteyl' => 'gold',
        'mezuniyet-yillik' => 'gold',
    ],

    // LCV limitleri. Kota SUM(guest_count) ile kıyaslanır, COUNT(*) ile değil.
    'rsvp' => [
        'max_guests_per_entry' => 10,
        // Faz 10 (10.60 · K104): 300 kişilik bir düğün davetiyesi aynı akşam
        // gönderilir; eski sayılar (IP 10/dk, davetiye 60/saat) ilk saatte
        // Ayrıntı: docs/rehber/config/davetkart.md §K18-5
        'rate_limit' => [
            'per_ip_per_minute' => 20,
            'per_invitation_per_hour' => 300,
        ],
        'poll_interval_seconds' => 15,
    ],

    // Faz 19 (19.26 · K192, FE-50): kullanıcı başına yayınlanmamış (taslak) davetiye
    // tavanı. Yayındakiler sayılmaz. Sihirbaz her ziyarette boş taslak açabiliyordu.
    'invitations' => [
        'max_drafts_per_user' => 50,
    ],

    // Faz 19 (19.12 · K189, FE-51): misafirin LCV'ye foto/video yüklemesi.
    // Kapalıyken POST /api/public/invitations/{id}/media 404 döner ve arayüzde
    // yükleme alanı yok. Açmak bir ürün kararıdır (kota ve bayt bütçesi: SEC-08).
    'guest_uploads' => [
        'enabled' => (bool) env('DAVETKART_GUEST_UPLOADS', false),
    ],

    // Yükleme limitleri. mimes, uzantıya değil dosya içeriğine bakan kuralda kullanılır.
    'media' => [
        'disk' => env('DAVETKART_MEDIA_DISK', 'public'),

        // 🔴 Misafirin yukleme ucu icin hiz siniri (6.16). LCV metninden AYRI
        // ve daha DAR: orada honeypot ilk savunmaydi, dosya yuklemede oyle bir
        // Ayrıntı: docs/rehber/config/davetkart.md §K18-6
        'rate_limit' => [
            'guest_per_ip_per_minute' => 60,
            'guest_per_invitation_per_hour' => 150,
        ],

        // 🔴 Bir misafir yuklemesinin, hicbir LCV yanitina baglanmadan diskte
        // bekleyebilecegi sure (saat). Suresi dolan yuklemeler
        // Ayrıntı: docs/rehber/config/davetkart.md §K18-7
        'orphan_grace_hours' => 1,

        // Faz 19 (19.13 · K190, SEC-08): kimliksiz misafir için BAYT ve BEKLEYEN
        // sınırı. Bütçe yalnızca LCV'ye iliştirilmiş dosyaları sayar; iliştirilmemiş
        // (yetim) dosyalar kendi küçük kotasına girer. Ayrıntı: StoreUploadedMediaAction kılavuzu.
        'guest_budget' => [
            'attached_bytes_per_invitation' => 1024 * 1024 * 1024, // 1 GB
            'pending_files_per_invitation' => 50,
            'pending_bytes_per_invitation' => 200 * 1024 * 1024, // 200 MB
        ],

        // Kuyruktaki OptimizeUploadedImage isinin ayarlari.
        'optimize' => [
            // 🔴 EN UZUN KENAR, genislik DEGIL. Genisligi sinirlamak dikey
            // fotografi serbest birakiyordu: 3000x4000'lik bir kare 2000x2667
            // Ayrıntı: docs/rehber/config/davetkart.md §K18-8
            'max_edge_px' => 2000,

            // 🔴 Cozme (decode) belleginin ust siniri BU SATIRDIR: GD piksel
            // basina ~4 bayt ister, yani 8192x8192 ≈ 67 MP ≈ 270 MB. Dogrulama
            // Ayrıntı: docs/rehber/config/davetkart.md §K18-9
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
            // Ayrıntı: docs/rehber/config/davetkart.md §K18-10
            'max_size_kb' => 15360,
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_per_invitation' => 30,
        ],
        // 🔴 max_per_invitation her turde ZORUNLU: LCV medyasini kimligi
        // bilinmeyen misafir yukluyor. Hiz siniri "ne siklikta"ya bakar, kota
        // Ayrıntı: docs/rehber/config/davetkart.md §K18-11
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
        // Ayrıntı: docs/rehber/config/davetkart.md §K18-12
        'release_window_days' => 3,
    ],

    // İade (iptal) talebi (Faz 14, K139). Kullanıcı KULLANILMAMIŞ, parası
    // ödenmiş bir hakkı ödeme anından itibaren bu kadar gün içinde iptal
    // Ayrıntı: docs/rehber/config/davetkart.md §K18-13
    'refunds' => [
        'window_days' => 14,
    ],

    // Hediye kodu (Faz 11). Kod tahmini (brute-force) hızı kullanıcı başına sınırlanır;
    // uç auth'lu olduğu için anahtar KİMLİK, IP değil (asistan ile aynı gerekçe).
    // Önizleme ve kodlu checkout aynı kovayı paylaşır: biri kapanınca diğerine kaçılmasın.
    'gift_codes' => [
        'rate_limit' => [
            'per_user_per_minute' => 10,
            'per_user_per_hour' => 30,
        ],
    ],

    // Faz 19 (19.10 · K186, SEC-06): genel API tavanı. Kimliği bilinen istek
    // KULLANICI kovasında, bilinmeyen IP kovasında; misafir davetiye okuması
    // (önbellekli + ETag'li) ayrı ve geniş kovada: salonda yüz misafir tek IP.
    'api' => [
        'rate_limit' => [
            'per_user_per_minute' => 120,
            'per_ip_per_minute' => 60,
            'public_read_per_ip_per_minute' => 300,
        ],
    ],

    // Public davetiye cache'i. Tazelik TTL ile değil, event ile sağlanır.
    'cache' => [
        'public_invitation_ttl' => 60 * 60 * 6, // saniye
        'key_prefix' => 'davetkart',
    ],

    // FE-04 (Faz 19.23): bot paylasim onizlemesi. Gorsel frontend'in public/ klasorunden (mutlak
    // adres frontend.url ile kurulur); davetiyede galeri varsa ilk gorsel. max_age: botlarin HTTP onbellegi.
    'share_preview' => [
        'image_path' => '/videos/hero-bg-poster.jpg',
        'max_age' => 300, // saniye
    ],

    'auth' => [
        'login_rate_limit_per_minute' => 5, // brute-force savunması
        'token_name' => 'davetkart-spa',

        // Faz 14 (K144): e-posta değişikliği onay bağlantısının ömrü (dakika).
        // Parola sıfırlamayla aynı: 60.
        'email_change_expire_minutes' => 60,

        // Faz 19 (19.14 · K191, SEC-09): kayıt sonrası doğrulama bağlantısının ömrü
        // (dakika) ve "yeniden gönder" ucunun kullanıcı başına saatlik sınırı.
        'email_verification_expire_minutes' => 60 * 24,
        'email_verification_resend_per_hour' => 3,
    ],

    // AI çağrısı ücretli; kotasız bırakmak finansal risktir.
    'assistant' => [
        'daily_message_limit_per_user' => 30,
        'max_prompt_chars' => 2000,

        // 🔴 Hız sınırı kotanın YERİNE GEÇMEZ (L3). Kota "bugün kaç mesaj"a
        // bakar ve veritabanında sayılır (cache silinse bile durur); bu kova
        // Ayrıntı: docs/rehber/config/davetkart.md §K18-14
        'rate_limit' => [
            'per_user_per_minute' => 6,
        ],
    ],

    // İletişim formu: sistemin DÖRDÜNCÜ auth'suz yazma yolu.
    'contact' => [
        'max_message_chars' => 2000,

        // LCV'den (10/dk) daha DAR: meşru bir kullanıcı iletişim formunu
        // günde bir kez doldurur, LCV gönderimi ise bir davetiye için onlarca
        // Ayrıntı: docs/rehber/config/davetkart.md §K18-15
        'rate_limit' => [
            'per_ip_per_minute' => 3,
            'per_ip_per_hour' => 10,
        ],
    ],

    // Faz 10 (10.30): frontend'in kok adresi. Maildeki baglantilar MUTLAK
    // olmak zorunda (parola sifirlama). Adres istegin Host basligindan DEGIL
    // Ayrıntı: docs/rehber/config/davetkart.md §K18-16
    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:3000'),
        'password_reset_path' => '/sifre-sifirla',
        // Faz 14 (K144): yeni adrese giden onay bağlantısının hedefi.
        'email_change_path' => '/eposta-onay',
        'email_verification_path' => '/eposta-dogrula', // K191
    ],

    // Kullaniciya giden mail (K96): TEK dil, Turkce. K21'in (API tek dil,
    // metin dondurmez) BILINCLI istisnasi: mail kullanicinin dogrudan okudugu
    // bir metin ve kullanicinin dil tercihi bugun saklanmiyor.
    'mail' => [
        'locale' => 'tr',
    ],

    // Faz 10 (10.42 · K98 / S-1): saklama sureleri. KVKK'nin veri en aza
    // indirme ilkesi: amaci biten kisisel veri saklanmaz. `data:purge` her
    // Ayrıntı: docs/rehber/config/davetkart.md §K18-17
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
