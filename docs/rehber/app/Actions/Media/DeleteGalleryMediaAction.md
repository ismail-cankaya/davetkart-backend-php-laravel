# `app/Actions/Media/DeleteGalleryMediaAction.php`

> **Kod dosyası:** `app/Actions/Media/DeleteGalleryMediaAction.php`
> **Faz:** 6 — açık kalan maddenin kapanışı (galeri sırası)
> **Önce oku:** [`StoreUploadedMediaAction.md`](StoreUploadedMediaAction.md) §7–8 (kilit ve dosya sistemi)
> **Uç:** `DELETE /api/invitations/{invitation}/media/{media}` → `MediaController::destroy`

---

## 1. Ne yapar?

Sahibin galerisinden bir fotoğrafı kaldırır. Üç etki, bir sıra:

1. `gallery_media_ids` dizisinden kimliği çıkarır
2. `media` satırını siler
3. Diskteki dosyayı siler

Satırın silinmesi kotayı da boşaltır: 8 fotoğraf sınırındaki kullanıcı birini
silip yenisini yükleyebilir (`MediaTest::deleting_a_gallery_image_frees_its_quota_slot`).

---

## 2. 🔴 Görünürlük bir `if` değil, sorgunun kapsamı (P3)

```php
$media = $locked->galleryMedia()->whereKey($mediaId)->firstOrFail();
```

Dosya **davetiyenin galeri ilişkisi üzerinden** aranır. Bu yüzden:

| İstek | Sorgudan çıkar mı? | Yanıt |
|---|---|---|
| Bu davetiyenin galeri fotoğrafı | ✅ | 204 |
| Başka davetiyenin fotoğrafı (aynı sahip bile) | ❌ | 404 |
| Bu davetiyedeki bir **LCV fotoğrafı** | ❌ | 404 |
| Olmayan kimlik | ❌ | 404 |

Son üçü **aynı** yanıtı alır (**H7**): "var ama senin değil" bilgisi sızmaz.

LCV fotoğrafı neden silinemiyor? O dosya bir misafirin **yanıtına** bağlı
(`rsvps.photo_media_id`). Sahibin galeri ucundan silinmesi o yanıtı sessizce
bozardı.

Rota parametresi bu yüzden **model değil metin**: model bağlama kullanılsaydı
başka davetiyenin dosyası da çözülür ve karar bir `if`'e kalırdı.

---

## 3. Kilit — yüklemeyle aynı

`StoreUploadedMediaAction` diziye **davetiye satırı kilitliyken** ekliyor.
Silme kilitsiz çalışsaydı:

```
yükleme: diziyi oku [a]          silme: diziyi oku [a]
yükleme: [a, b] yaz               silme: [] yaz        ❌ b kayboldu
```

İki Action aynı satırı kilitlediği için biri diğerini bekler.

---

## 4. 🔴 Sıra: önce veritabanı, sonra dosya

Dosya sistemi transaction'a **dahil değildir** (F3). İki yanlış gidişin
ucuzunu seçiyoruz:

| Sıra | Arada patlarsa | Kim fark eder? |
|---|---|---|
| Dosya → transaction | Satır ve dizi kalır, dosya yok | **Misafir** — kırık görsel |
| **Transaction → dosya** ✅ | Dosya diskte kalır | Kimse — yalnızca disk küçük bir bedel öder |

`PruneOrphanMedia`'nın sırası bunun **tersi** ve o da doğru: orada kimsenin
görmediği veriyi temizliyoruz; en pahalı hata izini kaybetmek.

---

## 5. `touch()` neden var?

Dizi ile tablo ayrışmışsa (kimlik dizide yoksa) `save()` hiçbir şeyi kirli
bulmaz ve `updated` olayı fırlamaz. O zaman misafir cache'i, silinmiş dosyanın
URL'ini TTL dolana kadar göstermeye devam ederdi. `wasChanged()` yanlışsa
`touch()` olayı yine üretir (`UpdateInvitationAction` ile aynı desen).

---

## 6. Kuyruk

Yükleme `OptimizeUploadedImage`'ı kuyruğa atar. Fotoğraf iş koşmadan silinirse
iş `$deleteWhenMissingModels = true` sayesinde **sessizce düşer**; `failed_jobs`'a
gerçek bir hata gibi yazılmaz.

---

## 7. Kendin dene

```powershell
php artisan test tests/Feature/MediaTest.php
```

Galeri bölümündeki testler: sıra, silme, kota, IDOR, başka davetiye, LCV
medyası, kimliksiz istek, güncellemenin galeriyi yeniden yazamaması ve misafir
cache'inin tazelenmesi.

> ⚠️ **Test düzeneği tuzağı:** Aynı testte önce sahibin token'ıyla istek atılıp
> sonra başka bir token kullanılırsa uygulama örneği doğrulanmış kullanıcıyı
> önbellekte tutar ve ikinci token **yok sayılır**. IDOR testleri bu yüzden tek
> istekle yazıldı; galeri öğesi fabrikayla kurulur.
