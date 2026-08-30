# `database/migrations/2026_08_29_100000_add_media_columns_to_rsvps_table.php`

> **Faz:** 6 — Medya dilimi, dosya 6.17
> **Kardeş dosyalar:** [`2026_08_28_120000_create_rsvps_table.md`](2026_08_28_120000_create_rsvps_table.md) ·
> [`2026_08_28_130000_create_media_table.md`](2026_08_28_130000_create_media_table.md)

---

## 1. 🔴 Bu migration neden Faz 5'te yazılmadı?

`docs/09` bir dönem şunu varsayıyordu:

> *"Faz 5'te `rsvps.photo_media_id` kolonu nullable ve kısıtsız açılmıştı;
> kısıt Faz 6'da eklenir."*

**Yanlıştı.** Faz 5 medya kolonlarını hiç açmadı ve bu bilinçli bir karardı.

**Ders 26**: *çalıştırılmayan kod, doğru olduğu varsayılan koddur.* Kolon
sürümü: **bir faz boyunca yazanı olmayan kolon, doğru olduğu varsayılan
kolondur.** Faz 4'te `InvitationPublished` olayının `InvitationChanged`'e
dönüşme sebebi de buydu (**K48**).

Bugün üç şey birden hazır:

| Gereklilik | Nerede |
|---|---|
| Bağlanacak tablo | `media` (6.2) |
| Dosyayı yaratan uç | `PublicMediaController` (6.15/6.16) |
| Kolonu **yazan** kod | `SubmitRsvpAction` (6.20) — **aynı fazda** |

Bu yüzden kolonlar **ve** yabancı anahtar tek migration'da ekleniyor: *"kolon
var ama kısıtı yok"* ara durumu hiç oluşmuyor.

---

## 2. Neden `photo_media_id`, `photo_url` değil?

Frontend `types.ts` şöyle diyor:

```ts
export interface RSVPResponse {
  photoUrl?: string;
  videoUrl?: string;
}
```

Yani sözleşmede **URL** var. O hâlde neden kolonda **kimlik** saklıyoruz?

**E1**: *türetilebilen veri saklanmaz.* URL bir türetilmiş değerdir:

```
media.disk + media.path  →  Storage::disk($disk)->url($path)  →  URL
```

Ham URL saklasaydık:

- `APP_URL` veritabanına gömülürdü; alan adı değiştiği gün **her satır**
  kırılırdı ve hiçbir migration onları düzeltemezdi (hangi parçanın alan adı
  olduğunu bilmek metin ayrıştırmak demek)
- Yerel diskten S3'e geçiş **imkânsızlaşırdı**
- Dosya silindiğinde satırda **ölü bir URL** kalırdı — veritabanı bunu bilemezdi

Kimlik saklamak üçünü birden çözüyor. Sözleşmedeki URL'yi `RsvpResource`
üretecek (6.21).

### Yan kazanç: aidiyet doğrulanabilir hâle geliyor

Misafir `photoMediaId` gönderecek (6.19). Kimlik olduğu için sunucu şunu
**sorabilir**: *bu medya gerçekten bu davetiyeye mi ait?*

URL gönderilseydi bu soru sorulamazdı — istemci *"şu URL benim"* der ve
doğrulanacak bir şey kalmazdı (**N1**: aidiyet doğrulanacak girdi değil, yapısal
garanti olmalı).

---

## 3. `foreignUlid` — tip uyuşmazlığı sessiz değildir

```php
$table->foreignUlid('photo_media_id')->constrained('media');
```

`foreignUlid()` bir `char(26)` kolon açar — `media.id`'nin tipi (6.2, **K56**).

Eğer `media.id` bigint olsaydı `constrained()` **çalışma anında patlardı**:
PostgreSQL farklı tipler arasında yabancı anahtar kurmaz. Bu iyi bir şey —
şema hatası migration anında görünür, üretimde değil.

`constrained('media')` tablo adını **açıkça** veriyor, çünkü Laravel
`photo_media_id`'den `photo_media` tablosunu tahmin ederdi. Konvansiyona
güvenmek yerine yazmak, buradaki maliyeti sıfır olan bir netlik.

---

## 4. 🔴 `nullOnDelete`, `cascadeOnDelete` değil

```php
->nullOnDelete()
```

| Seçenek | Medya silinince |
|---|---|
| `cascadeOnDelete` | 🔴 **LCV yanıtı da silinir** |
| `nullOnDelete` | ✅ Kolon `null` olur, yanıt kalır |
| `restrictOnDelete` | Medya silinemez — temizlik işi bloke olur |

Neden `nullOnDelete`?

**Misafirin yazdığı metin, eklediği fotoğraftan bağımsız bir veridir.** "Melis
ve Can, tebrikler! 3 kişi geliyoruz" cümlesi, yanındaki fotoğraf silinse de
davetiye sahibi için değerlidir.

`cascadeOnDelete` yazsaydık, ileride yazılacak bir yetim-medya temizlik işi
sessizce **LCV kayıtlarını götürebilirdi**. Bir bakım görevinin veri silmesi,
fark edilmesi en zor hasar türüdür.

