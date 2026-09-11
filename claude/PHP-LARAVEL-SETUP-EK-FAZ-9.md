# `PHP-LARAVEL-SETUP.md` — Faz 9 Yaması

> **Tarih:** 11 Eylül 2026
> **Ne bu:** Ana devir dosyasına (`claude/PHP-LARAVEL-SETUP.md`) **işlenmeyi
> bekleyen** Faz 9 kayıtları. `-EK-FAZ-5/6/8` dosyalarıyla aynı biçim.
> **Tam kayıt:** `docs/rehber/fazlar/FAZ-9.md`

---

## A. Karar Kaydı'na eklenecek (§7)

| # | Karar | Gerekçe | Durum |
|---|---|---|---|
| **K80** | Altyapı **en düşük ortak paydaya** yazılır; Redis, S3 ve süpervizör birer **yükseltmedir** | Barındırma kararı *"VPS ama paylaşımlı hostinge göre"*. Kuyruk cron'dan `--stop-when-empty` ile koşabilmeli, cache `file` ile çalışabilmeli. **Q2** sayesinde bunun bir para kontrolüne maliyeti yok: kota veritabanında | ✅ Faz 9 |
| **K81** | `orders.scope` — kapsam **satırda saklanır**, okumada türetilmez | `invitation_id IS NULL` iki gerçeği birden anlatıyordu: *"paket alındı"* ve *"tekil siparişin davetiyesi silindi"* (`nullOnDelete`). Silinen bir davetiyenin siparişi sessizce pakete dönüşüp hesap geneline hak veriyordu (**N4/E12**) | ✅ Faz 9 |
| **K82** | Yayınlanmış davetiye **silinebilir**; ödenen hak **3 gün** içinde serbest kalır | İsmail'in ticari kararı. Pencere `published_at`'ten sayılır; kapalıysa hak yanar. 🔴 Sipariş satırı **hiç silinmez** — muhasebe kaydı bir tıkla yok olamaz | ✅ Faz 9 |
| **K83** | Serbest hak, yayın anında **yeten en düşük** siparişten harcanır | Resolver "en yüksek"i döndürür (**okuma**); tüketim tersini ister. 249 ₺'lik iş için 549 ₺'lik hak yakılmaz | ✅ Faz 9 |
| **K84** | Bakım komutları `--dry-run` taşır ve zamanlayıcıdan **önce elle** koşulur | Bir bakım komutunun ilk gerçek koşusu üretimde ve gözlemsiz olmamalı | ✅ Faz 9 |
| **K85** | Güvenlik başlıkları **middleware'de**, nginx'te değil | **B10**: `nginx.conf` sunucuda yaşar ve taşımada sessizce geride kalır. Middleware sürümlenir, test edilir ve paylaşımlı hostingde de çalışır (**K80**) | ✅ Faz 9 |
| **K86** | `config/cors.php` **yayınlandı ve daraltıldı**; `exposed_headers: ['ETag']` | Laravel varsayılanı `allowed_origins => ['*']` **ve** boş `exposed_headers`. İkincisi K7/K46'nın polling optimizasyonunu **sessizce** öldürüyordu: ETag CORS güvenli listesinde değil, JS onu okuyamaz | ✅ Faz 9 |

---

## B. Kurallar'a eklenecek (§11)

| # | Kural | En kısa hâli |
|---|---|---|
| **B10** | Kalite kapısının ve kurulumun bağımlı olduğu **her şey sürümlenir** | *Yeşil, taşınabilir olmadığı sürece bir bilgi taşımaz* |
| **K80** | Altyapı en düşük ortak paydaya yazılır | *Redis bir yükseltmedir, bir varsayım değil* |
| **E12** | Bir kolonun anlamı, ona yazan **tüm yolların** toplamıdır | *Kritik ayrım bir alanın yokluğuna değil varlığına yazılır* |

> **Toplam kural sayısı: 140** (FAZ-0 31 · FAZ-1 19 · FAZ-2 20 · FAZ-3 15 ·
> FAZ-4 11 · FAZ-5 10 · FAZ-6 11 · FAZ-7 10 · FAZ-8 10 · **FAZ-9 3**)

---

## C. Dersler'e eklenecek (§13)

**60. 🔴 Bir kolonun anlamı, ona yazan TÜM yolların toplamıdır.**
`invitation_id IS NULL` yazıldığı gün tek bir şey demekti: *paket alımı*. Ve o
gün doğruydu — `NULL` olmanın tek yolu paket satın almaktı. `nullOnDelete`
ikinci bir yol açtığında anlam, **kimse dosyaya dokunmadan** değişti. Bir
sorgunun doğruluğu, yazıldığı andaki dünyanın doğruluğudur; o dünyayı
değiştiren şey başka bir dosyada durabilir.

**61. 🔴 Sekiz fazdır yeşil yanan bir kapı, doğru sebeple yanıyor olmayabilir.**
`composer check`, `phpunit.xml`'in istediği `tests/Unit` dizinine bağımlıydı ve
o dizin **hiçbir commit'te yoktu** — git boş dizin saklamaz; o klasör yalnızca
bir makinede kalmış bir kalıntıydı. Temiz bir klonda, CI'da ya da üretim
sunucusunda kapı sekiz faz boyunca kırmızı olurdu. Ders 34'ün araç
katmanındaki hâli — ve aynı hata `.env.example`'da ikinci kez tekrarladı.

