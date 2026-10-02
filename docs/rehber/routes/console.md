# `routes/console.php`

> **Faz:** 9 — Üretim hazırlığı, dosya 9.11 · 🆕 **Faz 10, adım 10.10** (§2, token temizliği)
> **İlgili:** [`app/Console/Commands/ExpireStaleOrders.md`](app/Console/Commands/ExpireStaleOrders.md) ·
> [`app/Console/Commands/PruneOrphanMedia.md`](app/Console/Commands/PruneOrphanMedia.md) ·
> [`config/sanctum.md`](../config/sanctum.md)
> **Kurallar:** ders 26 · **B4** · **B6** · **CLAUDE.md §4** (15 saniye kuralı) · **K90**

---

## 1. Projenin ilk **kendiliğinden çalışan** kodu

Bugüne kadar yazılan her satır bir **istek** tarafından tetikleniyordu:
kullanıcı bir uca vurur, kod çalışır, yanıt döner. Bu blok farklı — kimse
istemeden çalışıyor. Üç yeni risk getiriyor:

| Risk | Cevabı |
|---|---|
| Kimse bakmıyorken çalışır → hata sessizce yutulur | Log + `--dry-run` ile önce elle koşma (9.9/9.10) |
| Üst üste çalışabilir | `withoutOverlapping()` |
| Sunucu ayağa kalkmazsa hiç çalışmaz ve kimse fark etmez | 🔴 §5 — bugün **kapatılmadı** |

> 🔴 **Bu dosya tek başına hiçbir şey yapmaz.** Zamanlayıcının çalışması için
> sunucuda dakikada bir `php artisan schedule:run` çağrılmalı. Bu dosya *"ne
> zaman"* der; *"çalıştır"* diyen şey **işletim sistemidir**.

---

## 2. Üç iş, üç farklı sıklık

```php
Schedule::command('orders:expire')->hourly()
Schedule::command('media:prune-orphans')->dailyAt('03:15')
Schedule::command('sanctum:prune-expired --hours=24')->daily()
```

### `orders:expire` — saatlik

`order_expires_after_minutes` 30 dakika, yani bir sipariş en kötü ihtimalle
~90 dakika `pending` görünür. Daha sık koşmanın **değeri yok**: bu satırı
gerçek zamanlı okuyan kimse yok. Daha seyrek koşmak ise *"ödeme bekliyor"*
satırlarını gün boyu ekranda tutardı.

> **Sıklık de bir sözleşmedir.** Günlük olması gereken bir iş dakikalık
> koşarsa veritabanını döver; saatlik olması gereken bir iş haftalık koşarsa
> işini yapmaz. İkisi de sessiz hatalardır.

### `media:prune-orphans` — 03:15, gece yarısı **değil**

İki sebep:

1. **Gece yarısı bir tepedir.** Raporlar, yedekler, log rotasyonu, fatura
   işleri — hepsi 00:00'da başlar. Bakım işleri o tepeden uzak tutulur.
2. **`:00` yerine `:15`.** Aynı dakikada başlayan işlerin veritabanına aynı
   anda yüklenmesini önler — *thundering herd*'in küçük ölçekli hâli.

🔴 Saat dilimi **bilerek ayarlanmadı**: sunucu UTC'de koşar ve bu iş için
*"saat kaçta"* sorusunun iş tarafında bir cevabı yok — kimseyi rahatsız
etmiyor, kimseye görünmüyor. **K71'in tersi**: orada saat dilimi *anlamlıydı*
(düğünün olduğu yerin saati); burada değil. Her zaman damgasına saat dilimi
eklemek, hiçbirine eklememek kadar yanlıştır.

### `sanctum:prune-expired --hours=24`

🔴 Laravel bu komutu **Faz 2'den beri sağlıyordu** ve sekiz fazdır
çağrılmadı: `personal_access_tokens` her girişle büyüyor, hiçbir şey
küçültmüyor. Bir yılda binlerce satır — hepsi ölü.

#### Komut ne siler? (kaynak: `vendor/laravel/sanctum/src/Console/Commands/PruneExpired.php`)

İki sorgu çalıştırır:

| # | Sorgu | Bizde |
|---|---|---|
| 1 | `expires_at < şimdi − saat` | **Hiçbir şey.** Token'larımız `createToken()` ile `expires_at` **olmadan** üretiliyor; kolon hep `NULL` |
| 2 | `created_at < şimdi − (expiration + saat)` | **Asıl iş bu.** Yalnızca `config/sanctum.php` → `expiration` doluyken çalışır |

