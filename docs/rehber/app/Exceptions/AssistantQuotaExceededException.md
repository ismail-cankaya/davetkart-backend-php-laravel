# `app/Exceptions/AssistantQuotaExceededException.php`

> **Faz:** 8, dosya 8.2 · **Kod:** `ASSISTANT_QUOTA_EXCEEDED` (429)
> **Kurallar:** K28 · H9 · ders 51

---

## 1. 🔴 Neden 403 degil 429?

K28, LCV kotasini **403** yapmisti ve gerekcesi suydu:

> *429 bir **hiz** sinirdir; kotamiz **kapasite** siniridir. Misafir
> yavaslayarak asamaz.*

Ayni testi asistan kotasina uygulayalim:

| Soru | LCV kotasi | Asistan kotasi |
|---|---|---|
| Kullanici **bekleyerek** asabilir mi? | ❌ Hayir — plan ne aldiysa o | ✅ **Evet** — gece yarisi yenilenir |
| `Retry-After` anlamli mi? | Hayir, yaniltici olurdu | **Evet**, tam olarak dogru bilgi |
| Dogru kod | **403** | **429** |

Kural degismedi, **cagiran degisti**. Ders 51'in ayni kalibi: idempotans
webhook'ta isteniyor, yayinda istenmiyordu.

30 mesaj/gun aslinda uzun pencereli bir hiz siniridir (30 / 86400 sn) ve
429'un tanimi tam olarak budur: *"belirli bir zaman diliminde cok fazla
istek"*.

---

## 2. Neden `RATE_LIMITED` yeniden kullanilmiyor?

Ikisi de 429 doner, ama frontend'de **ayni metni gosteremezler**:

| Kod | Kullaniciya ne der | Ne yapmali |
|---|---|---|
| `RATE_LIMITED` | "Cok hizlisin" | 30 saniye bekle |
| `ASSISTANT_QUOTA_EXCEEDED` | "Bugunluk hakkin bitti" | Yarin gel (veya plan yukselt) |

Durum kodu **kaba siniflandirma**, `code` **ince ayrim** — `docs/08` §4'un
tam olarak anlattigi is bolumu.

---

## 3. Neden `RsvpQuotaExceededException`'dan farkli olarak parametre tasiyor?

`RsvpQuotaExceededException` **parametresizdir** ve `errorParams()` bos
doner. Sebep H9'du: kotayi asan taraf **anonim bir misafirdi** ve kota
durumu davetiye **sahibinin** bilgisiydi — misafirin "kac kisi kaldi"yi
ogrenmesi bir sizintiydi.

Burada asan taraf **hesabin sahibi**. Kendi gunluk hakkini ogrenmesi
sizinti degil, dogru davranistir — `FILE_TOO_LARGE`'in `max` parametresiyle
ayni sinifta bir urun sabiti.

`remaining` **yok**: kota doldugunda kalan zaten sifirdir; ikinci bir alan
bilgi tasimaz (H9: beyaz liste "her ihtimale karsi" degildir).

---

## 4. `retryAfter` nasil hesaplaniyor?

`AskAssistantAction::secondsUntilReset()` — uygulamanin saat diliminde
(UTC) **ertesi gunun 00:00'ina** kalan saniye.

```php
$now->addDay()->startOfDay()->getTimestamp() - $now->getTimestamp()
```

Carbon'un `diffInSeconds` metodu surume ve `absolute` varsayilanina gore
**float** dondurebilir; iki zaman damgasinin cikarilmasi belirsizlik
birakmaz (ders 18: aracin davranisini tahmin etme).

**B6 — bunun kapatMADIGI sey:** Istanbul'daki bir kullanici icin kota gece
yarisi degil **sabah 03:00'te** yenilenir. Bkz.
[`AskAssistantAction.md`](../Actions/Assistant/AskAssistantAction.md) §5.

---

## 5. Sik yapilan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | 403 dondurmek | Kullanici "ne zaman gecer?" sorusunun cevabini alamaz |
| 2 | `RATE_LIMITED` kullanmak | Iki farkli durum ayni metni gosterir |
| 3 | `remaining` eklemek | Her zaman 0 olan bir alan; gurultu |
| 4 | `$code` adinda ozellik | LSP ihlali (ders 55) |
