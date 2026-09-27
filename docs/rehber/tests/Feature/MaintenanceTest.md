# `tests/Feature/MaintenanceTest.php`

> **Kod dosyası:** `tests/Feature/MaintenanceTest.php`
> **Faz:** 9 — Üretim hazırlığı, dosyalar 9.9 · 9.10 · 9.11 · 🆕 **Faz 10**, adım 10.5 (§5.1) · adım 10.11 (§6b)
> **Kılavuz yazımı:** 25 Eylül 2026 — **K18 borcu** (dosya Faz 9'da kılavuzsuz eklenmişti;
> plan 10.54'ün yarısı burada kapandı, `HardeningTest.md` hâlâ bekliyor)
> **Test sayısı:** 16 · **Test edilenler:** [`ExpireStaleOrders.md`](../../app/Console/Commands/ExpireStaleOrders.md) ·
> [`PruneOrphanMedia.md`](../../app/Console/Commands/PruneOrphanMedia.md) · [`routes/console.md`](../../routes/console.md) ·
> `sanctum:prune-expired` (Laravel'in komutu, [`config/sanctum.md`](../../config/sanctum.md))

---

## 1. Bu dosya neyi kanıtlıyor?

Buraya kadarki her test bir **istek** atıyordu: kullanıcı bir uca vurur, kod
çalışır, yanıt döner. Bu dosyanın test ettiği kod ise **kendiliğinden** çalışır:
zamanlayıcı gece 03:15'te bir komutu tetikler ve kimse bakmaz.

Bu yüzden her komut için aynı dört soru sorulur:

| # | Soru | Neden |
|---|---|---|
| 1 | Hedefi vuruyor mu? | Komutun varlık sebebi |
| 2 | 🔴 Hedef **olmayanı** bırakıyor mu? | Asıl koruma burada: bakım komutu yanlış satırı silerse kimse görmez |
| 3 | Sınırda ne oluyor? | Bekleme süresi, `NULL` son tarih |
| 4 | `--dry-run` gerçekten yazmıyor mu? | İlk üretim koşusu bununla yapılır |

Ve her iddia **yanıta değil etkiye** bakar (**T14**): komutun `0` çıkış kodu
dönmesi hiçbir şey kanıtlamaz — kanıt, hangi satırın değiştiği ve daha da
önemlisi hangisinin **değişmediğidir**.

---

## 2. `runCommand()` — neden `$this->artisan()` değil?

```php
private function runCommand(string $command, array $parameters = []): void
{
    $this->assertSame(Command::SUCCESS, Artisan::call($command, $parameters), …);
}
```

`$this->artisan(...)`'ın dönüş tipi `PendingCommand|int` bir **birleşimdir**:
`withoutMockingConsoleOutput()` çağrılırsa ham `int` döner ve üzerinde
`assertSuccessful()` çağrılamaz. Çalışma anında bu hiç olmaz, ama PHPStan level
8 bunu bilemez — ve bilmemekte haklıdır: gelecekteki bir `setUp()` onu
değiştirebilir.

`Artisan::call()` kesin olarak `int` döner. Tipi sonradan daraltmak yerine
**baştan tek tipli bir API** seçildi.

> Ders 18'in ailesi: bir aracın hata mesajı **belirtiyi** söyler
> (*"assertSuccessful çağrılamaz"*), **sebebi** değil (dönüş tipi birleşimdir).
> Çözüm mesajı susturmak değil, birleşimi hiç üretmemek.

---

## 3. `setUp()`: `Storage::fake()`

```php
Storage::fake(Config::string('davetkart.media.disk'));
```

Medya temizliği dosya siler; testler gerçek diske dokunmamalı. Disk adı
config'ten okunuyor, `'public'` diye yazılmıyor: üretimde disk S3 olduğunda
(K55) test yine doğru diski sahtelemiş olur.

⚠️ `Storage::fake()` **gerçek** diski hiç görmez. Faz 6'nın `storage:link`
dersi burada da geçerli: dosyanın gerçekten nerede durduğu yalnızca elle
doğrulamayla görülür.

---

## 4. Bölümler

| Bölüm | Test | Komut |
|---|---|---|
| `orders:expire` | 5 | [`ExpireStaleOrders`](../../app/Console/Commands/ExpireStaleOrders.md) |
| `media:prune-orphans` | 7 | [`PruneOrphanMedia`](../../app/Console/Commands/PruneOrphanMedia.md) |
| 🆕 `sanctum:prune-expired` | 1 | Laravel'in komutu + bizim config'imiz + zamanlayıcıdaki argüman (§6b) |
| Zamanlayıcı | 3 | [`routes/console.php`](../../routes/console.md) |

---

## 5. `orders:expire`

| Test | Soru | Kanıt |
|---|---|---|
| `it_expires_a_pending_order_whose_window_has_closed` | 1 | Durum `expired`, `paid_at` `NULL` |
| `it_leaves_a_pending_order_whose_window_is_still_open` | 3 | Durum `pending` |
| 🔴 `it_never_touches_a_paid_order` | 2 | `expires_at` bir gün geçmiş **ama** `paid` kaldı |
| `it_never_touches_an_order_without_an_expiry` | 3 | `NULL` son tarih = süre sınırsız (**N4**) |
| `the_dry_run_option_writes_nothing` | 4 | Durum `pending` |

`it_never_touches_a_paid_order` dosyanın en önemli testi: webhook gecikirse
`expires_at`'i geçmişte kalmış bir sipariş `paid` olabilir. Komut `status =
pending` koşulunu sorgunun **kapsamında** taşıdığı için o satıra dokunamaz.

### 5.1 🆕 Faz 10 (10.5): kırmızıya dönen test

Faz 9'da ilk testin adı `it_fails_a_pending_order_whose_window_has_closed` idi
ve `OrderStatus::Failed` bekliyordu. 10.5'te komut `expired` yazmaya başladı
ve plan şunu uyarmıştı (tuzak #3):

> *"`ExpireStaleOrders`'ın mevcut testleri `failed` bekliyor. Yeşil kalıyorsa
> test etkiyi değil yanıtı doğruluyordur."*

Kod değişti, test **değişmeden** koşturuldu ve kırıldı:

```
-App\Enums\OrderStatus Enum (Failed, 'failed')
+App\Enums\OrderStatus Enum (Expired, 'expired')
```

Kırmızı iyi haberdi: test kolonu okuyordu. Ancak ondan sonra adı ve beklentisi
değişti, ve bir satır eklendi:

```php
$this->assertSame(OrderStatus::Expired, $order->status);
$this->assertNull($order->paid_at);      // 🆕 expired, parası alınmış sayılmaz
```

Geç gelen ödemenin bu satırı **hâlâ açabildiğini** bu dosya değil
`PaywallTest` kanıtlıyor (10.7): orada komut gerçekten koşturulur, ardından
imzalı bir `paid` webhook'u gelir.

---

## 6. `media:prune-orphans`

| Test | Soru | Kanıt |
|---|---|---|
| `it_deletes_an_unreferenced_guest_upload_past_the_grace_period` | 1 | Satır yok |
| `it_keeps_an_unreferenced_upload_inside_the_grace_period` | 3 | Formu dolduran misafirin dosyası silinmez |
| `it_keeps_an_upload_referenced_by_an_rsvp_photo` | 2 | Satır duruyor |
| `it_keeps_an_upload_referenced_by_an_rsvp_video` | 2 | 🔴 İki FK kolonu var; sorgu **ikisini de** görmeli |
| 🔴 `it_never_touches_gallery_media` | 2 | Bir yıllık galeri dosyası duruyor |
| `it_removes_the_file_from_its_own_disk` | 1 | 🔴 Dosya **diskten** gitti — satırı silmek yer açmaz |
| `the_prune_dry_run_deletes_nothing` | 4 | Satır duruyor |

`it_never_touches_gallery_media`'nın gerekçesi: galeri dosyası bir LCV'ye
bağlanmaz, davetiyenin galerisinin **kendisidir**. *"Hiçbir LCV işaret
etmiyor"* ölçütü ona uygulansaydı komut bütün galerileri silerdi. Ölçüt, türün
ne işe yaradığına bağlı.

---

## 6b. 🆕 `sanctum:prune-expired` (Faz 10, 10.11)

### Neden bir **vendor** komutunu test ediyoruz?

Laravel'in komutunu değil, **bizim ona verdiğimiz iki şeyi** test ediyoruz:

| Girdi | Nerede | Faz 9'da |
|---|---|---|
| `sanctum.expiration` | `config/sanctum.php` | `null` → asıl sorgu hiç koşmadı |
| `--hours` | `routes/console.php` | `720` |

Komut Faz 9'dan 10.10'a kadar zamanlanmıştı, `schedule:list`'te görünüyordu,
hata vermiyordu — ve **hiçbir şey silmiyordu** (B4, [`routes/console.md`](../../routes/console.md) §2).
§7'deki kayıt testi bunu yakalayamazdı: o, satırın **var olduğunu** sorar, **iş
yaptığını** değil. Yalnızca silinen satırı sayan bir test yakalar (T14).

### Test

```php
$stale      = $this->tokenIssuedAt($user, now()->subDays(31)->subMinute());
$inGraceDay = $this->tokenIssuedAt($user, now()->subDays(31)->addMinute());
$alive      = $this->tokenIssuedAt($user, now()->subDay());

$this->runCommand($this->scheduledArtisanCommand('sanctum:prune-expired'));

$this->assertModelMissing($stale);
$this->assertModelExists($inGraceDay);
$this->assertModelExists($alive);
```

| Token | Yaşı | Durumu | Beklenen | Dörtlü soru |
|---|---|---|---|---|
| `$stale` | 31 gün + 1 dk | Süresi dolmuş, bekleme günü de geçmiş | **Silinir** | 1 (hedef) · 3 (sınır) |
| `$inGraceDay` | 31 gün − 1 dk | Süresi dolmuş, bekleme gününde | Kalır | 2 (hedef değil) · 3 (sınır) |
| `$alive` | 1 gün | Geçerli | Kalır | 2 (hedef değil) |

Sınırın formülü: `expiration` (30 gün) + `--hours` (24) = **31 gün**. Soru 4
(`--dry-run`) bu komut için yok: Laravel'in komutunun böyle bir seçeneği yok.

> **Sınır neden dakika, `AuthTest` §3.6'daki gibi saniye değil?** Orada Guard'ın
> kendi `now()`'ı sınırın **tam üstüne** getiriliyordu (`travelTo`). Burada
> token'lar `now()`'a göre yazılıyor ve komut birkaç milisaniye sonra kendi
> `now()`'ını alıyor. Bir dakikalık pay, iki an arasındaki kaymayı yutar ama
> ömrü 29 ya da 31 güne kaydıran bir mutasyonu (≥ 1 gün) kaçırmaz.

### 🔴 `scheduledArtisanCommand()` — zamanlayıcıdaki satırı **aynen** koşturmak

Test komutu `['--hours' => 24]` gibi **elle yazılmış** argümanlarla çağırsaydı,
yarın biri zamanlayıcıyı yeniden `--hours=720` yaptığında test yeşil kalırdı:
test kendi argümanını sınıyor olurdu, üretimin koşacağını değil.

Bunun yerine yardımcı, zamanlayıcıya kaydedilmiş satırı okuyup `Artisan::call()`'a
verilebilecek hâle getiriyor:

```
Windows: "C:\...\php.exe" "artisan" sanctum:prune-expired --hours=24
Linux  : '/usr/bin/php' 'artisan' sanctum:prune-expired --hours=24
Sonuç  : sanctum:prune-expired --hours=24
```

Tırnak işletim sistemine göre değişiyor (Laravel `ProcessUtils::escapeArgument`
kullanıyor); düzenli ifade `['"]artisan['"]` ikisini de kabul ediyor.
`Artisan::call()` argümanlı bir dizgiyi kendisi ayrıştırır (`StringInput`).

> **Neden `$event->run()` değil?** O, komutu **ayrı bir PHP sürecinde** başlatır.
> `RefreshDatabase` her testi bir transaction içinde koşturduğu için ayrı süreç
> testin yazdığı token'ları **göremez**: test boş bir tabloyu temizler ve geçer.

### `tokenIssuedAt()` — `forceFill`

`created_at` `$fillable`'da değil ve olmamalı: zamanı model yazar, istemci değil.
Test zamanı geriye almak için `forceFill()` kullanıyor: `$fillable`'ı atlayan,
**yalnızca** bilerek çağrılan yol. Uygulama kodunda karşılığı yok.

---

## 7. Zamanlayıcı

| Test | Neyi yakalar |
|---|---|
| `the_maintenance_commands_are_registered_with_the_scheduler` | 🔴 Bir **hatayı** değil bir **unutmayı**: kayıtsız komut hiç koşmaz ve bunu hiçbir şey söylemez |
| `each_maintenance_command_runs_at_its_intended_cadence` | Sıklık da sözleşmedir: `0 * * * *` · `15 3 * * *` · `0 0 * * *` |
| ⚠️ `every_scheduled_command_guards_against_overlapping` | **Hiçbir şeyi** — §8.1 |

---

## 8. Mutasyon tablosu (25 Eylül 2026, kum havuzunda koşturuldu)

| # | Mutasyon | Kırılan test |
|---|---|---|
| 1 | `ExpireStaleOrders` yine `Failed` yazsın (10.5'i geri al) | `it_expires_a_pending_order_…` |
| 2 | `->where('status', Pending)` satırını sil | `it_never_touches_a_paid_order` |
| 3 | `--dry-run` koşulunu `if (false)` yap | `the_dry_run_option_writes_nothing` |
| 4 | `->whereNotNull('expires_at')` satırını sil | ⚪ Hiçbiri — **eşdeğer mutant**: SQL'de `NULL < now()` zaten `NULL`, satır yine elenir (`ExpireStaleOrders.md` §3) |
| 5 | Bekleme süresi koşulunu sil | `it_keeps_an_unreferenced_upload_inside_the_grace_period` |
| 6 | `video_media_id` kolunu sil | `it_keeps_an_upload_referenced_by_an_rsvp_video` |
| 7 | Tür süzgecini (`guestUploadableValues`) sil | `it_never_touches_gallery_media` |
| 8 | `Storage::…->delete()` satırını sil | `it_removes_the_file_from_its_own_disk` |
| 9 | `orders:expire`'dan `withoutOverlapping()`'i sil | ⚠️ **Hiçbiri** — §8.1 |
| 10 | `orders:expire`'dan `onOneServer()`'ı sil | ⚠️ **Hiçbiri** — §8.1 |

**Faz 10, 10.11 — `sanctum:prune-expired` (27 Eylül 2026, İsmail'in makinesi, PHP 8.5):**

| # | Mutasyon | Kırılan iddia |
|---|---|---|
| 11 | `config/sanctum.php` → `'expiration' => null` (Faz 9'un hâli) | `$stale` silinmedi |
| 12 | `'expiration' => 60 * 24 * 29` | `$inGraceDay` silindi |
| 13 | `routes/console.php` → `--hours=720` (Faz 9'un hâli) | `$stale` silinmedi |
| 14 | `--hours=0` | `$inGraceDay` silindi |

13. satır `scheduledArtisanCommand()`'ın varlık sebebi: argüman testte elle
yazılsaydı bu mutasyon **hayatta kalırdı**.

Dördüncü satır bir boşluk değil: mutasyon programın davranışını değiştirmiyor.
Eşdeğer bir mutantı öldürecek test yazılamaz — ve yazılmaya çalışılmamalı.

### 8.1 🔴 Boş yeşil: `every_scheduled_command_guards_against_overlapping`

```php
$this->assertNotNull($event->mutexName(), …);
```

`mutexName()` her zamanlanmış iş için bir kilit **adı** üretir —
`withoutOverlapping()` çağrılmış olsun ya da olmasın. Yani bu iddia hiçbir
zaman kırılamaz. Mutasyon 9 bunu kanıtladı: koruma silindi, test yeşil kaldı.

Doğrusu, korumanın **kendisini** sormak:

```php
$this->assertTrue($event->withoutOverlapping, …);   // Illuminate\Console\Scheduling\Event
$this->assertTrue($event->onOneServer, …);           // mutasyon 10'u da öldürür
```

**Bu adımda düzeltilmedi**: 10.5'in işi `orders:expire`'ın yazdığı değer,
zamanlayıcı testleri değil. Bulgu FAZ-10 planına Dilim E'nin yeni satırı olarak
eklendi (test denetiminin bu dosya için *"yüzeysel incelendi"* dediği yer tam
burası). **B6**: bir savunmanın neyi kapatmadığı da yazılır.

---

## 9. Bu dosyanın kapatamadıkları (B6)

| Kapatmaz | Neden / nerede |
|---|---|
| Zamanlayıcının üretimde **gerçekten** koşması | Dakikada bir `schedule:run` çağıran cron'a bağlı; test yalnızca kaydı görür. Plan 10.55: Sentry Cron Monitors |
| Gerçek disk | `Storage::fake()` (§3) |
| Toplu `UPDATE` ile eşzamanlı webhook yarışı | **T15**: tek süreçli testte kurulamaz. `ExpireStaleOrders.md` §9.3 mantığı anlatıyor |
| Geç ödemenin `expired` satırı açması | Bu dosyada değil, `PaywallTest` (10.7) |
| Süresi dolan token'ın **reddedilmesi** | Bu dosya yalnızca satırın silinmesini görür; reddi `AuthTest` §3.6 kanıtlıyor (10.11) |

---

## 10. Kendin dene

```powershell
php artisan test --filter=MaintenanceTest
# 16 passed
```

Mutasyon 13'ü elle dene: `routes/console.php`'de `--hours=24`'ü `--hours=720`
yap, `php artisan test --filter=the_scheduled_token_prune` koştur. **Kırmızı**
olmalı (§6b). Geri almayı unutma.

Mutasyon 9'u elle dene: `routes/console.php`'de `orders:expire`'ın
`->withoutOverlapping()` satırını sil, testi koştur. **Yeşil** kalacak — §8.1'in
kanıtı. Sonra satırı geri koymayı unutma.

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Boş yeşil** | Koruduğu kod silindiğinde bile geçen test |
| **Eşdeğer mutant** | Kodu değiştiren ama davranışı değiştirmeyen mutasyon; hiçbir test onu öldüremez |
| **Mutex** | Aynı işin iki kopyasının aynı anda koşmasını engelleyen kilit |
| **`onOneServer()`** | Birden çok sunucuda zamanlayıcı koşsa bile işin yalnızca birinde çalışması |
