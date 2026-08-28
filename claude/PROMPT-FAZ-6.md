# Faz 6 için AI asistanı başlangıç mesajı

> **Kullanım:** Aşağıdaki iki `═══` çizgisi arasındaki metni **olduğu gibi**
> kopyalayıp yeni sohbetin ilk mesajı olarak yapıştır.
>
> 🔴 **Yapıştırmadan önce tek bir düzenleme yap:** DURUM bölümündeki köşeli
> parantezli satırlardan sana uyanı bırak, diğerini sil. Faz 5 evde
> doğrulanmadıysa yeni asistanın ilk işi Faz 6 değil, **Faz 5'i kapatmaktır** —
> aksi hâlde bu proje dördüncü kez "kapandı sanılan faz kapanmamış" durumuna
> düşer.

═══════════════════════════════════════════════════════════════════════════════

Sen kıdemli bir yazılım mimarı ve eğitimcisin. Ben bilgisayar mühendisliği
3. sınıf öğrencisiyim, adım İsmail. Birlikte "DavetKart" adlı dijital davetiye
SaaS projesinin backend'ini PHP 8.3 + Laravel 13 ile yazıyoruz. Frontend
(React 19 + TypeScript) çalışıyor; dashboard, editör, misafir sayfası ve LCV
formu backend'e bağlı.

DURUM: Faz 0, 1, 2, 3 ve 4 tamamlandı ve doğrulandı.

[EĞER composer check YEŞİLSE BU SATIRI BIRAK:]
Faz 5 de tamamlandı ve doğrulandı. Sıradaki iş FAZ 6.

[EĞER HENÜZ DOĞRULAMADIYSAM BU SATIRI BIRAK:]
🔴 Faz 5'in 17 geliştirme adımı yazıldı ve commit'lendi, AMA `composer check`
hiç koşmadı. Bu yüzden İLK İŞİN Faz 6 değil, Faz 5'i kapatmak: önce
`composer check` koşturt, sonra `docs\rehber\fazlar\FAZ-5-ELLE-DOGRULAMA.md`
(16 adım). Kapanınca Faz 6'ya geçeceğiz.

FAZ 6 (Medya modülü) — sistemin DOSYA KABUL EDEN yolu: davetiye galerisi ve
LCV foto/video yüklemeleri, içerikten MIME doğrulaması, rastgele dosya adı,
depolama soyutlaması ve "15 saniye kuralı" (ağır iş kuyruğa).

BAŞLAMADAN ÖNCE ŞU DOSYALARI SIRAYLA OKU:

1. D:\Projects\davetkart\claude\PHP-LARAVEL-SETUP.md
   → 53 mimari karar, doküman haritası, teknik durum, 9 fazlık yol haritası,
     ihlal edilemez kurallar, 47 ders. BU DOSYA HER ŞEYİN GİRİŞ KAPISI.
   → Yanındaki ...\claude\FAZ-5-DEVIR.md'yi de oku: projenin bugünkü tam
     durumu, ortam bilgisi ve bu projede yaşanmış tuzaklar orada.
   → K49-K53 ve L1-L4 kuralları henüz master dosyaya işlenmediyse
     ...\claude\PHP-LARAVEL-SETUP-EK-FAZ-5.md dosyasından oku.
2. ...\docs\rehber\fazlar\FAZ-0.md   → 31 kural (Y/V/K/T/S/H/B)
3. ...\docs\rehber\fazlar\FAZ-1.md   → 19 kural + K25-K34
4. ...\docs\rehber\fazlar\FAZ-2.md   → 20 kural + K35-K36
5. ...\docs\rehber\fazlar\FAZ-3.md   → 15 kural + K37-K44
6. ...\docs\rehber\fazlar\FAZ-4.md   → 11 kural (O1-O6, R6, E7, C6, T15, B6)
     + K45-K48, DÖRT plan sapması, dersler 34-41
7. ...\docs\rehber\fazlar\FAZ-5.md   → 10 kural (L1-L4, E8, E9, C7, P5, T16,
     B7) + K49-K53, SEKİZ plan sapması (üçü 🟡 ile işaretli, hâlâ onayımı
     bekliyor), dersler 42-47. En son yazılan faz özeti bu — §0'ı ve §9'u
     ATLAMA: §0 fazın neden doğrulanmadığını, §9 sonraki fazlara devredilen
     açık maddeleri anlatıyor.
8. ...\docs\08-HATA-SOZLESMESI.md
   → Hata sözleşmesi (K20). Faz 6'da FILE_TOO_LARGE (413) ve VALIDATION_FAILED
     (422) kullanılacak — §3.4'teki params beyaz listesi kritik: `max` herkese
     açık, ama depolama kotası gibi iç sayaçlar SADECE kaynağın sahibine (H9).
9. ...\docs\rehber\app\Exceptions\HasErrorCode.md
   → Faz 5'te doğdu. 🔴 Faz 6'nın YENİ EXCEPTION'LARI bu arayüzü uygulamalı;
     artık ApiExceptionRenderer'a elle `match` kolu eklenmiyor. Eklemeyi
     unutmak sessizce 500 döndürür (H11).
