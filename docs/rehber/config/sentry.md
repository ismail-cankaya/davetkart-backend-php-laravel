# `config/sentry.php`

> **Ne zaman:** 21 Eylül 2026 — Faz 9 sonrası, plan dışı ekleme (commit `a72d3f4`)
> **Kılavuz yazımı:** 23 Eylül 2026 gözden geçirmesi (K18 borcu — dosya kılavuzsuz eklenmişti)
> **İlgili:** [`../bootstrap/app.md`](../bootstrap/app.md) §2.6 ·
> [`../app/Exceptions/ApiExceptionRenderer.md`](../app/Exceptions/ApiExceptionRenderer.md) ·
> `docs/10-URETIM-ENV-SABLONU.md` (Sentry bloğu)
> **Kararlar:** **K14** (IP ham saklanmaz) · **H8** (yığın izi yanıta girmez) · **E6** (ortam farkı env'e)

---

## 1. Sentry nedir, neden eklendi?

**Sentry** bir *hata izleme* servisidir. Üretimde bir istisna fırladığında
yığın izini, isteğin rotasını, sürümü ve ortamı Sentry'nin sunucusuna gönderir;
sen de bir panelden *"bu hata kaç kez, hangi kullanıcıda, hangi sürümden beri"*
sorusunu cevaplarsın.

`laravel.log` bunu zaten yapmıyor muydu? Kısmen:

| | `laravel.log` | Sentry |
|---|---|---|
| Nerede durur | Sunucunun diskinde | Dışarıda, sunucu çökse de erişilir |
| Gruplama | Yok — her satır ayrı | Aynı hata tek kayıt + sayaç |
| Bildirim | Yok — biri dosyayı açmalı | E-posta / uygulama bildirimi |
| Arama | `grep` | Sürüm, rota, ortam filtreleri |

Faz 9'un açık kararlarından biri *"zamanlanmış iş koşmazsa kimse bilmez"* idi;
Sentry o sorunun **bir kısmını** çözer: bir iş **patlarsa** artık haber gelir.
Hiç **koşmazsa** yine gelmez (Sentry'nin *Cron Monitors* özelliği ayrı kurulum ister).

---

## 2. Dosya Laravel ile gelmez — paket yayınlar

`composer require sentry/sentry-laravel` paketi kurar, `php artisan
sentry:publish` bu dosyayı `config/` altına kopyalar. İçeriğin neredeyse
tamamı paketin varsayılanıdır; biz yalnızca iki satır ekledik:

```php
declare(strict_types=1);   // pint.json zorunlu tutuyor (ders 13)
```

ve dosyanın başındaki açıklama. **Hiçbir ayar koddan değil `.env`'den gelir** —
`env()` çağrısının serbest olduğu tek yer `config/` dosyalarıdır (`config:cache`
sonrası kod içindeki `env()` `null` döner).

---

## 3. Önemli anahtarlar

| Anahtar | `.env` değişkeni | Bizdeki değer | Neden |
|---|---|---|---|
| `dsn` | `SENTRY_LARAVEL_DSN` | yerelde **boş**, üretimde dolu | 🔴 DSN bir **yazma anahtarıdır**; repoya girerse biri projene sahte olay basıp kotanı doldurabilir. Boşsa SDK **tamamen kapalıdır** |
| `environment` | `SENTRY_ENVIRONMENT` | `production` | Boşsa `APP_ENV` kullanılır; açıkça yazmak staging/prod karışmasını önler |
| `release` | `SENTRY_RELEASE` | boş | Dolarsa (ör. git SHA) *"bu hata hangi deploy'la geldi"* görünür — deploy betiğine eklenmeli |
| `sample_rate` | `SENTRY_SAMPLE_RATE` | `1.0` | Hataların **tamamı** gönderilir |
| `traces_sample_rate` | `SENTRY_TRACES_SAMPLE_RATE` | **boş = kapalı** | Performans izleme. Açılırsa her istek bir *transaction* olur ve ücretsiz kota hızla biter; önce `0.1` gibi düşük oran |
| `send_default_pii` | `SENTRY_SEND_DEFAULT_PII` | **`false`** | 🔴 `true` olsaydı IP, çerez ve kullanıcı bilgisi Sentry'ye giderdi. K14 (KVKK) IP'yi **kendi** veritabanımızda bile ham tutmuyor; üçüncü bir tarafa göndermek bu kararı arkadan dolanmak olurdu |
| `breadcrumbs.sql_bindings` | — | `false` | Sorgu **parametreleri** (e-posta, IBAN, misafir adı) gönderilmez; yalnızca sorgunun şablonu |
| `ignore_transactions` | — | `['/up']` | Sağlık sondası izleme verisini kirletmesin |

---

## 4. Nasıl bağlanıyor? `bootstrap/app.php`

```php
->withExceptions(function (Exceptions $exceptions): void {
    Integration::handles($exceptions);        // (1) report
    $exceptions->render(fn (...) => ...);      // (2) render — K20 zarfı
})
```

Laravel'de bir istisnanın **iki ayrı kaderi** vardır:

1. **report** — kaydet/bildir (log dosyası, Sentry)
2. **render** — istemciye hangi HTTP yanıtının döneceğine karar ver

`Integration::handles()` yalnızca (1)'e bir kanca ekler. (2) — yani
`ApiExceptionRenderer`'ın ürettiği `{error: {code, ...}}` zarfı — hiç değişmez.
Bu yüzden Sentry'yi eklemek API sözleşmesini etkilemedi.

---

## 5. 🔴 Bilinen sorun: iş istisnaları da raporlanıyor

Laravel bazı istisnaları **hiç raporlamaz** (`internalDontReport`):
`ValidationException`, `AuthenticationException`, `ModelNotFoundException`,
tüm `HttpException`'lar (404, 429…). Bunlar Sentry'ye de gitmez.

Ama **bizim** `HasErrorCode` istisnalarımız bu listede değil:

| İstisna | HTTP | Ne kadar sık? |
|---|---|---|
| `InvalidCredentialsException` | 401 | **Her yanlış parola** |
| `PaywallViolationException` | 402 | Her ödemesiz yayın denemesi (frontend bilerek dener — F3 Seçenek A) |
| `RsvpQuotaExceededException` · `MediaQuotaExceededException` | 403 | Kota dolunca her istek |
| `AssistantQuotaExceededException` | 429 | Günlük 30 mesajdan sonra her mesaj |
| `InvitationAlreadyPublishedException` | 409 | Çift tıklama |
| `InvalidWebhookSignatureException` | 404 | Webhook ucunu tarayan her bot |

Sonuç: bunların hepsi hem `laravel.log`'a `ERROR` seviyesinde yığın iziyle
yazılıyor, hem de Sentry'ye bir olay olarak gidiyor. Bu **hata değil, beklenen
davranış**; ama (a) ücretsiz planın aylık kotasını yer, (b) gerçek 500'leri
gürültünün içinde kaybettirir.

**Öneri (karar İsmail'in):** yalnızca sunucu tarafı hataları raporla.

```php
// bootstrap/app.php — withExceptions içinde
$exceptions->dontReportWhen(
    fn (Throwable $e): bool => $e instanceof HasErrorCode
        && $e->errorCode()->status() < 500,
);
```

`PaymentProviderException` (502/503) ve `AiProviderException` (503) raporlanmaya
devam eder — onlar gerçekten **bizim** ya da sağlayıcının sorunudur.

---

## 6. Testlerde Sentry

`phpunit.xml` `SENTRY_LARAVEL_DSN`'i ezmiyor. Yerel `.env`'ine bir gün gerçek
DSN yazarsan, testlerde bilerek fırlatılan istisnalar da Sentry'ye gider.
Güvenli taraf: `phpunit.xml`'e `<env name="SENTRY_LARAVEL_DSN" value=""/>`.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | DSN'i `.env.example`'a yazmak | Repoyu gören herkes projene olay basabilir |
| 2 | `send_default_pii=true` | IP/çerez üçüncü tarafa gider — K14 ihlali |
| 3 | `traces_sample_rate=1.0` | Her istek bir transaction; kota günler içinde biter |
| 4 | `dontReport` düşünmeden Sentry'yi açmak | Her yanlış parola bir olay (§5) |
| 5 | `config:cache` sonrası `.env`'e DSN eklemek | Etkisiz — `config:cache` tekrar çalıştırılmalı |
| 6 | Sentry'yi izleme sanmak | Hiç **koşmayan** zamanlanmış iş hata fırlatmaz; Cron Monitors ayrı kurulur |

---

## 8. Kendin dene

```bash
# 1. Sentry panelinden bir proje aç, DSN'i YALNIZCA yerel .env'ine yaz
php artisan config:clear
php artisan sentry:test          # panelde bir test olayı görünmeli

# 2. DSN'i sil ve tekrar dene — hiçbir şey gönderilmemeli, hata da vermemeli
php artisan config:clear
php artisan sentry:test
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **DSN** | *Data Source Name* — olayların gönderileceği adres + proje anahtarı |
| **Event (olay)** | Sentry'ye giden tek bir hata kaydı |
| **Issue** | Aynı hatanın gruplanmış hâli |
| **Breadcrumb** | Hatadan önceki adımların izi (sorgular, loglar) |
| **Transaction / tracing** | Bir isteğin süresinin parça parça ölçümü (performans izleme) |
| **PII** | *Personally Identifiable Information* — kişiyi tanımlayan veri |
| **report / render** | Laravel'de istisnanın *kaydedilmesi* / *yanıta çevrilmesi* |
