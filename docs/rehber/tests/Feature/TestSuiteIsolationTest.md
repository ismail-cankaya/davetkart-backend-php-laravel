# `tests/Feature/TestSuiteIsolationTest.php`

> **Faz:** 10 — Sertleştirme, adım 10.17 · **3 test**
> **Test edilen:** [`phpunit.md`](../../phpunit.md) §4.1 (Sentry DSN) ·
> [`tests/TestCase.md`](../TestCase.md) §8 (`Http::preventStrayRequests()`)
> **Kurallar:** **T6** · **T16**

---

## 1. Bu dosya neyi sınıyor?

Bir özelliği değil, **test altyapısının iki garantisini**:

| Garanti | Nerede kuruldu | Olmasaydı |
|---|---|---|
| Hiçbir test Sentry'ye olay göndermez | `phpunit.xml` → `<server name="SENTRY_LARAVEL_DSN" value=""/>` | Bilerek fırlatılan her test istisnası (yüzlerce) gerçek projede bir olay |
| Hiçbir test sahtesiz HTTP çağrısı yapamaz | `TestCase::setUp()` → `Http::preventStrayRequests()` | `Http::fake()`'i unutan test gerçek API'ye, gerçek anahtarla gider |

İkisi de bugün kendiliğinden doğru: geliştiricinin `.env`'inde DSN yok,
`Http::fake()`'i unutan test yok. Bu dosya **yarını** korur.

---

## 2. Testler

| Test | T6 | Ne kanıtlar |
|---|---|---|
| `the_test_suite_has_no_sentry_dsn` | Yokluk | `config('sentry.dsn')` boş **ve** Sentry istemcisinin DSN'i `null` |
| `an_http_call_without_a_fake_fails_instead_of_reaching_the_network` | Yokluk | Sahtesiz çağrı → `StrayRequestException` |
| `a_faked_http_call_still_works` | 🔴 Varlık | Koruma sahte kurulu çağrıyı **engellemez** |

### 2.1 🔴 "İstemci yok" değil, "istemcinin DSN'i yok"

İlk yazımda test şunu iddia ediyordu:

```php
$this->assertNull(SentrySdk::getCurrentHub()->getClient());   // ❌ yanlış varsayım
```

Kırmızı yandı. DSN boşken de bir `Sentry\Client` kuruluyor. Kaynak (`vendor/sentry/sentry/src/Transport/HttpTransport.php`) gerçek güvenceyi gösterdi:

```php
if ($this->options->getDsn() === null) {
    $this->logger->info(sprintf('Skipping %s, because no DSN is set.', ...));
    return new Result(ResultStatus::skipped(), $event);
}
```

İddia bu yüzden **istemcinin DSN'ine** bakıyor. İki iddia iki kaynağı sınıyor:

| İddia | Neyi yakalar |
|---|---|
| `Config::get('sentry.dsn')` boş | Bizim anahtarımız (`SENTRY_LARAVEL_DSN`) sızdı |
| İstemcinin DSN'i `null` | SDK DSN'i **başka yoldan** buldu (örn. kendi varsayılanı `$_SERVER['SENTRY_DSN']`) |

### 2.2 Varlık testi neden şart?

`preventStrayRequests()` yanlışlıkla **her** çağrıyı engelleseydi (ya da ileride
biri onu `Http::fake()`'ten önce değil sonra çağıracak şekilde değiştirse) yalnızca
yokluk testi yeşil kalırdı. `AssistantTest`'in `Http::fake()` kuran testleri birden kırılırdı ve
en kolay "çözüm" korumayı kaldırmak olurdu. `a_faked_http_call_still_works` bu
ikisini ayırıyor.

### 2.3 `example.invalid` neden?

`.invalid` üst düzey alan adı (RFC 2606) **hiçbir zaman** çözümlenmez. Mutasyon
denemesinde koruma kaldırıldığında bile istek gerçek bir sunucuya ulaşamaz,
DNS'te düşer. Test bozulduğunda bile dış dünyaya dokunmuyor.

---

## 3. Mutasyon kanıtı (28 Eylül 2026, İsmail'in makinesi, PHP 8.5)

| # | Mutasyon | Kırılan test |
|---|---|---|
| M1 | `TestCase::setUp()`'tan `Http::preventStrayRequests();` silindi | `an_http_call_without_a_fake_…` (`StrayRequestException` yerine bağlantı hatası) |
| M2 | `phpunit.xml`'den `<server>` satırı silindi **ve** kabukta `SENTRY_LARAVEL_DSN` tanımlandı | `the_test_suite_has_no_sentry_dsn` |

⚠️ M2 yalnızca ortamda bir DSN varken kırılır. Geliştiricinin `.env`'inde DSN
yoksa satır silinse de test yeşil kalır, çünkü koruyacak bir şey yok. Bu bir
boşluk değil: test *"şu an DSN boş mu?"* sorusunu soruyor, ve cevap ortama göre
değişiyor. Satırın değeri CI'da ya da DSN'li bir makinede ortaya çıkar.

### 3.1 Deney: `<env force>` neden yetmedi?

Sentry satırı önce `<env name="SENTRY_LARAVEL_DSN" value="" force="true"/>` olarak
yazıldı. Kabukta DSN tanımlıyken koşturulan deney:

| `phpunit.xml` | Kabuk | Sonuç |
|---|---|---|
| `<env force>` | — | ✅ |
| `<env force>` | `SENTRY_LARAVEL_DSN` | ❌ DSN dolu |
| `<env>` (force yok) | `SENTRY_LARAVEL_DSN` | ❌ |
| `<server>` | `SENTRY_LARAVEL_DSN` | ✅ |
| `<server>` | `SENTRY_DSN` | ✅ (yedeğe düşmüyor) |

Sebep: Laravel env'i önce `$_SERVER`'dan okur, `<env>` oraya yazmaz. Ayrıntı:
[`phpunit.md`](../../phpunit.md) §4.1.

---

## 4. Çalıştırma

```powershell
php artisan test --filter=TestSuiteIsolationTest
# 3 passed

# CI'ı taklit et: kabukta bir DSN varken de yeşil kalmalı
$env:SENTRY_LARAVEL_DSN = 'https://abc@o1.ingest.example.invalid/1'
php artisan test --filter=TestSuiteIsolationTest
Remove-Item Env:SENTRY_LARAVEL_DSN
```