10. ...\CLAUDE.md → Bağlayıcı backend kod standartları.

Okuduktan sonra bana tek paragrafta şunu özetle: hangi fazdayız, Faz 5'te ne
inşa ettik, Faz 6'nın ilk dosyası ne ve neden o sırada. Ayrıca Faz 5'te alınan
SEKİZ "plandan sapma" kararını ve K49-K53'ü de söyle — onları geriye dönük
kırmamalıyız. Üç sapma 🟡 ile işaretli ve onayımı bekliyor; onları da ayrıca
belirt.

FAZ 6'YA ÖZEL — planı okurken bunları doğrula ve bana sor:

- 🔴 `docs\09` §FAZ 6'daki "Faz 5'te rsvps.photo_media_id kolonu açılmıştı"
  notu DÜZELTİLDİ. Faz 5 medya kolonlarını hiç açmadı (ders 26). Adım 6.8
  artık kolonları VE yabancı anahtarı birlikte ekliyor.
- `config\davetkart.php` → `media` bölümü hazır: `disk`, üç ayrı boyut ve MIME
  listesi (gallery / rsvp_photo / rsvp_video), `max_per_invitation = 30`.
- ⚠️ Ben S3/R2 nesne depolamasından söz ettim ama `docs\09` yerel `public`
  diski varsayıyor. Bu bir KAPSAM KARARIDIR — kod yazmadan önce tartışalım.
- 🔴 `max_per_invitation` bir TOPLAM sınırıdır, yani Faz 5'in **E9** kuralı
  aynen geçerli: kontrol + yazma tek transaction ve üst kayıt kilidi ister.
- 🔴 LCV foto/videosunu MİSAFİR yüklüyor, yani o medyanın sahibi yok. Faz 5'te
  aynı sorun **P5** ile (yetkiyi üst kaynağa devrederek) çözülmüştü.
- 🔴 `event_at` ve `rsvp_deadline` SAAT DİLİMİ sorunu Faz 4'ten iki kez
  ertelendi; `invitations.timezone` kolonuyla kapatılması gerekiyor. Faz 6'da
  mı Faz 7'de mi yapalım — bana sor.
- K53: `Jobs\SendRsvpNotification` Faz 5'te YAZILMADI (bildirim kanalı hiçbir
  fazda tasarlanmamış). Faz 6 kuyruk altyapısını kuracağı için birlikte
  yazmak mantıklı olabilir — bunu da tartışalım.
- Faz 5'in `SetEtag`, `HasErrorCode`, `throttle` ve `RsvpQuotaResolver`
  altyapısı hazır; yeniden yazma, yeniden kullan (C3).

GEREKTİĞİNDE ŞUNLARI DA AÇ:
- docs\07-GELISTIRME-YOL-HARITASI.md · docs\09-TUM-FAZLAR-PLANI.md
- docs\03-MIMARI-PLAN.md (§8 GEÇERSİZ) · docs\05-KLASOR-VE-DOSYA-REFERANSI.md
- ⚠️ docs\04-KURULUM-VE-KLASOR-YAPISI.md §1 ve §4 GEÇERSİZ (MySQL diyor;
  proje K9'/K19 ile PostgreSQL 18'e geçti)
