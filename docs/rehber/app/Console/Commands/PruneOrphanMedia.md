# `app/Console/Commands/PruneOrphanMedia.php`

> **Faz:** 9 — Üretim hazırlığı, dosya 9.10 · **Komut:** `php artisan media:prune-orphans`
> **İlgili:** [`../../Enums/MediaKind.md`](../../Enums/MediaKind.md) ·
> [`../../Actions/Media/StoreGuestMediaAction.md`](../../Actions/Media/StoreGuestMediaAction.md) ·
> [`../../../config/davetkart.md`](../../../config/davetkart.md)
> **Kurallar:** **F3** (dosya sistemi transaction'a dâhil değildir) · **F4**
> (depolama konumu satırda) · **L7** (geri alınamayan iş en sona) · **L3**

---

## 1. Faz 6 bu deliği gördü ve yalnızca **yavaşlattı**

`config/davetkart.php`, Faz 6'dan beri şunu yazıyor:

> *"Bu sınır olmasa gönderim yapmadan yüklenen 'yetim' dosyalarla disk
> doldurulabilirdi."*

Sınır (`max_per_invitation`) doldurmayı **yavaşlatır** ama **temizlemez**.
Misafir dosyayı yükler, formu göndermez; satır ve dosya sonsuza kadar kalır.
Bir davetiye başına 200 fotoğraf sınırı, o 200'ün silinmesini sağlamaz.

> **L3'ün üçüncü yüzü:** hız sınırı "ne sıklıkta"ya, kota "ne kadar"a bakar —
> **temizlik** ise "ne kadar süre"ye bakar. Üçü birbirinin yerine geçmez.

---

## 2. Yetim tanımı — üç koşul birden

```php
->whereIn('kind', MediaKind::guestUploadableValues())      // 1
->whereNotExists(… rsvps.photo_media_id / video_media_id)  // 2
->where('created_at', '<', now()->subHours($grace))        // 3
```

| # | Koşul | Olmasaydı |
|---|---|---|
| 1 | Tür misafir yüklemesi | 🔴 Komut **bütün galerileri** silerdi |
| 2 | Hiçbir LCV satırı işaret etmiyor | Kullanılan fotoğraflar giderdi |
| 3 | Bekleme süresi dolmuş (24 saat) | Misafir formu doldururken dosyası silinirdi |

### Birinci koşul en ince olanı

Galeri medyası bir LCV yanıtına **bağlanmaz** — davetiyenin galerisinin
**kendisidir**. "Hiçbir rsvp işaret etmiyor" ölçütü ona uygulansaydı her galeri
dosyası yetim görünürdü. Ölçüt, türün **ne işe yaradığına** bağlı; aynı SQL
farklı türler için farklı anlam taşıyor.

---

## 3. 🔴 Ham tablo sorgusu, `Rsvp` modeli değil

```php
$query->select(DB::raw('1'))->from('rsvps')->whereColumn(…)
```

Model sorgusu (`Rsvp::query()`) **soft delete süzgecini** uygular. Yani
silinmiş bir LCV yanıtının fotoğrafı "kimse işaret etmiyor" görünür ve
silinirdi.

Ham sorgu silinmiş satırları da sayar — ve **yanlış yönde hata yapar**:
fazla dosya saklamak, birinin fotoğrafını yanlışlıkla silmekten iyidir. Bir
temizlik işinde fail-safe yön **temizlememektir**.

> Faz 6'da öğrenilen *"soft delete ilişkiyi `null` yapar"* tuzağının tersi:
> orada silinmiş üst kayıt bir `TypeError` üretmişti; burada silinmiş bir kayıt
> **fazla** silmeye yol açardı.

---

## 4. 🔴 Sıra: önce DOSYA, sonra SATIR

```php
Storage::disk($media->disk)->delete($media->path);   // 1
$media->delete();                                    // 2
```

**F3** (dosya sistemi transaction'a dâhil değildir) burada bir **sıra kararı**
doğuruyor. İki yanlış gidişin daha ucuzu seçiliyor:

| Sıra | Hata olursa |
|---|---|
| Satır önce, dosya sonra | 🔴 Dosya diskte kalır ve onu işaret eden **hiçbir kayıt yoktur**. Bir daha asla bulunamaz — disk sızıntısı **kalıcı** |
| ✅ Dosya önce, satır sonra | Satır dosyasız kalır. Kimse ona bakmıyor (zaten yetim) ve **bir sonraki koşu** aynı satırı bulur: `Storage::delete()` olmayan dosyada da `true` döner, satır silinir. Hata **kendi kendini onarır** |

**L7** (*geri alınamayan işi en sona koy*) burada ince bir çeviri istiyor:
geri alınamaz olan **silmek** değil, **izini kaybetmek**. Referansı olmayan bir
dosya, silinmiş bir dosyadan daha kötüdür — yer kaplar ve kimse bilmez.

Disk **satırdan** okunuyor, config'ten değil (**F4**): S3'e göçten sonra eski
satırlar hâlâ kendi diskinden silinir.

---

## 5. `chunkById`, `chunk` değil

```php
$this->orphans()->chunkById(100, …);
```

Düz `chunk()` `OFFSET` ile sayfalar. Ama biz **sayfaladığımız satırları
siliyoruz**: birinci sayfa silinince ikinci sayfanın offset'i kayar ve her
sayfada birkaç satır **atlanır**. Klasik "silerken sayfalama" hatası.

`chunkById` offset yerine **son görülen `id`'den** devam eder; silme onu
etkilemez.

---

## 6. Bu komutun YAPMADIKLARI (B6)

| Yapmaz | Neden / nerede |
|---|---|
| Galeri medyasını silmek | Yetim olamaz (§2) |
| Silinmiş davetiyelerin medyasını silmek | 🔴 Ayrı bir iş — soft delete geri alınabilir olmalı |
| Diskte olup satırı olmayan dosyaları bulmak | Ters yön: disk taraması. Bugün gerek yok; §8'e bak |
| `rsvps` satırlarına dokunmak | FK `nullOnDelete`; LCV metni fotoğraftan bağımsızdır (K60) |
| Kendini zamanlamak | `routes/console.php` (9.11) |

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `kind` süzgecini unutmak | 🔴 Bütün galeriler silinir |
| 2 | Bekleme süresini koymamak | Misafirin yüklediği dosya form gönderilmeden silinir |
| 3 | `Rsvp::query()` kullanmak | Soft-delete edilmiş LCV'nin fotoğrafı silinir (§3) |
| 4 | Satırı önce silmek | Kalıcı disk sızıntısı (§4) |
| 5 | `chunk()` kullanmak | Her sayfada birkaç yetim atlanır (§5) |
| 6 | `config('…media.disk')` ile silmek | S3 göçünden sonra eski dosyalar silinemez (**F4**) |

---

## 8. 🔴 Kapatmadığı delik: ters yön

Bu komut **satırdan diske** bakıyor: *"kaydı var, referansı yok."* Tersi de
mümkün — **diskte olup satırı olmayan** dosya. Bu, `StoreUploadedMediaAction`'ın
telafi bloğu (`Storage::delete()`) da patlarsa oluşur.

Bugün yazılmadı: bir disk taraması, S3'te binlerce `LIST` çağrısı demek ve
henüz böyle bir dosyanın var olduğuna dair **hiçbir kanıt yok**. Kanıt
üretilmeden yazılan temizlik kodu, ders 26'nın tarif ettiği koddur.

`FAZ-9.md`'ye borç olarak yazılıyor: S3'e geçildikten sonra bir kez elle
sayım (`aws s3 ls | wc -l` ile `SELECT count(*) FROM media`) yapılmalı.

---

## 9. Kendin dene

```powershell
php artisan tinker
>>> $m = App\Models\Media::factory()->rsvpPhoto()->create(['created_at' => now()->subDays(2)]);
>>> Storage::disk($m->disk)->put($m->path, 'test');

php artisan media:prune-orphans --dry-run   # "1 yetim yukleme bulundu"
php artisan media:prune-orphans             # "1 yetim yukleme silindi."
php artisan media:prune-orphans             # "0 yetim yukleme silindi."  ← idempotent
```

Sonra dosyanın gerçekten gittiğini **diskte** doğrula:

```powershell
dir storage\app\public\media\rsvp_photo
```

🔴 Bu son adım testin göremediği şey: `Storage::fake()` gerçek diski hiç
görmez (Faz 6'nın `storage:link` dersi). Elle doğrulamaya bu yüzden giriyor.

**Mutasyon denemeleri (T16):**

| Mutasyon | Kırmızıya dönmesi gereken test |
|---|---|
| `whereIn('kind', …)` sil | `it_never_touches_gallery_media` |
| `created_at` koşulunu sil | `it_keeps_an_unreferenced_upload_inside_the_grace_period` |
| `orWhereColumn(video…)` sil | `it_keeps_an_upload_referenced_by_an_rsvp_video` |
| `Storage::delete()` satırını sil | `it_removes_the_file_from_its_own_disk` |

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Yetim (orphan) kayıt** | Kendisine işaret eden hiçbir kaydı kalmamış satır/dosya |
| **Bekleme süresi (grace period)** | Bir kaydın "henüz yetim sayılmadığı" tampon süre |
| **`whereNotExists`** | Bağlı bir alt sorgunun hiç satır döndürmediği koşul |
| **`chunkById`** | Son görülen id'den devam ederek sayfalama |
| **Fail-safe** | Hata durumunda güvenli tarafa düşmek — burada: silmemek |