**62. Bir tüketim adımı, tüketmeden önce "gerekli mi" diye sorar.**
Serbest hakkı bağlayan adım koşulsuz çağrılsaydı, Elit paketi olan bir
kullanıcı her yayında elindeki tekil siparişi de harcardı. Ve tüketimde doğru
refleks **okumanınkinin tersidir**: resolver "en yüksek"i döndürür, tüketim
"yeten en düşük"ü harcar. Aynı veriye bakan iki kod farklı yönde optimize
edilir.

**63. Bir savunmayı "zaten başka bir savunma tutuyor" diye açmak, katmanlı
savunmanın tersidir.** CORS'ta `['*']` bırakmanın gerekçesi hazırdı: *"token
Authorization başlığında gidiyor, CSRF geçerli değil."* Doğru ama yetersiz —
`*`, herhangi bir sitedeki JavaScript'in `/api/public/` uçlarını sürmesine izin
verir. **L1** katmanların birbirinin yerine geçmediğini söyler; bu, aynı
kuralın yapılandırma tarafındaki hâli.

---

## D. §9 Teknik Durum — güncelleme

| | |
|---|---|
| Dal | `faz-9` |
| Uç nokta | **21** (değişmedi — Faz 9 uç eklemedi) |
| Test | **245** beklenen (198 + 47) · ilk **6 birim** testi |
| Kural | **140** · **Karar** 86 · **Ders** 63 |
| Yeni komut | `orders:expire` · `media:prune-orphans` |
| Yeni config | `config/cors.php` · `davetkart.orders.release_window_days` · `davetkart.media.orphan_grace_hours` |
| Yeni migration | `..._add_scope_to_orders_table` · `..._make_orders_scope_not_null` |

### Silinen

- `routes/web.php` (ölü `welcome` rotası — ders 26)
- `resources/views/welcome.blade.php`
- `tests/Unit/.gitkeep` (ilk gerçek birim testiyle yerini bıraktı)

---

## E. §10 Yol Haritası — güncelleme

| Faz | Durum |
|---|---|
| 9 | ⚠️ **14/14 adım yazıldı**, `composer check` **koşmadı**; Redis/S3/`IyzicoGateway` **ertelendi** |

**Sıradaki:** 🔴 **frontend yakalama fazı** — backend artık **altı** faz önde
(4/5/6/7/8/9). Faz 9'un iki maddesi doğrudan frontend'e dokunuyor:

1. `services/api.ts` → üretimde Vite proxy yok; gerçek origin
   `CORS_ALLOWED_ORIGINS`'e yazılmalı.
2. 🔴 ETag artık açığa çıkıyor (`exposed_headers`); polling'in `If-None-Match`
   gönderdiği **frontend tarafında doğrulanmalı**.

---

## F. §15 Açık Sorular — güncelleme

### ✅ Faz 9'da kapananlar

| Konu | Karar |
|---|---|
| ~~`routes/web.php` closure'ı~~ | **Silindi** (9.1). K30'un gerekçesi geçersizleşti: Laravel 13 closure'ları serileştiriyor |
| ~~Süresi dolmuş `pending` siparişler~~ | `orders:expire` (9.9) |
| ~~Yetim medya temizliği~~ | `media:prune-orphans` (9.10) |
| ~~`sanctum:prune-expired` zamanlanmış görevi~~ | `routes/console.php` (9.11) |
| ~~`DeleteInvitationAction`~~ | Yazıldı (9.7) — bir **iş kuralı doğduğu için** |
| ~~CORS~~ | K86 (9.12) |

### ⬜ Hâlâ açık (Faz 9'un eklediği)

| Konu | Not |
|---|---|
| 🔴 Zamanlanmış iş koşmazsa kimse bilmez | İzleme kararı: e-posta (K79 açık) / ping servisi / kendi kendini izleyen tablo |
| Silme sonucu kullanıcıya söylenmiyor | `DELETE` → 204. Doğru çözüm bir *"siparişlerim"* ucu |
| Ters yön yetim (diskte var, satırı yok) | S3 göçünden sonra bir kez elle sayım |
| Redis'e geçince `throttle` sınıfı değişir | `ThrottleRequestsWithRedis` — kovalar aynı, davranış birebir değil |
| Argon2id ölçülmedi | Hedef ~250 ms/hash; paylaşımlı hostingde `memory_limit` zorlayabilir |
| 🔴 `IyzicoGateway` | Sandbox anahtarı bekliyor. `PaymentGateway` arayüzü (K8) hazır: tek `match` kolu + tek bağlama satırı |
| 🔴 Redis · S3 · süpervizör | K80 gereği yükseltme; barındırma netleşince |

### ⬜ Önceki fazlardan devralınan

Değişmedi: K43 (paket kaç yayın açar) · K79 (bildirim kanalı) ·
`SubscriptionTier::label()` · asistan kotasının UTC gün sınırı ·
`contact_messages` okuma ucu · `rsvps.id` ULID (K52) · iade akışı ·
**Faz 5/6/7/8 elle doğrulama betikleri**.
