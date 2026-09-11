# FAZ 9 — Üretim Hazırlığı

> **Tarih:** 11 Eylül 2026
> **Durum:** ⬜ **`composer check` HENÜZ KOŞMADI** — 9.8'den 9.14'e kadar yedi adım
> tek oturumda, kapı koşmadan yazıldı (İsmail'in açık talebi)
> ⬜ **Elle doğrulama açık** ([`FAZ-9-ELLE-DOGRULAMA.md`](FAZ-9-ELLE-DOGRULAMA.md))
> **Önceki:** [`FAZ-8.md`](FAZ-8.md) · **Sonraki:** frontend yakalama fazı
> **Bu dosya:** fazın kaydı, alınan kararlar, kurulan kurallar ve devir

---

## 0. 🔴 ÖNCE BUNU OKU

Faz 9 bir **özellik** fazı değil, bir **ortam** fazıdır: kodun kendisini değil,
kodun **çalıştığı yeri** değiştirir. Bunun iki sonucu var ve ikisi de bu
dosyanın okunma biçimini belirliyor.

**Birincisi:** bu fazda kırılacak şeylerin çoğu `composer check`'in görüş
alanının dışında. Testler `array` cache, `sync` kuyruk ve `local` disk ile
koşar; Redis, S3, HTTPS, `config:cache` ve cron oralarda yoktur. Bu yüzden her
altyapı adımı `FAZ-9-ELLE-DOGRULAMA.md`'ye somut bir adım üretti — *"çalıştı"*
değil, *"şunu koş, şunu gör"* biçiminde.

**İkincisi ve daha önemlisi:** bu faz, önceki sekiz fazın **bilerek ödediği
bedellerin gerçekten işe yarayıp yaramadığını** gördü. Üç tanesi karşılığını
verdi, biri vermedi:

| Faz | Ödenen bedel | Faz 9'da olan |
|---|---|---|
| 7 · **K8** | `PaymentGateway` arayüzü | ⬜ Henüz tahsil edilmedi — `IyzicoGateway` anahtarlar gelince |
| 6 · **F4** | `media.disk` kolonda saklanıyor | ✅ S3 göçü eski satırları kırmıyor; `PruneOrphanMedia` her satırı kendi diskinden siliyor |
| 8 · **Q2** | Kota veritabanında, cache'te değil | ✅ Redis'e geçiş kotayı sıfırlamayacak |
| 0 · **Y1** | Kodda `env()` yok | ✅ `config:cache` güvenli — `app/` ve `routes/` içinde sıfır çağrı |
| 1 · **K30** | Rota dosyasında closure yok | ⚠️ **Gerekçesi geçersizleşti** — §3'e bak |

---

## 1. 🔴 Fazın en büyük bulgusu: `orders.invitation_id` iki gerçeği birden saklıyordu

Faz 9'un asıl işi planlanan liste değildi. 9.7'ye hazırlanırken şu çıktı:

```php
// OrderEntitlementResolver — Faz 7
$query->whereNull('invitation_id')          // "paket alımı"
    ->orWhere('invitation_id', $invitation->getKey());
```

`invitation_id IS NULL` = **paket alımı** demekti ve tablo yazıldığı gün bu
doğruydu: `NULL` olmanın tek yolu paket satın almaktı.

Ama `orders.invitation_id` **`nullOnDelete`**. Kalıcı silme geldiği gün ikinci
bir yol doğardı:

```
249 ₺ Standart (tek davetiye için)  →  orders(invitation_id = X, paid)
Davetiye X kalıcı silinir           →  nullOnDelete → invitation_id = NULL
Aynı satır artık "paket" görünür     →  hesabın TÜM davetiyeleri bedava yayınlanır
```

Kimse hata yapmamıştı: şema tutarlı, FK doğru, sorgu doğru. Yanlış olan tek
şey **`NULL`'un iki farklı gerçeği anlatmasıydı** — **N4**'ün para
katmanındaki hâli.

🔴 Bugün sömürülebilir **değildi**, çünkü kod tabanında `forceDelete()` çağıran
tek satır yok. Ama 9.7 (`DeleteInvitationAction`) tam olarak kalıcı silmenin
yazılacağı adımdı: plan düz uygulansaydı bu delik **bu fazda açılacaktı**.

> **Ders 60:** bir kolonun anlamı, o kolona yazan **tüm yolların** toplamıdır.
> Bugün tek yol varsa anlam nettir; yarın ikinci bir yol açıldığında anlam,
> kimse dosyaya dokunmadan değişir. Bu yüzden kritik bir ayrım, bir alanın
> **yokluğuna** değil **varlığına** yazılır.

Çözüm `orders.scope` kolonu (9.2–9.6) ve dört kombinasyon:

| `scope` | `invitation_id` | Anlamı | Yayın hakkı |
|---|---|---|---|
| `account` | `NULL` | Paket alımı | Sahibinin **her** davetiyesine |
| `invitation` | dolu | Tekil, bağlı | **Yalnızca** o davetiyeye |
| 🔴 `invitation` | `NULL` | **Serbest bırakılmış tekil** | **Hiçbirine** — bağlanana kadar |
| `account` | dolu | Anlamsız | ❌ CHECK kısıtı |

---

## 2. Yazılan dosyalar (14 adım)

| # | Dosya | Ne yapar |
|---|---|---|
| 9.1 | `routes/web.php` **silindi** + `bootstrap/app.php` | Ölü `welcome` rotası; `web` middleware grubu artık hiç koşmuyor |
| 9.2 | `app/Enums/OrderScope.php` + `tests/Unit/.gitkeep` | Kapsam tipi + sürümlenmemiş test süiti düzeltildi (**B10**) |
| 9.3 | `..._add_scope_to_orders_table.php` | Nullable kolon + 2 CHECK + geri doldurma (**genişlet**) |
| 9.4 | `Order` · `OrderFactory` · `StartCheckoutAction` | Bütün yazıcılar `scope` yazar (**taşı**) |
| 9.5 | `..._make_orders_scope_not_null.php` | `SET NOT NULL` (**daralt**) |
| 9.6 | `OrderEntitlementResolver` · `Order::scopeGrantingAcrossAccount` | 🔴 Deliği fiilen kapatan sorgu |
| 9.7 | `DeleteInvitationAction` + `config/davetkart.php` | Üç günlük serbest bırakma penceresi |
| 9.8 | `ClaimReleasedOrderAction` + `PublishInvitationAction` + 17 test | Serbest hakkı yeni davetiyeye bağlama |
| 9.9 | `ExpireStaleOrders` (`orders:expire`) | `expires_at` üç fazdır yazılıyor, ilk kez okunuyor |
| 9.10 | `PruneOrphanMedia` (`media:prune-orphans`) | LCV'ye bağlanmamış misafir yüklemeleri |
| 9.11 | `routes/console.php` | Zamanlayıcı: 3 iş + `sanctum:prune-expired` |
| 9.12 | `config/cors.php` + `SecurityHeaders` middleware | 🔴 `exposed_headers: ETag` + 6 sertleştirme başlığı |
| 9.13 | `.env.example` + `docs/10-URETIM-ENV-SABLONU.md` | Projenin kendi değişkenleri ilk kez belgelendi |
| 9.14 | Bu dosya + elle doğrulama + docs/07 + docs/09 | Faz kapanışı |

**Yeni test sayısı:** 17 (Paywall) + 15 (Maintenance) + 9 (Hardening) + 6 (Unit)
= **47**. Toplam beklenen: **245**.

---

## 3. ⚠️ Plandan sapmalar ve geçersizleşen gerekçeler

### K30'un gerekçesi geçersiz (ama kararı doğru)

Prompt ve `docs/09`, *"`route:cache` bugün çalışmaz, `routes/web.php` bir
closure içeriyor"* diyordu. **Yanlış.** Laravel 13'te
`Route::prepareForSerialization()` (`vendor/.../Routing/Route.php:1544`)
closure'ı `SerializableClosure::unsigned()` ile serileştiriyor; istisna
fırlatmıyor. Kalan tek `Unable to prepare route` hatası **çift rota adı**
hakkında. Üstelik framework'ün kendi `/up` sağlık rotası da bir closure.

K30 Laravel 11 davranışına karşı yazılmıştı ve **peşin ödenen bedel doğruydu**;
bugünkü gerekçesi başka: saf API backend'inde `welcome` görünümüne giden bir
rota **ölü koddur** (ders 26).

Yan sonuç: `view:cache` derleyecek Blade bulamıyor → dağıtım listesinden
**çıkarıldı**.

### `view:cache` listeden çıktı

`welcome.blade.php` silinince derlenecek tek Blade kalmadı
(`health-up.blade.php` vendor'da ve `View::file()` ile render ediliyor).
Çalıştırmak tören olurdu.

### Dilim B ve C ertelendi

Prompt Faz 9'u beş dilim olarak tarifliyordu. İsmail'in üç cevabı sırayı
değiştirdi:

| Dilim | Durum | Sebep |
|---|---|---|
| A — önkoşul temizliği | ✅ 9.1 | — |
| B — zamanlanmış işler | ✅ 9.9–9.11 | — |
| C — gerçek altyapı (Redis, S3, süpervizör) | ⬜ **ertelendi** | Barındırma kararı *"VPS ama paylaşımlı hostinge göre"* → **K80**; Redis bir yükseltme oldu, varsayım değil |
| D — gerçek ödeme (`IyzicoGateway`) | ⬜ **ertelendi** | Sandbox anahtarları yok; imza formatı ve durum sözlüğü tahmin edilemez |
| E — üretim sertleştirmesi | ✅ 9.12–9.13 | CORS, başlıklar, env belgelendi. HTTPS/TrustProxies sunucu tarafında kaldı |

---

## 4. Kurulan kurallar (Faz 9 · 3 kural)

| # | Kural | Gerekçe |
|---|---|---|
| **B10** | **Kalite kapısının ve kurulumun bağımlı olduğu her şey sürümlenir** | `tests/Unit` boş bir dizindi ve git boş dizin saklamaz: `composer check` sekiz fazdır **yalnızca bu makinede** yeşildi. Aynı hata `.env.example`'da tekrar etti — projenin kendi değişkenlerinin hiçbiri yazılı değildi. **Yeşil, taşınabilir olmadığı sürece bir bilgi taşımaz** |
| **K80** | **Altyapı en düşük ortak paydaya yazılır; Redis, S3 ve süpervizör birer YÜKSELTMEDIR** | Barındırma kararı *"VPS ama paylaşımlı hostinge göre"* oldu. Kuyruk cron'dan `--stop-when-empty` ile koşabilmeli, cache `file` ile çalışabilmeli. Q2 sayesinde bunun bir para kontrolüne maliyeti yok |
| **E12** | **Bir kolonun anlamı, ona yazan tüm yolların toplamıdır** | `invitation_id IS NULL` bir gün "paket", ertesi gün "davetiyesi silindi" demeye başladı — kimse dosyaya dokunmadan. Kritik bir ayrım bir alanın **yokluğuna** değil **varlığına** yazılır (N4'ün genellemesi) |

> Kural sayıları: FAZ-0 (31) · FAZ-1 (19) · FAZ-2 (20) · FAZ-3 (15) ·
> FAZ-4 (11) · FAZ-5 (10) · FAZ-6 (11) · FAZ-7 (10) · FAZ-8 (10) ·
> **FAZ-9 (3)** = **140**

---

## 5. Alınan kararlar (K80–K86)

| # | Karar | Gerekçe |
|---|---|---|
| **K80** | Altyapı en düşük ortak paydaya yazılır (yukarıda) | Barındırma belirsiz; taşınabilirlik Q2 sayesinde bedava |
| **K81** | `orders.scope` — kapsam **satırda saklanır**, okumada türetilmez | §1. `invitation_id`'nin yokluğu iki gerçeği birden anlatıyordu (**N4/E12**) |
| **K82** | Yayınlanmış davetiye **silinebilir**; hak **3 gün** içinde serbest kalır | İsmail'in ticari kararı. Pencere `published_at`'ten sayılır; kapalıysa hak yanar. Sipariş satırı **hiç silinmez** — muhasebe kaydı bir tıkla yok olamaz |
| **K83** | Serbest hak, yayın anında **yeten en düşük** siparişten harcanır | Resolver "en yüksek"i döndürür (okuma), tüketim tersini ister: 249 ₺'lik iş için 549 ₺'lik hak yakılmaz |
| **K84** | Bakım komutları `--dry-run` taşır ve zamanlayıcıdan **önce elle** koşulur | İlk gerçek koşu üretimde ve gözlemsiz olmamalı |
| **K85** | Güvenlik başlıkları **middleware'de**, nginx'te değil | **B10**: nginx.conf sunucuda yaşar ve taşımada geride kalır. Ayrıca test edilebilir ve paylaşımlı hostingde de çalışır (**K80**) |
| **K86** | `config/cors.php` **yayınlandı ve daraltıldı**; `exposed_headers: ['ETag']` | Laravel varsayılanı `['*']` + boş `exposed_headers`. İkincisi K7/K46'nın polling optimizasyonunu **sessizce** öldürüyordu |

---

## 6. 🔴 Doğrulama durumu (dürüst liste)

### ⬜ Hiçbiri doğrulanmadı

Bu fazda `composer check` **hiç koşmadı**. 9.8–9.14 arası yedi adım, İsmail'in
açık talebiyle tek oturumda ve kapı koşmadan yazıldı. Yazılan PHP dosyalarının
tamamı `php -l` ile sözdizimi açısından kontrol edildi — **başka hiçbir şey
doğrulanmadı**.

Bu, projenin *"her adım yeşil bitmeli"* kuralının bilinçli bir askıya
alınmasıdır ve riski açık: zincir kırmızı gelirse hangi adımdan geldiğini
ayırmak zor olacak.

### ⬜ `composer check` bunları zaten göremez

| Ne | Neden test edilemez | Nerede kapanır |
|---|---|---|
| `config:cache` sonrası davranış | Testler config'i her seferinde yeniden okur | Elle doğrulama |
| `route:cache` | Testler rota dosyasını okur | Elle doğrulama |
| Gerçek kuyruk worker'ı | `QUEUE_CONNECTION=sync` | Elle doğrulama |
| S3 diski | `FILESYSTEM_DISK=local`, `Storage::fake()` | Dilim C |
| HSTS başlığı | Testler `http` üzerinden koşar | Elle doğrulama |
| Cron'un gerçekten çalışması | İşletim sistemi işi | Elle doğrulama + §8 |
| `.env.example`'ın eksiksizliği | Testler mevcut `.env` ile koşar | Temiz klon tatbikatı |

---

## 7. Öğrenilen dersler (60–63)

**60. 🔴 Bir kolonun anlamı, ona yazan TÜM yolların toplamıdır.**
`invitation_id IS NULL` yazıldığı gün tek bir şey demekti ve o gün doğruydu.
`nullOnDelete` ikinci bir yol açtığında anlam, **kimse dosyaya dokunmadan**
değişti. Bir sorgunun doğruluğu, yazıldığı andaki dünyanın doğruluğudur; o
dünyayı değiştiren şey başka bir dosyada durabilir.

**61. 🔴 Sekiz fazdır yeşil yanan bir kapı, doğru sebeple yanıyor olmayabilir.**
`composer check` `tests/Unit` dizinine bağımlıydı ve o dizin **hiçbir
commit'te yoktu** — yalnızca İsmail'in makinesinde kalmış bir kalıntıydı. Temiz
bir klonda, CI'da ya da üretim sunucusunda kapı sekiz faz boyunca kırmızı
olurdu. Ders 34'ün (*"beklediğin yanıtı almak, beklediğin sebeple aldığın
anlamına gelmez"*) araç katmanındaki hâli.

**62. Bir tüketim adımı, tüketmeden önce "gerekli mi" diye sorar.**
`ClaimReleasedOrderAction` koşulsuz çağrılsaydı, Elit paketi olan bir kullanıcı
her yayında elindeki serbest tekil siparişi de harcardı. Ve tüketimde doğru
refleks okumanınkinin **tersidir**: resolver "en yüksek"i döndürür, tüketim
"yeten en düşük"ü harcar. Aynı veriye bakan iki kod, farklı yönde optimize
edilir.

**63. Bir savunmayı "zaten başka bir savunma tutuyor" diye açmak, katmanlı
savunmanın tersidir.** CORS'ta `allowed_origins => ['*']` bırakmanın gerekçesi
hazırdı: *"token Authorization başlığında gidiyor, CSRF geçerli değil."* Doğru
ama yetersiz — `*`, herhangi bir sitedeki JavaScript'in `/api/public/` uçlarını
sürmesine izin verir. **L1** katmanların birbirinin yerine geçmediğini söyler;
bu, aynı kuralın yapılandırma tarafındaki hâli.

---

## 8. 🔴 Açık kararlar ve borçlar

### Faz 9'un kendi bıraktıkları

| # | Konu | Not |
|---|---|---|
| 1 | 🔴 **Zamanlanmış iş koşmazsa kimse bilmez** | `schedule:run` cron'a kurulmazsa hiçbir şey hata vermez. İzleme kararı: e-posta (K79 açık) / ping servisi / kendi kendini izleyen tablo |
| 2 | **Silme sonucu kullanıcıya söylenmiyor** | `DELETE /api/invitations/{id}` → 204. Hak serbest mi bırakıldı, yandı mı — kullanıcı bilmiyor. Doğru çözüm bir *"siparişlerim"* ucu |
| 3 | **Ters yön yetim: diskte olup satırı olmayan dosya** | S3'e geçtikten sonra bir kez elle sayım (`aws s3 ls \| wc -l` ↔ `SELECT count(*) FROM media`) |
| 4 | **Redis'e geçince `throttle` sınıfı değişir** | `ThrottleRequestsWithRedis` — kovalar aynı, davranış birebir aynı değil. `throttle:assistant` ve `throttle:contact` yeniden sınanmalı |
| 5 | **Argon2id ölçülmedi** | Üretim donanımı bilinmiyor. Hedef ~250 ms/hash; paylaşımlı hostingde `memory_limit` 64 MB'lık hash'i zorlayabilir |

### Önceki fazlardan devralınan, hâlâ açık

| # | Konu | Kaynak |
|---|---|---|
| 1 | 🔴 **Paket alım kaç yayın açar?** Bugün sınırsız | K43 · FAZ-7 §9 |
| 2 | **Bildirim kanalı** (`SendRsvpNotification`) — kanal + dil + politika + şablon | K79 · FAZ-8 §9 |
| 3 | `SubscriptionTier::label()` dokuz fazdır çağrılmıyor → ders 26 gereği **silinmeli** | FAZ-8 §9 |
| 4 | Asistan kotasının gün sınırı **UTC** — İstanbul'da 03:00'te yenileniyor | FAZ-8 §9 |
| 5 | `contact_messages` için **okuma ucu yok** | FAZ-8 §9 |
| 6 | `rsvps.id` ULID (K52) Faz 5'ten beri onay bekliyor | FAZ-7 §9 |
| 7 | İade var olan yayını geri çekmiyor | FAZ-7 §9 |
| 8 | 🔴 **Faz 5, 6, 7 ve 8'in elle doğrulama betikleri hâlâ açık** | — |
| 9 | 🔴 **Frontend BEŞ faz geride** | FAZ-8 §8 |

---

## 9. Frontend'e düşen (Faz 9 eklemesi)

| # | Konu | Değişiklik |
|---|---|---|
| 1 | 🔴 `services/api.ts` | Üretimde Vite proxy **yok**: `baseURL` gerçek origin olmalı ve o origin `CORS_ALLOWED_ORIGINS`'e yazılmalı |
| 2 | 🔴 ETag okuma | `exposed_headers` artık ETag'i açıyor; polling'in `If-None-Match` göndermesi frontend tarafında **doğrulanmalı** |
| 3 | Silme uyarısı | Yayınlanmış bir davetiye silinirken *"3 gün içinde hakkınızı yeni bir davetiyede kullanabilirsiniz"* uyarısı — sunum kararı (K20: backend metin dönmez) |
| 4 | Faz 8'in beş maddesi | Hâlâ yapılmadı (FAZ-8 §8) |

---

## 10. Faz 9 kapanış listesi

- [ ] 🔴 `composer check` **son satırı** yeşil (`Tests: 245 passed` beklenen)
- [ ] `php artisan migrate` — 2 yeni migration (9.3, 9.5)
- [ ] `php artisan schedule:list` → 3 iş görünüyor
- [ ] [`FAZ-9-ELLE-DOGRULAMA.md`](FAZ-9-ELLE-DOGRULAMA.md) tamamlandı
- [ ] Temiz klon tatbikatı `.env.example`'dan geçti
- [ ] Mutasyon tablolarından en az 5 satır denendi (**T16**)
- [ ] §8'deki açık kararlar okundu
- [ ] Bu dosyanın **durum alanı** güncellendi (**B7**)

---

## 11. Bir cümlelik özet

Faz 9'da kodun çalıştığı yeri kurmaya çalışırken asıl öğrendiğimiz şey şuydu:
bir sistemin en tehlikeli hataları yazıldığı gün **doğru** olan satırlardan
doğar — `invitation_id IS NULL` da, boş bir `tests/Unit` dizini de, `['*']`
CORS varsayılanı da yazıldıkları gün kimseyi rahatsız etmiyordu; onları
tehlikeli yapan, **dünyanın onların etrafında değişmesiydi**.
