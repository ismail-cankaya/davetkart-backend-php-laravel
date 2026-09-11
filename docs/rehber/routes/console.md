# `routes/console.php`

> **Faz:** 9 — Üretim hazırlığı, dosya 9.11
> **İlgili:** [`app/Console/Commands/ExpireStaleOrders.md`](app/Console/Commands/ExpireStaleOrders.md) ·
> [`app/Console/Commands/PruneOrphanMedia.md`](app/Console/Commands/PruneOrphanMedia.md)
> **Kurallar:** ders 26 · **B6** · **CLAUDE.md §4** (15 saniye kuralı)

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
Schedule::command('sanctum:prune-expired --hours=720')->daily()
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

### `sanctum:prune-expired --hours=720`

🔴 Laravel bu komutu **Faz 2'den beri sağlıyordu** ve sekiz fazdır
çağrılmadı: `personal_access_tokens` her girişle büyüyor, hiçbir şey
küçültmüyor. Bir yılda binlerce satır — hepsi ölü.

`--hours=720` (30 gün): iptal edilmiş ya da süresi dolmuş token bir ay
saklanır, sonra silinir. **Sıfır yazmadık**, çünkü bir güvenlik incelemesinde
*"hangi token ne zaman iptal edildi"* sorusunun izi kalmalı. Bir temizlik işi
kadar hızlı olmalı, ama adli iz bırakacak kadar yavaş.

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

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `schedule:run`'ı cron'a kurmayı unutmak | Hiçbir iş koşmaz, hiçbir şey hata vermez (§5) |
| 2 | `withoutOverlapping()` yazmamak | İki süreç aynı satırları siler |
| 3 | Her işi gece yarısına koymak | Aynı anda başlayan yığın; veritabanı tepesi |
| 4 | Anlamı olmayan bir işe saat dilimi vermek | Gereksiz karmaşıklık; K71'in yanlış tarafa uygulanması |
| 5 | `--hours=0` ile token silmek | Adli iz kalmaz |
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
