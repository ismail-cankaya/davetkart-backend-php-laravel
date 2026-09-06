# `app/Exceptions/AiProviderException.php`

> **Faz:** 8, dosya 8.2 · **Kod:** `PROVIDER_UNAVAILABLE` (503)
> **Kurallar:** H8 · K27 · ders 55

---

## 1. Neden 502/503 ayrimi YOK?

`PaymentProviderException` iki adlandirilmis kurucu tasiyor ve iki farkli
kod donduruyor (K27):

| | Kod | Anlam |
|---|---|---|
| `rejected()` | `PAYMENT_PROVIDER_ERROR` (502) | Yukari akis **cevap verdi** ama hatali |
| `unavailable()` | `PROVIDER_UNAVAILABLE` (503) | Erisilemiyor / yapilandirilmamis |

Ayrimin gerekcesi **izleme alarmiydi**: odeme akisinda biz bir *gateway*'iz
ve "sorun onlarda" ile "sorun bizde" farkli ekipleri uyandirir.

Asistanda ayni ayrimi **yapmadik**. Ayirt edici soru su: *bu ayrim, birinin
FARKLI bir sey yapmasina yol aciyor mu?*

- Kullanicinin onundeki eylem her iki halde de ayni: "birazdan tekrar dene".
- Frontend her iki kodu da ayni balonla gosterirdi.
- Log zaten ayrimi tasiyor (`error.message`, `finishReason`, `blockReason`).

Bugun eklenirse hicbir yerden okunmayan bir **olu sozlesme maddesi** olur —
ders 26'nin sozlesme eksenindeki hali. Frontend'in ayirt edip farkli bir sey
yapacagi bir durum dogdugunda ikinci kod eklenir.

---

## 2. Iki kurucu, tek kod

| Kurucu | Ne zaman | Log'da ne der |
|---|---|---|
| `unavailable(string $driver)` | Bilinmeyen `ai.default`, eksik API anahtari | `AI provider 'x' is not available.` |
| `unreachable(string $driver, ?Throwable)` | Baglanti hatasi, zaman asimi, 4xx/5xx, bos cevap | `... did not return a usable reply.` |

Ayrim **disariya** degil, hata ayiklayan kisiye hizmet eder.

---

## 3. 🔴 Ders 55 burada yapisal olarak kapali

Faz 7'nin ilk `composer check` kosusu alti hata vermisti: iki exception'da
`private readonly ErrorCode $code` yazilmis ve `Exception::$code`
golgelenmisti (LSP ihlali).

Bu sinifta o tuzaga dusecek bir yuzey **hic olusmadi**: tek bir kod
donduruldugu icin bir **ozellige ihtiyac yok**.

```php
public function errorCode(): ErrorCode
{
    return ErrorCode::ProviderUnavailable;   // alan yok, golgeleme yok
}
```

Kural, hatirlanmasi gereken bir adim olmaktan cikip yapinin bir sonucu
oldu. Yeni exception yazarken hala `$code`, `$message`, `$file`, `$line`
**yasakli adlardir**.

---

## 4. `retryAfter` neden sinif sabiti?

```php
private const RETRY_AFTER_SECONDS = 30;
```

Bu bir **is ayari** degil bir **HTTP nezaket degeri**. `config`'e konsaydi
"ayarlanmasi gereken bir sey" gibi gorunur ve kimse dokunmadigi icin olu bir
anahtar olurdu. `PaymentProviderException` 60 sn oneriyor; AI'in gecici
arizasi (zaman asimi, anlik 429) tipik olarak daha kisa surer.

H12 hala isliyor: deger `ErrorCode::filterParams()` beyaz listesinden gecer
ve `PROVIDER_UNAVAILABLE` `retryAfter`'a izin verdigi icin disari cikar.

---

## 5. Sik yapilan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Saglayicinin mesajini `getMessage()` ile yanita koymak | H8 ihlali |
| 2 | `previous` gecirmemek | Hata ayiklama zinciri kopar, log ise yaramaz |
| 3 | Yeni bir `AI_PROVIDER_ERROR` kodu eklemek | Frontend'in ayirt etmedigi olu sozlesme maddesi |
| 4 | `$code` adinda ozellik tanimlamak | LSP ihlali; PHPStan yakalar (ders 55) |

---

## 6. Sirada ne var?

[`AssistantQuotaExceededException.md`](AssistantQuotaExceededException.md) —
neden 403 degil **429**.
