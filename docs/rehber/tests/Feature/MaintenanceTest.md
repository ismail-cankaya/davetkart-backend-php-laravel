# `tests/Feature/MaintenanceTest.php`

> **Kod dosyası:** `tests/Feature/MaintenanceTest.php`
> **Faz:** 9 — Üretim hazırlığı, dosyalar 9.9 · 9.10 · 9.11 · 🆕 **Faz 10**, adım 10.5 (§5.1) · adım 10.11 (§6b) · adım 10.14 (§6c)
> **Kılavuz yazımı:** 25 Eylül 2026 — **K18 borcu** (dosya Faz 9'da kılavuzsuz eklenmişti;
> plan 10.54'ün yarısı burada kapandı, `HardeningTest.md` hâlâ bekliyor)
> **Test sayısı:** 28 · **Test edilenler:** [`ExpireStaleOrders.md`](../../app/Console/Commands/ExpireStaleOrders.md) ·
> [`PruneOrphanMedia.md`](../../app/Console/Commands/PruneOrphanMedia.md) · [`routes/console.md`](../../routes/console.md) ·
> `sanctum:prune-expired` (Laravel'in komutu, [`config/sanctum.md`](../../config/sanctum.md)) ·
> [`NormalizeUserEmails.md`](../../app/Console/Commands/NormalizeUserEmails.md)

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
| 🆕 `users:normalize-emails` | 5 | [`NormalizeUserEmails`](../../app/Console/Commands/NormalizeUserEmails.md) (§6c) |
| 🆕 `data:purge` | 6 | [`PurgeExpiredData`](../../app/Console/Commands/PurgeExpiredData.md) (§6e) |
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

## 6c. 🆕 `users:normalize-emails` (Faz 10, 10.14)

Faz 9'a kadar `İsmail@…` ile kaydolmuş hesapların satırındaki `i̇` (i + U+0307)
dizisini kanonik biçime getiren tek seferlik bakım komutu.

| Test | Soru | Kanıt |
|---|---|---|
| 🔴 `it_folds_a_legacy_dotted_i_email_so_the_user_can_log_in_again` | 1 | Önce giriş **401**, komuttan sonra kolon baytı baytına `ismail…` **ve** giriş **200** |
| `it_leaves_an_already_canonical_email_untouched` | 2 | Kolon aynı, `updated_at` bile değişmedi |
| 🔴 `it_skips_and_reports_an_address_another_account_already_holds` | 2 · 3 | `FAILURE` · iki hesap da olduğu gibi · **aynı koşudaki** ilgisiz bozuk satır yine düzeldi · rapor çakışan hesabın numarasını (`#<id>`) söylüyor |
| `it_skips_two_legacy_rows_that_fold_into_the_same_address` | 3 | `FAILURE` · iki satır da yazılmadı |
| `the_normalize_dry_run_writes_nothing_and_shows_the_hidden_dot` | 4 | Kolon aynı · çıktıda `i\u0307smail…` görünüyor |

### İlk testin iki yarısı

Kolonun düzeldiğini görmek yetmez. Komutun **amacı** kullanıcının yeniden
girebilmesi. Test o yüzden giriş ucunu komuttan **önce** (401: bozuk durum
gerçekten kuruldu) ve **sonra** (200) çağırıyor. İlk 401 olmasa test, bozuk
satırı hiç kuramamış bir kurulumda da yeşil kalabilirdi.

### `userWithStoredEmail()` — mutator'ı atlamak

```php
DB::table('users')->where('id', $user->id)->update(['email' => $raw]);
```

Model üzerinden yazılsaydı 10.13'ten beri normalizer değeri düzeltirdi ve
testin kurmak istediği bozuk durum **hiç oluşmazdı**. Sorgu oluşturucu
(query builder) modeli ve mutator'ı atlar: Faz 9'un yazdığı satırın aynısı.

### Neden `runCommand()` değil de `Artisan::call()`?

`runCommand()` çıkış kodunun `SUCCESS` olduğunu **iddia eder** (§2). Çakışma
testleri ise tam tersini bekliyor: `FAILURE`. Orada `Artisan::call()`'ın dönüş
değeri doğrudan karşılaştırılıyor.

### Görünmez nokta ve düzenleme aracı

Dry-run testi çıktıda `i\u0307smail.cankaya@gmail.com` **metnini** arıyor: ters
bölü, `u`, `0307`. İlk yazımda iğne, düzenleme aracı tarafından **gerçek**
U+0307 karakterine çevrildi ve test kırmızı yandı. Komut doğruydu, iğne yanlıştı.
Hata mesajı ipucunu veriyordu: iğnenin uzunluğu 26 bayttı (kaçış metni 30
olurdu). Görünmez karakterle çalışırken **baytlara bak**.

---

## 6e. 🆕 `data:purge` (Faz 10, 10.45 · K98)

Saklama süresi dolan kişisel veriyi silen gece işi. Bütün testler aynı sabit
"şimdi"yi kullanıyor (`PURGE_NOW = 2026-10-01 12:00 UTC`); sınırlar ona göre **elle**
yazıldı:

| Süre | Sınır | Silinen (sınırın öbür yanı) | Kalan |
|---|---|---|---|
| Çöp kutusu 30 gün | 2026-09-01 | 2026-08-31'de silinen | 2026-09-02'de silinen |
| Misafir verisi 6 ay | 2026-04-01 | 2026-03-31'deki etkinlik | 2026-04-02'deki etkinlik |
| İletişim 12 ay | 2025-10-01 | 2025-09-30'daki mesaj | 2025-10-02'deki mesaj |

**Sınırlar neden sabit yazıldı?** Config'ten hesaplansaydı (`now()->subDays(config(…))`)
süre değiştiğinde beklenen değer de değişir ve test yeşil kalırdı: test fonksiyonu
kendisiyle karşılaştırmış olurdu (Dilim E 10.49'un `PaywallTest`'te bulduğu hata).
Süreler kullanıcıya verilmiş bir söz; değişirse bu testler **bilerek** kırılmalı
(mutasyon M6–M8).

| Test | Soru | Kanıt |
|---|---|---|
| `a_trashed_invitation_is_purged_with_its_files_after_thirty_days` | 1 · 3 | 31 günlük gitti, **dosyası** da · 29 günlük çöp kutusunda |
| `a_live_invitation_is_never_purged` | 2 | 2024'te açılmış canlı davetiye yerinde |
| 🔴 `guest_data_is_purged_six_months_after_the_event` | 1 · 2 · 3 | LCV ve misafir fotoğrafı (dosyasıyla) gitti · davetiye ve **galeri** yerinde · sınırın öbür yanı ve tarihsiz davetiye yerinde |
| `contact_messages_are_purged_after_twelve_months` | 1 · 3 | |
| 🔴 `orders_survive_the_purge` | 2 | Kalıcı silinen davetiyenin siparişi kaldı (`invitation_id = NULL`, hâlâ `paid`) |
| `the_purge_dry_run_counts_but_deletes_nothing` | 4 | Satırlar yerinde · çıktı *"1 davetiye kalıcı silindi (yazılmadı)"* |

`trashedInvitationAt()`, çöp kutusuna atılma zamanını `forceFill(['deleted_at' => …])`
ile geriye alıyor (`tokenIssuedAt()` ile aynı gerekçe: zamanı yalnızca test yazar).

## 7. Zamanlayıcı

| Test | Neyi yakalar |
|---|---|
| `the_maintenance_commands_are_registered_with_the_scheduler` | 🔴 Bir **hatayı** değil bir **unutmayı**: kayıtsız komut hiç koşmaz ve bunu hiçbir şey söylemez |
| `each_maintenance_command_runs_at_its_intended_cadence` | Sıklık da sözleşmedir: `0 * * * *` · `15 3 * * *` · `0 0 * * *` |
| ✅ `every_scheduled_command_guards_against_overlapping` | Bir işten `withoutOverlapping()` silinmesini (10.54b'de düzeltildi, §8.1) |
| 🆕 `every_scheduled_command_runs_on_one_server` | Bir işten `onOneServer()` silinmesini (10.54b) |

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
| 9 | `orders:expire`'dan `withoutOverlapping()`'i sil | ⚠️ **Hiçbiri** — §8.1 · ✅ 10.54b'den sonra: `every_scheduled_command_guards_…` |
| 10 | `orders:expire`'dan `onOneServer()`'ı sil | ⚠️ **Hiçbiri** — §8.1 · ✅ 10.54b'den sonra: `every_scheduled_command_runs_on_one_server` |

**Faz 10, 10.11 — `sanctum:prune-expired` (27 Eylül 2026, İsmail'in makinesi, PHP 8.5):**

| # | Mutasyon | Kırılan iddia |
|---|---|---|
| 11 | `config/sanctum.php` → `'expiration' => null` (Faz 9'un hâli) | `$stale` silinmedi |
| 12 | `'expiration' => 60 * 24 * 29` | `$inGraceDay` silindi |
| 13 | `routes/console.php` → `--hours=720` (Faz 9'un hâli) | `$stale` silinmedi |
| 14 | `--hours=0` | `$inGraceDay` silindi |

13. satır `scheduledArtisanCommand()`'ın varlık sebebi: argüman testte elle
yazılsaydı bu mutasyon **hayatta kalırdı**.

**Faz 10, 10.14 — `users:normalize-emails` (28 Eylül 2026, İsmail'in makinesi, PHP 8.5):**

| # | Mutasyon (`NormalizeUserEmails.php`) | Kırılan test |
|---|---|---|
| 15 | *"Hedef başka hesapta"* kontrolünü `if (false)` yap | `it_skips_and_reports_an_address_another_account_already_holds` |
| 16 | *"İki aday aynı hedefe"* kontrolünü `if (false)` yap | `it_skips_two_legacy_rows_that_fold_into_the_same_address` |
| 17 | Koşullu `UPDATE`'ten `where('email', eski)`'yi sil | ⚠️ **Hiçbiri**: okuma-yazma yarışı tek süreçli testte kurulamaz (**T15**) |
| 18 | `--dry-run` dalını `if (false)` yap | `the_normalize_dry_run_writes_nothing_…` |
| 19 | `visible()` ham değeri döndürsün | `the_normalize_dry_run_writes_nothing_…` (çıktıda kaçış yok) |
| 20 | Çakışmada da `SUCCESS` dön | Çakışma testlerinin ikisi |

17. satır bilinen bir boşluk, eşdeğer mutant değil: koşul gerçek bir yarışı
kapatıyor, ama o yarışı kuracak ikinci bir süreç testte yok.

**Faz 10, 10.45 — `data:purge` (1 Ekim 2026, İsmail'in makinesi, PHP 8.5):**

| # | Mutasyon (`PurgeExpiredData.php` · `config` · `routes/console.php`) | Kırılan |
|---|---|---|
| 21 | `onlyTrashed()` → `withTrashed()` | ⚪ **Hiçbiri: eşdeğer mutant.** Sorgu zaten `deleted_at < sınır` diye süzüyor; canlı davetiyenin `deleted_at`'i `NULL` ve hiçbir zaman eşleşmiyor |
| 22 | Davetiye silinirken dosyalar silinmesin | çöp kutusu testi |
| 23 | Galeri de misafir verisi sayılsın | misafir verisi testi |
| 24 | Tarihsiz davetiye de "bitmiş" sayılsın | misafir verisi testi |
| 25 | Misafir dosyası diskte kalsın | misafir verisi testi |
| 26 | 30 → 28 gün | çöp kutusu testi |
| 27 | 6 → 7 ay | misafir verisi testi |
| 28 | 12 → 11 ay | iletişim testi |
| 29 | `--dry-run` yazsın | dry-run testi |
| 30 | Zamanlayıcıdan çıkarıldı | kayıt testi · sıklık testi |

21. satıra rağmen `a_live_invitation_is_never_purged` duruyor: yarın biri süzgeci
yanlış kolona (`created_at`) kurarsa canlı davetiyeler silinirdi ve o test kırılırdı.
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

#### ✅ Faz 10, 10.54b: düzeltildi

Test iki teste bölündü; ikisi de bayrağın **kendisini** soruyor (`Event::$withoutOverlapping`,
`Event::$onOneServer`) ve döngüden önce `assertNotEmpty($events)` diyor. O satır ikinci bir
boş yeşili kapatıyor: zamanlayıcı bir gün okunamaz hâle gelirse (`events()` boş döner)
`foreach` hiç dönmez ve test hiçbir şey sınamadan geçerdi.

| Mutasyon (`routes/console.php`, 1 Ekim 2026) | Kırılan |
|---|---|
| `data:purge`'den `withoutOverlapping()` silindi | `every_scheduled_command_guards_against_overlapping` |
| `orders:expire`'dan `onOneServer()` silindi | `every_scheduled_command_runs_on_one_server` |
| `sanctum:prune-expired`'dan `onOneServer()` silindi | `every_scheduled_command_runs_on_one_server` |

`onOneServer()` bugün tek sunucuda (K80) davranış değiştirmiyor; ikinci bir uygulama
sunucusu eklendiği gün her ikisinin cron'u aynı dakikada `data:purge` başlatırdı. Satır
büyümenin sigortası ve Faz 9'dan beri her işte yazılıydı, ama hiç sınanmıyordu.

---

## 9. Bu dosyanın kapatamadıkları (B6)

| Kapatmaz | Neden / nerede |
|---|---|
| Zamanlayıcının üretimde **gerçekten** koşması | Dakikada bir `schedule:run` çağıran cron'a bağlı; test yalnızca kaydı görür. Plan 10.55: Sentry Cron Monitors |
| Gerçek disk | `Storage::fake()` (§3) |
| Toplu `UPDATE` ile eşzamanlı webhook yarışı | **T15**: tek süreçli testte kurulamaz. `ExpireStaleOrders.md` §9.3 mantığı anlatıyor |
| Geç ödemenin `expired` satırı açması | Bu dosyada değil, `PaywallTest` (10.7) |
| Süresi dolan token'ın **reddedilmesi** | Bu dosya yalnızca satırın silinmesini görür; reddi `AuthTest` §3.6 kanıtlıyor (10.11) |
| `users:normalize-emails`'in okuma-yazma yarışı | **T15** — mutasyon 17 |

---

## 10. Kendin dene

```powershell
php artisan test --filter=MaintenanceTest
# 28 passed
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