`expiration` = 30 gün (43 200 dakika), `--hours=24` → `created_at`'i **31 günden
eski** her token silinir. Yani token ömrünü doldurduktan bir gün sonra.

#### Faz 9'da ne oluyordu? (B4)

> 🔴 **23 Eylül 2026'dan 10.10'a kadar bu iş HİÇBİR ŞEY SİLMEDİ.** `expiration`
> `null`'dı; ikinci sorgu hiç koşmadı, komut her gece *"Expiration value not
> specified in configuration file"* uyarısını basıp geçti. Birinci sorgunun da
> eşleşeceği satır yoktu.
>
> Sonuç: tablo büyüdü **ve** hiçbir token süresi dolmadı — çalınan bir token
> sonsuza kadar geçerliydi. Düzeltme bir **karar** istedi (K90, 30 gün, mutlak)
> ve bu satırda değil, [`config/sanctum.php`](../config/sanctum.md)'de yapıldı.

Bu, **ders 26**'nın zamanlayıcıdaki hâli: komut yazılmış, zamanlanmış, `schedule:list`'te
görünüyor, hiçbir hata vermiyor — ve hiçbir şey yapmıyor. Bir bakım işinin
*"koştuğunu"* görmek, *"iş yaptığını"* görmek değildir. Doğrusunu yalnızca
**silinen satırı sayan** bir test söyler (`MaintenanceTest`, adım 10.11).

#### Neden `--hours=720` değil de `24`?

Faz 9 `720` (30 gün) yazmıştı. Gerekçe: *"bir güvenlik incelemesinde hangi token
ne zaman iptal edildi sorusunun izi kalmalı."* Gerekçe iki yerden yanlıştı:

1. **İptal edilen token'ın satırı zaten yok.** `RevokeTokenAction` çıkışta
   satırı **anında siler** (`$token->delete()`). Saklanacak bir iz hiç oluşmadı.
2. **Süresi dolan token'ın satırı bilgi taşımıyor.** Guard süresi dolmuş token'ı
   reddederken `last_used_at`'i **güncellemez** (`Guard.php`: geçersiz token'da
   `return`, güncelleme çağrısından önce). Satır bize *"reddedilen bir deneme
   oldu mu?"* sorusunu bile cevaplayamaz.

İzi olmayan bir şeyi saklamak yalnızca tabloyu iki katına çıkarıyordu (30 gün
yaşayan + 30 gün bekleyen satırlar). `24` paketin kendi varsayılanı; `0` ile
pratik farkı yok ve varsayılandan sapmak için bir sebep kalmadı.

> **B6 — kapatmadığı delik:** *"Hangi token ne zaman kullanıldı/iptal edildi"*
> sorusunun bugün **hiçbir** cevabı yok. İsteniyorsa yeri bu tablo değil, bir
> denetim (audit) log'udur. Faz 10'un kapsamında değil.

---

## 3. `withoutOverlapping()` — idempotansa değil yapıya güven

Her üç işte de var. Önceki koşu bitmeden ikincisi başlarsa iki süreç aynı
satırları siler/günceller.

`orders:expire` için zararsız (idempotent — toplu `UPDATE` ikinci kez hiçbir
satır bulmaz). `media:prune-orphans` için **değil**: ikinci süreç aynı yetim
satırı okuyup dosyasını silmeye çalışır, birinci süreç ise satırı silmek
üzeredir.

Koruma işin idempotansına değil **yapıya** bağlanıyor — çünkü idempotans
yarın bozulabilir ve bozulduğunda kimse bu dosyayı hatırlamaz.

`onOneServer()`: bugün tek sunucu var, yarın iki olursa aynı iş iki kez
koşmaz. Bedeli sıfır, faydası ileride.

---

## 4. 15 saniye kuralı burada anlamını buluyor

`CLAUDE.md` §4 der ki: *"uzun sürecek işler asla ana HTTP sürecini
bekletmemeli."* Faz 6'dan beri `OptimizeUploadedImage` kuyruğa gidiyordu ama
**kuyruk hiç çalışmadı** — testlerde `sync`, geliştirmede kimse `queue:work`
koşmadı.

