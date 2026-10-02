# `tests/Feature/ExceptionReportingTest.php`

> **Faz:** 10 — Sertleştirme, adım 10.16 · **4 test**
> **Test edilen:** [`bootstrap/app.md`](../../bootstrap/app.md) §2.7 (`dontReportWhen`)
> **Kurallar:** **T6** (varlık ve yokluk birlikte) · **T16** (mutasyon)

---

## 1. Neden ayrı bir dosya?

Raporlama politikası ne bir uç ne bir Action: **uygulamanın hata işleyicisinin**
bir kuralı. `HardeningTest` (tarayıcı sertleştirmesi: CORS, güvenlik başlıkları)
yakın bir konu ama aynı değil. Oraya eklemek ayrıca o dosyanın henüz yazılmamış
kılavuzunu (plan 10.54) bu adıma çekerdi. Küçük, tek konulu bir dosya daha
okunur.

---

## 2. `Exceptions::fake()` kuralı gerçekten sınıyor mu?

Evet, ve bu dosyanın var olabilmesi buna bağlı. Sahte işleyici, raporlamadan
önce **gerçek** işleyiciye sorar:

```php
// vendor/laravel/framework/src/Illuminate/Support/Testing/Fakes/ExceptionHandlerFake.php
public function shouldReport($e)
{
    return $this->runningWithoutExceptionHandling() || $this->handler->shouldReport($e);
}
```

`$this->handler` `bootstrap/app.php`'nin kurduğu işleyici, `dontReportWhen`
kuralı da onun içinde. Fake kuralı atlasaydı bu testler hiçbir şey kanıtlamazdı.

---

## 3. Testler

| Test | T6 yarısı | Ne kanıtlar |
|---|---|---|
| `a_client_side_business_exception_is_not_reported` | Yokluk | 401 ve 402 iş istisnası **raporlanmaz** |
| `a_provider_failure_is_still_reported` | 🔴 Varlık | 502 (`PaymentProviderException::rejected`) ve 503 (`AiProviderException`) **raporlanır** |
| `an_exception_without_an_error_code_is_still_reported` | 🔴 Varlık | `HasErrorCode` olmayan bir istisna (gerçek bir hata) **raporlanır** |
| `a_wrong_password_answers_401_and_reports_nothing` | Uçtan uca | Gerçek istek, gerçek 401, rapor yok |

### Neden iki varlık testi?

Yalnızca *"4xx raporlanmaz"* yazsaydık, **bütün** raporlamayı kapatan bir kural
(`fn () => true`) da testi geçerdi. Gerçek 500'ler sessizce kaybolurdu, yani
kuralın varlık sebebinin tam tersi olurdu. İki varlık testi iki ayrı yanlışı
yakalar:

- 5xx **iş** istisnası susturulursa → `a_provider_failure_…`
- `HasErrorCode` **olmayan** istisna susturulursa → `an_exception_without_…`

### Neden `report()` doğrudan çağrılıyor?

İlk üç test istisnayı bir uçtan fırlatmak yerine `report($e)` ile doğrudan
işleyiciye veriyor. Gerçek istekte de aynı yol izleniyor: yakalanan istisna
`$handler->report($e)`'ye gidiyor. Böylece her istisna için onu fırlatan bir uç
kurmaya gerek kalmıyor (502 için sahte bir ödeme sağlayıcısı, 503 için yapay
zekâ sürücüsü gerekirdi). Yolun istekten geçtiğini dördüncü test kanıtlıyor.

### 502 neden seçildi?

Elimizdeki **en küçük** 5xx kodu. `< 500` sınırı `< 503`'e kayarsa 502 susar ve
test kırılır. 503 seçilseydi o kayma fark edilmezdi.

---

## 4. Mutasyon kanıtı (28 Eylül 2026, İsmail'in makinesi, PHP 8.5)

| # | Mutasyon (`bootstrap/app.php`) | Kırılan test |
|---|---|---|
| M1 | Kural hep `false` (Faz 9'un hâli: her şey raporlanır) | `a_client_side_…` · `a_wrong_password_…` |
| M2 | Kural hep `true` (her şey susar) | `a_provider_failure_…` · `an_exception_without_…` |
| M3 | `HasErrorCode` şartı tersine: kodu olmayan her istisna da susar | `an_exception_without_…` |
| M4 | Sınır `< 503` | `a_provider_failure_…` |
| M5 | Sınır `<= 500` | ⚪ **Hiçbiri: eşdeğer mutant** |

M5 eşdeğer: bugün durumu **tam 500** olan bir `HasErrorCode` istisnası yok.
`ErrorCode::ServerError` (500) yakalanmamış istisnaların genel kodu; hiçbir iş
istisnası onu taşımıyor. Yarın biri 500 kodlu bir iş istisnası eklerse bu
mutant canlanır. O gün sınırın `<` mı `<=` mü olacağı bilinçli bir karar
olmalı (önerim `<`: 500 her zaman konuşmalı).

---

## 5. Çalıştırma

```powershell
php artisan test --filter=ExceptionReportingTest
# 4 passed
```

Kasten kır: `bootstrap/app.php`'deki `dontReportWhen` kuralını
`fn (Throwable $e): bool => true,` yap. İki **varlık** testi kırılmalı. Kırılmıyorsa
test kuralı değil, fake'i sınıyordur.

---

## 🆕 Faz 10 (10.55b) — `sentry` log kanalının eşiği

| Test | İddia |
|---|---|
| `the_sentry_log_channel_takes_critical_but_not_error` | Kanalın işleyicisi `SentryHandler`; `critical` kaydını alır, `error` kaydını almaz |

İki yönlü (T6): yalnızca *"critical alınır"* deseydik seviyesiz bir kanal (paketin
varsayılanı) da yeşil kalırdı ve her istisna Sentry'ye iki kez giderdi. Yalnızca *"error
alınmaz"* deseydik `emergency`'ye çekilmiş bir kanal da yeşil kalırdı ve *"para alındı,
hak açılamadı"* uyarısı hiç gelmezdi.

Test işleyicinin **eşiğini** soruyor (`isHandling()`), Sentry'ye gerçekten bir şey
gönderildiğini değil: testte DSN boş ve ağa çıkılmıyor. Eşik, bu kararın ta kendisi.

| Mutasyon (`config/logging.php`) | Kırılan |
|---|---|
| `level` satırı silindi (paket varsayılanı `debug`) | bu test |
| `level` → `error` | bu test |
| `level` → `emergency` | bu test |
