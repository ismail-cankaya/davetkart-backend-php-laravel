# `app/Http/Requests/Media/StorePublicMediaRequest.php`

> **Kod dosyası:** `app/Http/Requests/Media/StorePublicMediaRequest.php`
> **Faz:** 6 — Medya dilimi, dosya 6.6
> **Tabanı:** [`MediaRequest.md`](MediaRequest.md)

---

## 1. Sistemin ikinci auth'suz yazma yolu

Faz 5'te LCV gönderimi sistemin **tek** auth'suz yazma yoluydu. Faz 6 ikincisini
açıyor — ve bu sefer yazılan şey bir satır değil, **diskte bir dosya**.

```php
protected function allowedKinds(): array
{
    return MediaKind::guestUploadableValues();     // ['rsvp_photo', 'rsvp_video']
}
```

---

## 2. 🔴 Liste neden türetiliyor?

`StoreMediaRequest` listeyi **elle** yazıyordu (`['gallery']`), burada
**türetiliyor**. Çelişki değil — iki farklı soru:

| | Soru | Cevap nerede |
|---|---|---|
| `StoreMediaRequest` | "Sahibin arayüzü bugün neyi yüklüyor?" | Ürün kararı → elle |
| `StorePublicMediaRequest` | "Bu tür **misafire açık mı**?" | 🔴 Güvenlik kuralı → `MediaKind` |

İkincisi bir **güvenlik sınırı**, ve o sınırın tek tanımı
`MediaKind::isGuestUploadable()`. Burada elle liste yazsaydık:

```php
return ['rsvp_photo', 'rsvp_video'];        // ❌
```

...ve yarın biri enum'da `Gallery`'yi misafire açsa (ya da yeni bir tür ekleyip
misafire kapatmayı unutsa), iki liste **ayrışırdı**. Üstelik testler de
muhtemelen aynı elle yazılmış listeye bakardı — yani **hiçbir test bunu
söylemezdi**. **C3**'ün tam tanımı.

---

## 3. Bu uçta hangi savunmalar var?

Faz 5'in **L1** kuralı (*katmanlar en ucuzdan pahalıya*) burada da geçerli:

```
1. Rota: throttle:media-public   → istek Action'a hiç gelmez
2. Bu sınıf: kind 'in:' + boyut + mimetypes  → 422
3. Action: davetiye yayında mı, LCV modülü açık mı → 404
4. Action: kota (kilitli transaction) → 403
5. Action: rastgele ad + içerikten MIME → diske yazım
```

Bu sınıf **2. katman**. Misafirin `kind=gallery` göndermesi buradan öteye
geçmez; Policy'ye, Action'a, diske hiç ulaşmaz.

> ⚠️ Faz 5'in honeypot'u burada **yok**. Sebep: bu uç bir formdan değil, dosya
> seçici diyaloğundan tetikleniyor; görünmez bir alan doldurmayı bekleyeceğimiz
> bir bot senaryosu yok. Savunma hız sınırı + kota üzerine kurulu (L3).

---

## 4. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Listeyi elle yazmak | Enum ile ayrışır; misafir galeriye yükleyebilir ve hiçbir test söylemez |
| 2 | Bu ucu `/api/public/` dışına koymak | **K12** fail-safe kırılır |
| 3 | Hız sınırı koymamak | Kimliği bilinmeyen biri diski doldurabilir (L3) |
| 4 | Kota kontrolünü buraya yazmak | 422 döner; oysa kapasite reddi 403 |

---

## 5. Sırada ne var?

**6.7 — `StoreUploadedMediaAction`.**
Kardeş sınıf: [`StoreMediaRequest.md`](StoreMediaRequest.md)
