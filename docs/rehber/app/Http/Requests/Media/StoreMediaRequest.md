# `app/Http/Requests/Media/StoreMediaRequest.php`

> **Kod dosyası:** `app/Http/Requests/Media/StoreMediaRequest.php`
> **Faz:** 6 — Medya dilimi, dosya 6.6
> **Tabanı:** [`MediaRequest.md`](MediaRequest.md) — kuralların nasıl
> hesaplandığı orada anlatıldı.

---

## 1. Tek sorumluluğu: hangi türler serbest?

```php
protected function allowedKinds(): array
{
    return [MediaKind::Gallery->value];
}
```

Bu uç davetiye **sahibinin** kullandığı uç (`POST /api/media/upload`,
`auth:sanctum` arkasında). Ve yalnızca `gallery` kabul ediyor.

---

## 2. 🔴 "Sahip her şeyi yükleyebilir" neden demedik?

Cazip alternatif şuydu:

```php
return MediaKind::values();                          // ❌ hepsi
// ya da
return array_diff(MediaKind::values(), MediaKind::guestUploadableValues());
```

İkisi de yazılmadı. Gerekçe **en az ayrıcalık** (least privilege):

> Bugün sahibin arayüzünde LCV medyası yükleyeceği bir yer **yok**.
> `GalleryUploader.tsx` galeri yüklüyor, o kadar.

Kullanılmayan bir yetkiyi açmak, bir gün birinin onu kullanmasını mümkün
kılar — ve o gün kimse "bunu sahip mi yüklüyor olmalı?" diye sormaz, çünkü
soru çoktan cevaplanmış görünür.

Yeni bir sahip-türü geldiği gün bu listeye eklenecek — **ve o an karar
bilinçli olarak verilmiş olacak.** Listeyi türetseydik o an hiç yaşanmazdı.

Bu, `MediaKind::isGuestUploadable()` içindeki `match`'te `default` kolu
yazmama kararıyla aynı fikir: **kararı sessizce vermek yerine, karar anını
zorunlu kıl.**

---

## 3. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `MediaKind::values()` döndürmek | Sahip, hiç kullanmadığı türleri yükleyebilir hâle gelir |
| 2 | Yetki kontrolünü buraya yazmak | **M4** ailesi: kaynağa bağlı yetki, model yüklendikten sonra (controller/Policy) |
| 3 | Türü sabit varsayıp `kind` alanını kaldırmak | Uç, ileride ikinci bir türe açılamaz; ayrıca istemci ne gönderdiğini bilmez |

---

## 4. Sırada ne var?

Kardeş sınıf: [`StorePublicMediaRequest.md`](StorePublicMediaRequest.md)