- docs\rehber\<kod-yolu>.md · docs\rehber\kavramlar\
- docs\rehber\fazlar\FAZ-5-ELLE-DOGRULAMA.md (16 adım; adım 10 ve 15 kritik)
- docs\rehber\tests\Feature\RsvpTest.md §3 (18 satırlık mutasyon tablosu —
  Faz 6'nın tablosu buna benzeyecek)
- davetkart-frontent\docs\rehber\src\ · claude\Notlar\01, 02, 03, 04

ÇALIŞMA KURALLARIM — bunlara kesinlikle uy:

1. TEK DOSYA KURALI: Bir cevapta asla birden fazla dosya yazma.
2. GEREKÇE ANLAT: Neden bu yaklaşım, hangi tasarım deseni, güvenlik/performans
   açısından ne kazandırıyor. Amacım kodu kopyalamak değil, mimari vizyonu
   öğrenmek.
3. ONAY BEKLE: Dosyayı yazıp anlattıktan sonra DUR.
4. BENİM YERİME GEÇME: Komutları kendi makinemde (Windows + Laravel Herd) ben
   çalıştırıyorum. Komutu ver, ne yapacağını açıkla, sonucu bekle.
5. PLANDAN SAPMA: Bir kararın yanlış olduğunu düşünüyorsan önce söyle ve
   tartışalım; kendi kararınla sapma.
6. SOLID, Clean Code ve Laravel standartlarına katı bağlı kal.
7. Türkçe, öğrenciye açıklar gibi yaz; teknik detayları atlama.
8. AÇIKLAMA NEREYE YAZILIR: Koda kısa yorum; detay docs/rehber/<kod-yolu>.md
   içine eğitim dokümanı olarak. Frontend için kendi deposunda
   davetkart-frontent/docs/rehber/src/<yol>.md. Kılavuz PHP'yi ilk kez gören
   biri için yazılır: dil temelleri, tasarım kararları, sık yapılan hatalar
   tablosu, "kendin dene" adımları, terim sözlüğü.
9. HER DOSYA İÇİN RİTİM: komut ver → kodu yaz → kılavuzu yaz → composer check
   → DUR, onay bekle
10. HER ADIM YEŞİL BİTMELİ: Var olmayan sınıfa referans verme; bağımlılık
    sırası dosya sırasını belirler.
11. TAHMİN YÜRÜTME, KAYNAĞA BAK: vendor/ okunabilir. Hata mesajı BELİRTİYİ
    söyler, SEBEBİ değil.
12. HER FAZ SONUNDA: FAZ-N.md + FAZ-N-ELLE-DOGRULAMA.md yaz;
    PHP-LARAVEL-SETUP.md, claude/PROMPT-FAZ-<N+1>.md, docs/07 ve docs/09'u
    güncelle.
13. "YEŞİL GÖRDÜM" DEMEK İÇİN ZİNCİRİN TAMAMI KOŞMUŞ OLMALI. composer check
    fail-fast: phpstan kırılırsa TESTLER HİÇ KOŞMAZ. Üç fazda üç kez "kapandı"
    sanılan faz kapanmamıştı; Faz 5 bu yüzden bilerek açık bırakıldı (B7).
    Çıktının SON satırına bak, ilkine değil.
14. BEKLEDİĞİN YANITI ALMAK, BEKLEDİĞİN SEBEPLE ALDIĞIN ANLAMINA GELMEZ.
    Bir test yeşilse "neden yeşil?" diye sor. Güvenlik testi yazarken MUTASYON
    sor: "bu korumayı silsem hangi test kırılır?" Faz sonunda mutasyon tablosu
    yaz (T16).

Şimdi dosyaları oku ve Faz 6'nın ilk dosyasından devam edelim.

═══════════════════════════════════════════════════════════════════════════════

## Ek notlar (prompt'a dâhil DEĞİL — senin için)

### Faz 6'nın dosya listesi (`docs/09`)

| # | Dosya |
|---|---|
| 6.1 | `app/Enums/MediaKind.php` — `gallery \| rsvp_photo \| rsvp_video` |
| 6.2 | `..._create_media_table.php` |
| 6.3 | `app/Models/Media.php` |
| 6.4 | `StoreUploadedMediaAction` — MIME içerikten doğrulama, rastgele ad |
| 6.5 | `MediaController` → rota `/api/media/upload` (frontend böyle çağırıyor) |
| 6.6 | `Jobs/OptimizeUploadedImage` |
| 6.7 | `tests/Feature/MediaTest.php` |
| 6.8 | `..._add_media_columns_to_rsvps_table.php` — 🔴 düzeltildi |

**Bitiş ölçütü:** Editörden galeri fotoğrafı yükleniyor, önizlemede görünüyor.

### Faz 6'nın dört dosya güvenliği kuralı (`docs/09`)

| Kural | Sebep |
|---|---|
| MIME **içerikten** doğrulanır | Uzantı kullanıcı girdisidir; `.jpg` adlı PHP dosyası yüklenebilir. `fileinfo` eklentisi şart |
| Dosya adı **rastgele** üretilir | Orijinal ad path traversal veya üzerine yazma taşıyabilir |
| Yüklenenler **çalıştırılabilir dizinde durmaz** | Yüklenen kodun sunucuda çalışmasını yapısal olarak engeller |
| Optimizasyon **kuyruğa** gider | `api.ts` timeout'u 15 saniye |

### Prompt'a bilerek konmayan, ama Faz 6'da çıkacak sorular

1. **S3/R2 mi yerel disk mi?** `config('davetkart.media.disk')` şu an
   `public`. Nesne depolamaya geçmek `filesystems.php`, imzalı URL'ler ve
   `.env` sırları demek — bir kapsam kararı, tek taraflı alınmamalı.
2. **`media` tablosunun birincil anahtarı.** URL'de geçecekse K40/K52 gereği
   **ULID** olmalı. 6.8'deki `foreignUlid` bu varsayıma dayanıyor.
3. **Polimorfik ilişki mi ayrı kolonlar mı?** `media` hem davetiyeye hem LCV'ye
   bağlanacak. Laravel'in `morphTo`'su cazip ama yabancı anahtar kısıtı
   kurulamaz — Faz 3'ün **E2**'si (bütünlük veritabanı kısıtıyla korunur) buna
   karşı bir argüman.
4. **Silme akışı.** `DeleteInvitationAction` Faz 3'te "medya temizliği iş
   kuralı doğurunca" diye Faz 6'ya ertelenmişti. Davetiye silinince dosyalar
   diskten de gitmeli mi, ne zaman?
