# `app/Http/Resources/PublicGalleryImageResource.php`

> **Kod dosyası:** `app/Http/Resources/PublicGalleryImageResource.php`
> **Faz:** 6 — açık kalan maddenin kapanışı (galeri sırası)
> **Önce oku:** [`MediaResource.md`](MediaResource.md) — sahibin sürümü,
> [`PublicTimelineEventResource.md`](PublicTimelineEventResource.md) — aynı kararın ilk hâli

---

## 1. İki okuyucu, iki şekil (C4)

| Okuyucu | Resource | Alanlar | Neden |
|---|---|---|---|
| **Sahip** | `MediaResource` | `id`, `url` | Silme ucu dosyayı **kimliğiyle** ister |
| **Misafir** | `PublicGalleryImageResource` | `url` | Galeriyi düzenlemez; kimlik ona iş görmez |

`PublicTimelineEventResource` ile birebir aynı karar: sahibin sürümünden **tek**
fark `id` alanı (**C5** — gövdeye giden alanlar da beyaz listedir).

---

## 2. Neden düz metin değil nesne?

```json
"galleryImages": [{ "url": "https://…/media/gallery/aB3x….jpg" }]
```

`["https://…"]` daha kısa olurdu. Ama yarın bir `alt` metni ya da boyut bilgisi
eklendiği gün düz dizi **kırılır**; nesne ise genişler. Sahibin sürümü zaten
nesne (`{id, url}`) — iki şekil yapıca aynı kalır.

---

## 3. Sıra nereden geliyor?

Bu sınıf sıralamaz. `PublicInvitationResource` ona
`$invitation->orderedGalleryMedia()` koleksiyonunu verir; sıra
`gallery_media_ids` dizisindendir. `created_at`'e göre sıralayan bir mutasyonu
`PublicInvitationTest::gallery_images_follow_the_stored_order_without_ids` yakalar:
test diziyi kayıtların oluşma sırasının **tersiyle** kurar.

---

## 4. Cache

`PublicInvitationResource` sonucu `->resolve($request)` ile düz diziye çevirip
cache'e yazar (4.3). Galeri değişince davetiye satırı güncellenir, `updated`
olayı `ClearInvitationCache`'i tetikler ve yeni fotoğraf beklemeden görünür
(`MediaTest::gallery_changes_refresh_the_public_invitation`).
