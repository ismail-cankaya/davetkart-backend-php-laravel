# `2026_09_17_100000_add_gallery_media_ids_to_invitations_table.php`

> **Kod dosyası:** `database/migrations/2026_09_17_100000_add_gallery_media_ids_to_invitations_table.php`
> **Faz:** 6 — açık kalan maddenin kapanışı (`docs/09` → `gallery_images[]`)
> **Önce oku:** [`2026_08_28_130000_create_media_table.md`](2026_08_28_130000_create_media_table.md)
> **Sonra:** [`../../app/Actions/Media/DeleteGalleryMediaAction.md`](../../app/Actions/Media/DeleteGalleryMediaAction.md)

---

## 1. Hangi borcu kapatıyor?

Faz 6 yükleme uçlarını teslim etti ama galeriyi davetiyeye **bağlamadı**:

| Yer | Faz 6 sonu | Sonuç |
|---|---|---|
| `InvitationPayloadResource` | `'galleryImages' => []` | Sahip yüklediği fotoğrafı yeniden açınca göremiyordu |
| `PublicInvitationResource` | `$design['galleryImages'] = []` | Misafir galeriyi **hiç** göremiyordu |
| `InvitationRequest::COLUMN_MAP` | `galleryImages` yok | Frontend'in gönderdiği URL listesi sessizce düşüyordu |

Dosyalar diskteydi, `media` satırları yazılıyordu; ama "bu davetiyenin galerisi
hangi dosyalardan, hangi sırayla oluşur?" sorusunun **cevabını tutan bir yer
yoktu.**

---

## 2. Neden `media` tablosuna bir `position` kolonu değil?

`Media.md §6` bu kararı Faz 6'da zaten vermişti:

> Galerinin sırası `media` tablosunda **tutulmuyor.** `media` satırları sunucunun
> kaydı: kota sayımı ve temizlik için.

| Seçenek | Sorun |
|---|---|
| `created_at`'e göre sırala | Olmayan bir sırayı uydurur: eşzamanlı yüklemeler seçim sırasıyla bitmez; ileride sürükle-bırak hiç ifade edilemez |
| `media.position` kolonu | Yeniden sıralama **N satırı** günceller; silmede boşluklar kayar |
| **`invitations.gallery_media_ids` (jsonb dizi)** ✅ | Sıra **tek bir değerdir**, tek yazımla değişir; davetiyenin satır kilidiyle korunur |

---

## 3. Neden kimlik, URL değil?

URL `disk + path + APP_URL`'den **türetilir** ve saklanmaz (**E1**,
`create_media_table §5`). Dizide URL saklasaydık alan adı değiştiği gün tüm
galeriler kırılırdı. Dizide **ULID** durur; URL'e Resource katmanında çevrilir.

---

## 4. `default('[]')` ve NOT NULL

"Galerisi yok" bir **değerdir**, bilinmeyen bir durum değil. `NULL`'a izin
verseydik her okuma yeri iki boş hâli (`null` ve `[]`) ayrı ayrı düşünmek
zorunda kalırdı.

PostgreSQL, varsayılanı olan bir NOT NULL kolonu eklerken değeri **mevcut
satırlara da** yazar; ayrı bir doldurma (backfill) adımı gerekmez.

---

## 5. 🔴 Dizi kimin?

Kolon **`#[Fillable]` listesinde değil.** Yalnızca iki Action yazar:

| Action | İşlem |
|---|---|
| `StoreUploadedMediaAction` | Galeri yüklemesini, satırıyla **aynı kilitli transaction'da** sona ekler |
| `DeleteGalleryMediaAction` | Aynı kilitle diziden çıkarır, sonra satırı ve dosyayı siler |

İstek gövdesinden gelen bir liste kabul edilseydi iki şey kırılırdı:
başka davetiyenin dosya kimliği yazılabilirdi ve otomatik kaydetme ile yükleme
yarışında **yeni fotoğraf sessizce kaybolurdu.** (`MediaTest::an_invitation_update_cannot_rewrite_the_gallery`)

---

## 6. Kendin dene

```powershell
php artisan migrate
php artisan tinker
>>> \App\Models\Invitation::first()->galleryMediaIds()
=> []
```

Geri almak için: `php artisan migrate:rollback --step=1` (kolon düşer; `media`
satırları ve dosyalar yerinde kalır).
