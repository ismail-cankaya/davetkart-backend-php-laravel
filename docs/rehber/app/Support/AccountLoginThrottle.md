# `app/Support/AccountLoginThrottle.php`

> Faz 21.1 · `SEC-16` · K265. Hesap başına, IP'den bağımsız başarısız giriş kovası.

## 1. Hangi açığı kapatıyor?

Giriş ucunda iki kova vardı ([`AppServiceProvider.md`](../Providers/AppServiceProvider.md) §5.5): e-posta + IP başına
dakikada 5, IP başına dakikada 20. İkisinin de anahtarında **IP** var. Bir hesaba çok sayıda IP'den (botnet, konut
vekilleri) saldıran biri hiçbir kovayı doldurmaz: her IP kendi 5/dk hakkını kullanır. 1000 IP = dakikada 5000 deneme.

Bu sınıf üçüncü kovayı ekler: **yalnızca hesabın adresi** (`login-fail|<HMAC(e-posta)>`). Hangi IP'den gelirse gelsin, bir
hesaba saatte en çok **30 başarısız** deneme yapılabilir. Saldırganın maliyeti artık IP sayısına değil hedefe bağlı.

| Kova | Anahtar | Sınır | Nerede |
|---|---|---|---|
| 1 | e-posta + IP | 5/dk, her istek | `throttle:auth` (rota ara katmanı) |
| 2 | IP | 20/dk, her istek | `throttle:auth` |
| 3 (yeni) | e-posta | 30/saat, **yalnızca başarısız** | bu sınıf, `LoginUserAction` içinden |

## 2. Neden rota ara katmanında değil?

`throttle:auth` her isteği sayar (`Limit::by()`); başarılı girişi de. Üçüncü kova ise yalnızca **başarısız** denemeyi
saymalı (§A-4): doğru parolayla giren sahip kendi kovasını doldurmamalı. Başarı ya da başarısızlık ancak parola
denendikten sonra bilinir, yani sayaç Action'ın içinde elle artırılır (`RateLimiter::hit`).

Sıra:

1. `ensureOpen()`: kova doluysa `AccountLoginThrottledException` → 429 `RATE_LIMITED` + `retryAfter`. Parola **hiç
   denenmez**.
2. Parola denenir (sahte hash dahil, [`LoginUserAction.md`](../Actions/Auth/LoginUserAction.md) §2).
3. Başarısızsa `recordFailure()`. Kovayı bu deneme doldurduysa `true` döner; Action sahibine uyarı postası atar.

## 3. 🔴 Dolu kovada doğru parola da bekler

Bu bilinçli. Doğru parola dolu kovada geçseydi saldırgan yanlış tahminde 429, doğru tahminde 200 görürdü: kova hiçbir
şeyi frenlemezdi, yalnızca hata kodunu değiştirirdi.

Bedeli: saldırgan saatte 30 başarısız denemeyle sahibi **o saat için** girişten alıkoyabilir. Karar tablosu (§A-2)
"hesap kilitlenmez" diyor; bunun karşılığı:

- **Kilit yok, pencere var.** Kalıcı bir "kilitli" durumu yok, yönetici açmaz; pencere en geç bir saatte kendiliğinden
  kapanır (`DECAY_SECONDS`).
- **Sahibin her zaman bir yolu var.** Parola sıfırlama (`ResetPasswordAction`) başarılı olunca `clear()` kovayı boşaltır.
  Uyarı postası da tam bunu söyler.
- **Açık oturumlar etkilenmez.** Kova yalnızca `/auth/login`'de; token'la gelen istekler ona hiç bakmaz.

## 4. Kayıtsız adres de sayılır

`recordFailure()` kullanıcı var mı diye bakmaz. Yalnızca kayıtlı hesapların kovası dolsaydı 31. denemedeki 429 "bu adres
kayıtlı" derdi: giriş ucunun özenle kapatılan sayım (enumeration) açığı (08 §3.1, A3) arka kapıdan geri gelirdi. Uyarı
postası ise yalnızca var olan hesaba gider; bu fark yanıta yansımaz (§6).

## 5. Anahtar neden HMAC?

E-posta kişisel veri (KVKK). Önbellek (yerelde `array`, canlıda `file`) anahtarı diske yazar. `IpHasher` deseni: APP_KEY ile
`hash_hmac('sha256', …)`. Düz `sha256` olsaydı, elinde adres listesi olan biri önbellek dosya adlarından kimin
denendiğini çıkarabilirdi.

Adres anahtara girmeden önce `EmailNormalizer::normalize()`'dan geçer. Giriş isteği zaten normalize edilmiş gelir, ama
sınıf bunu çağırana bırakmaz: `İsmail@` ile `ismail@` iki ayrı kova olsaydı saldırgan iki kat hak alırdı (Faz 10.13 dersi).

## 6. Uyarı kovası (`claimWarning`)

Kova bir pencerede tek kez dolar (30. başarısızlık). Ama sabit pencereler art arda gelebilir: biri 10:59'da dolar, yenisi
11:01'de. "Saatte en çok bir posta" (§A-3) bu yüzden ayrı bir kovayla sağlanır (`login-warn|…`, 1/saat). Parola
sıfırlanıp kova aynı saat içinde yeniden dolarsa da ikinci posta gitmez.

Neyi kapatmaz (B6): posta kuyruğa yazılırken (canlıda `database` kuyruğu) kayıtlı hesabın 30. denemesi birkaç milisaniye
uzun sürebilir. Bu fark saatte en çok bir kez, yalnızca 30. denemede olur ve parola karşılaştırmasının ~200 ms'lik
gürültüsü içinde kalır.

## 7. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Kovayı `authLimits()`'e `Limit::perHour(30)->by($email)` diye eklemek | Başarılı girişler de sayılır, sahip kendi kovasını doldurur |
| `ensureOpen()`'ı parola kontrolünden sonra çağırmak | Doğru tahmin 200 alır; kova işe yaramaz |
| Yalnızca `$user !== null` iken saymak | 429 adresin kayıtlı olduğunu söyler |
| Kovayı aşınca hesabı kalıcı kilitlemek | Saldırgan sahibi süresiz dışarıda tutar |
| Anahtara ham e-posta yazmak | Kişisel veri önbellek diskinde |

## 8. Deneme

```powershell
php artisan test tests/Feature/AccountLoginThrottleTest.php
```

Testler her denemeyi ayrı bir IP'den yapar (`REMOTE_ADDR`): IP kovaları hiç dolmaz, 429 yalnızca bu kovadan gelebilir.
Kılavuz: [`../../tests/Feature/AccountLoginThrottleTest.md`](../../tests/Feature/AccountLoginThrottleTest.md).

## Terimler

| Terim | Anlam |
|---|---|
| Kova (bucket) | Bir anahtar için sayılan deneme sayacı ve penceresi |
| Sabit pencere | İlk denemeyle başlayan, süresi dolunca sıfırlanan sayım aralığı |
| Credential stuffing | Sızmış parola listelerini çok sayıda IP'den deneme |
| HMAC | Gizli anahtarla üretilen özet; anahtarı bilmeyen tersine çeviremez ya da önceden hesaplayamaz |