Bu dosya kuyruğu başlatmıyor (o 9.12'nin işi), ama aynı fikri kuruyor: bir
isteğin beklemesi gerekmeyen iş, istekten **ayrı bir zamanda** koşar.

---

## 5. 🔴 Kapatmadığı delik: iş koşmazsa kimse bilmez

`schedule:run` cron'a kurulmazsa — ya da kurulur, sonra sunucu taşınırken
unutulursa — bu dosyadaki hiçbir satır **hiç** çalışmaz. Ve hiçbir şey hata
vermez: `composer check` yeşil, uçlar çalışıyor, disk sessizce doluyor.

Bu, izleme (monitoring) sorusudur ve bugün **çözülmedi**. Seçenekler:

| Seçenek | Maliyet |
|---|---|
| `->emailOutputOnFailure()` | SMTP kararı gerekiyor (K79 hâlâ açık) |
| Healthchecks.io tarzı bir "ping" servisi | Dış bağımlılık |
| `schedule:run`'ın son koşma zamanını bir tabloya yazmak | Kendi kendini izleyen sistem — klasik hata |

Üçü de bir **karar** istiyor, dosya değil. `FAZ-9.md`'ye açık madde olarak
yazılıyor. **B6**: bir savunmanın neyi kapatmadığı da yazılır.

Bugünkü asgari cevap `FAZ-9-ELLE-DOGRULAMA.md`'de: kurulumdan sonra
`schedule:list` çıktısı **gözle** doğrulanacak ve ertesi gün tabloya bakılacak.

> ✅ **Faz 10 (10.55 · K103) — kapandı.** Sentry Cron Monitors seçildi; ayrıntı en altta.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `schedule:run`'ı cron'a kurmayı unutmak | Hiçbir iş koşmaz, hiçbir şey hata vermez (§5) |
| 2 | `withoutOverlapping()` yazmamak | İki süreç aynı satırları siler |
| 3 | Her işi gece yarısına koymak | Aynı anda başlayan yığın; veritabanı tepesi |
| 4 | Anlamı olmayan bir işe saat dilimi vermek | Gereksiz karmaşıklık; K71'in yanlış tarafa uygulanması |
| 5 | `sanctum.expiration`'ı `null` bırakıp `sanctum:prune-expired`'ı zamanlamak | Komut her gece bir uyarı basar, **hiçbir satır silmez** (Faz 9'dan 10.10'a kadar tam olarak buydu, §2) |
| 5b | *"İz kalsın"* diye `--hours`'ı büyütmek | İz zaten yok (çıkışta satır anında siliniyor); tablo boşuna büyür (§2) |
| 6 | Komutu yazıp zamanlayıcıya eklememek | Kod var, koşan yok — ders 26 |

---

## 7. Kendin dene

```powershell
php artisan schedule:list
```

Üç satır görmelisin: `orders:expire` (saatlik), `media:prune-orphans` (03:15),
`sanctum:prune-expired` (günlük) — ve her birinin bir sonraki koşma zamanı.

Zamanlayıcıyı beklemeden **elle** tetikle:

```powershell
php artisan schedule:run       # o dakikada koşması gereken işleri çalıştırır
php artisan schedule:test      # hangi işi koşacağını sorar, seçtiğini hemen koşar
```

🔴 `schedule:test` bu fazın en faydalı komutu: bir bakım işini ilk kez
zamanlayıcıdan görmek, onu hiç görmemektir.

Token temizliğinin **gerçekten** iş yaptığını görmek (Faz 10, 10.10):

```powershell
php artisan sanctum:prune-expired --hours=24
```

İki görev satırı görmelisin ve **"Expiration value not specified"** uyarısı
**olmamalı**. Uyarıyı görüyorsan `config/sanctum.php` → `expiration` boş ya da
`config:cache` eski bir kopyayı tutuyor (`php artisan config:clear`).

> ⚠️ Bu komut geliştirme veritabanında da **gerçekten siler** (31 günden eski
> token'lar). Oturumun düşerse yeniden giriş yap.

Üretimde (VPS, cron):

```
* * * * * cd /var/www/davetkart && php artisan schedule:run >> /dev/null 2>&1
```

Paylaşımlı hostingde cPanel → Cron Jobs → aynı satır. Dakikada bir koşar ve
Laravel **hangi işin sırası geldiğine kendisi karar verir** — cron'a üç ayrı
satır yazılmaz.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Cron** | Unix'te zamanlanmış görev çalıştırıcısı |
| **Cron ifadesi** | `dakika saat gün ay haftagünü` — `15 3 * * *` = her gün 03:15 |
| **Mutex** | Aynı işin iki kopyasının aynı anda koşmasını engelleyen kilit |
| **Thundering herd** | Aynı anda başlayan çok sayıda işin kaynağı boğması |
| **İdempotan** | Birden çok kez çalıştırıldığında sonucu değişmeyen işlem |

---

## 🆕 Faz 10 eklemesi — `data:purge` (10.44 · K98)

```php
Schedule::command('data:purge')
    ->dailyAt('03:45')
    ->withoutOverlapping()
    ->onOneServer();
```

Saklama süresi dolan kişisel veriyi siler: çöp kutusundaki davetiye (30 gün), misafir
verisi (etkinlikten 6 ay sonra), iletişim mesajı (12 ay). Ayrıntı:
[`PurgeExpiredData.md`](../app/Console/Commands/PurgeExpiredData.md).

**Neden 03:45?** `media:prune-orphans` 03:15'te. İkisi de diskten dosya siliyor ve
aynı dakikada başlasalar aynı diski aynı anda yorarlar (§2'deki *thundering herd*).
Yarım saat, prune işinin bitmesi için cömert bir pay.

**İlk koşu (K84):** yayına alındığı gün elle ve `--dry-run` ile. Faz 10'dan önce
hiçbir şey silinmediği için ilk gerçek koşu birikmiş verinin hepsini bir kerede siler.

Zamanlayıcıda artık **dört** iş var. §7'deki `schedule:list` beklentisi:
`orders:expire` (saatlik) · `media:prune-orphans` (03:15) · `sanctum:prune-expired`
(günlük) · `data:purge` (03:45).

---

## 🆕 Faz 10 eklemesi — Sentry Cron Monitors (10.55 · K103)

§5'in açık deliği: zamanlayıcı durursa hiçbir iş koşmaz ve kimse fark etmez. Seçilen
çözüm Sentry'nin zamanlanmış iş izlemesi. Her işin sonuna tek satır eklendi:

```php
Schedule::command('data:purge')
    ->dailyAt('03:45')
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor('data-purge');
```

### Nasıl çalışıyor?

`sentryMonitor()` paketin (`sentry/sentry-laravel`) zamanlayıcıya eklediği bir makro. İşe
üç geri çağrı bağlar:

| An | Sentry'ye giden |
|---|---|
| İş başlarken (`before`) | *"başladım"* + işin sıklığı (`0 * * * *` gibi) |
| Başarıyla bitince | *"bitti, tamam"* |
| Hata verince | *"bitti, hata"* |

Sentry sıklığı bildiği için **beklenen** zamanı hesaplar. *"Başladım"* beklenen zamanda
gelmezse (zamanlayıcı durdu, sunucu kapandı, cron hiç kurulmadı) uyarı üretir. §5'teki
üçüncü seçeneğin *"kendi kendini izleyen sistem"* sorunu burada yok: izleyen taraf
sunucunun dışında.

### Neden sabit adlar?

Ad verilmezse paket adı komut satırından türetir: `sanctum:prune-expired --hours=24`
için ad argümanı da içerir. Argüman bir gün değişirse Sentry bunu **yeni** bir iş sanar,
eski izleyici *"koşmadı"* diye uyarmaya başlar ve geçmiş kopar. Sabit ad bunu önler.

| İş | İzleyici |
|---|---|
| `orders:expire` | `orders-expire` |
| `media:prune-orphans` | `media-prune-orphans` |
| `sanctum:prune-expired --hours=24` | `sanctum-prune-expired` |
| `data:purge` | `data-purge` |

### DSN boşken ne olur?

Makro her zaman kayıtlı (paketin `register()`'ı DSN'e bakmaz), ama Sentry'ye bildirim
yalnızca DSN doluyken gider. Geliştirmede ve testlerde hiçbir şey değişmez, ağa çıkılmaz.

### Bilinen sınırlar (B6)

- **Ücret:** Sentry planının izleyici kotası deploy öncesi kontrol edilmeli (`docs/10`).
- **Sentry'nin kendisi kapalıysa** uyarı da gelmez. Bu, dışarıdan izlemenin kabul edilen
  bedeli.
- **Uyarının kime gideceği** Sentry panelinde ayarlanır, kodda değil.

**Test:** `MaintenanceTest::every_scheduled_command_reports_to_a_sentry_monitor`. Her işin
*"başladım"* geri çağrısını ve izleyici adını doğruluyor. Mutasyon: bir işten
`sentryMonitor()` silinince ya da ad verilmeyince kırılıyor.
