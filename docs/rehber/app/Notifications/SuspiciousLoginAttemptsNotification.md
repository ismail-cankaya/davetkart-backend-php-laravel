# `app/Notifications/SuspiciousLoginAttemptsNotification.php`

> Faz 21.1 · `SEC-16` · K265. "Hesabınıza çok sayıda hatalı giriş denendi" postası.

## Ne zaman gider?

Bir hesabın başarısız giriş kovası ([`AccountLoginThrottle.md`](../Support/AccountLoginThrottle.md)) **dolduğu** denemede,
o hesap gerçekten varsa ve son bir saatte bu posta gitmediyse. Yani saatte en çok bir kez. Kayıtsız adres için hiçbir
şey gönderilmez (gönderilecek biri yok).

## Ne söyler, ne söylemez?

| Söyler | Söylemez |
|---|---|
| Son bir saatte hatalı parolayla çok deneme yapıldı | Denemelerin IP'leri, ülkeleri, sayısı |
| Girişler bir süre durdu, açık oturumlar etkilenmedi | Hangi saatte, hangi cihazdan |
| Siz değilseniz parolanızı yenileyin; yenileyince giriş hemen açılır | |

IP neden yok? Karar tablosu (§A-3) böyle istiyor ve gerekçesi sağlam: IP kişisel veri (KVKK), sistem ham IP'yi zaten
saklamıyor (`IpHasher`), saldırgan IP'leri çoğunlukla vekil sunuculardır ve kullanıcıya bir şey anlatmaz. Önemli olan
kullanıcının yapacağı tek iş: parolayı yenilemek.

Bağlantı sıfırlama **sayfasına** gider (`/sifremi-unuttum`), token taşımaz: postayı ele geçiren biri onunla bir şey
yapamaz. `PasswordChangedNotification` ile aynı desen.

## Neden kuyrukta (`ShouldQueue`)?

Posta SMTP/SES'e gider; bu ağ çağrısı giriş isteğini bekletmemeli (CLAUDE.md §4, 15 saniye kuralı). Kuyruğa yazmak
(canlıda `database` kuyruğu) tek bir `INSERT`tir.

Dil: `davetkart.mail.locale` (tr). Test: `the_warning_is_queued_turkish_and_carries_no_ip`.