> Karşılaştır: `media.invitation_id` **`cascadeOnDelete`** kullanıyor (6.2) — ve
> orası doğru. Davetiye yoksa dosyalarının var olmasının anlamı yok. Aynı
> mekanizma, farklı yön: *silmenin anlamı ilişkinin yönüne bağlıdır.*

---

## 5. `nullable()` — neden zorunlu değil?

Çoğu LCV yanıtında foto/video **olmayacak**. Kolonlar zorunlu olsaydı her
gönderim bir dosya isterdi.

Ayrıca **N4**'ün akrabası: `null` burada *"misafir dosya eklemedi"* demek — ve
bu, boş bir string ya da bir yer tutucu kimlikten farklı bir bilgidir.

---

## 6. `after('message')` ne işe yarıyor?

Kolonun tablodaki **sırasını** belirler. Davranışa etkisi **yoktur**; yalnızca
`\d rsvps` çıktısında ilgili alanların yan yana durmasını sağlar.

⚠️ MySQL'de `after()` gerçek bir `ALTER` yan cümlesidir; PostgreSQL kolonu her
zaman sona ekler ve Laravel bunu sessizce yok sayar. Yani burada bir **niyet
beyanı** — çalışan bir kısıt değil.

---

## 7. `down()` — kısıt önce düşer

```php
$table->dropConstrainedForeignId('photo_media_id');
```

`dropColumn()` yeterli **değil**: PostgreSQL bir kolonu, üzerindeki yabancı
anahtarla birlikte düşürmez. `dropConstrainedForeignId()` iki işi sırayla yapar
— önce kısıt, sonra kolon.

`down()` yazmak bir tören değil: `migrate:rollback` geliştirme sırasında gerçekten
kullanılıyor ve yarım kalan bir rollback şemayı **kırık** bırakır.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `photo_url` kolonu açmak | `APP_URL` veritabanına gömülür; S3 göçü ve alan adı değişimi kırılır (**E1**) |
| 2 | `cascadeOnDelete` kullanmak | Bir temizlik işi sessizce LCV yanıtlarını siler |
| 3 | `constrained()` yazmayı unutmak | Kolon var, kısıt yok: olmayan bir medyaya işaret eden satırlar birikir (**E2**) |
| 4 | `foreignId` kullanmak (`foreignUlid` yerine) | Tip uyuşmazlığı; kısıt kurulamaz |
| 5 | Kolonları Faz 5'te açmak | Bir faz boyunca yazanı olmayan kolon (**ders 26**) |
| 6 | `nullable()` koymamak | Fotoğrafsız LCV gönderilemez |
| 7 | `down()`'da `dropColumn()` kullanmak | Rollback FK yüzünden patlar |

---

## 9. Kendin dene

```powershell
php artisan migrate
```

```sql
-- pgAdmin
\d rsvps
-- photo_media_id | character(26) | nullable
-- video_media_id | character(26) | nullable
-- "rsvps_photo_media_id_foreign" FOREIGN KEY (photo_media_id)
--     REFERENCES media(id) ON DELETE SET NULL
```

🔴 **Kısıtı gerçekten dene** — bir kısıtın varlığı, çalıştığının kanıtı değil:

```sql
-- 1) Olmayan bir medyaya işaret et: REDDEDİLMELİ
UPDATE rsvps SET photo_media_id = '01hzzzzzzzzzzzzzzzzzzzzzzz' WHERE id = (SELECT id FROM rsvps LIMIT 1);
-- ERROR: insert or update on table "rsvps" violates foreign key constraint

-- 2) Bağlı medyayı sil: LCV KALMALI, kolon null OLMALI
DELETE FROM media WHERE id = '<bağlı medya id>';
SELECT id, guest_name, photo_media_id FROM rsvps WHERE id = '<o lcv>';
-- satır DURUYOR, photo_media_id NULL
```

İkinci deneme `nullOnDelete` kararının kanıtı. `cascadeOnDelete` olsaydı satır
**kaybolurdu**.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Yabancı anahtar (FK)** | Bir kolonun başka tablodaki bir satıra işaret ettiğini veritabanına söyleten kısıt |
| **`ON DELETE SET NULL`** | Hedef satır silinince işaret eden kolonun `null` olması |
| **`ON DELETE CASCADE`** | Hedef satır silinince işaret eden **satırın da** silinmesi |
| **Referans bütünlüğü** | Var olmayan bir satıra işaret edilememesi garantisi |
| **Türetilmiş değer** | Saklanmayan, mevcut veriden hesaplanan değer (URL) |

---

## 11. Sırada ne var?

**6.18 — `Rsvp` modeli.** İki yeni ilişki (`photoMedia`, `videoMedia`) ve
`#[Fillable]` listesinin **neden genişlemediği**: kimlikler doğrulanmadan
atanamaz, o iş `SubmitRsvpAction`'ın (6.20).
