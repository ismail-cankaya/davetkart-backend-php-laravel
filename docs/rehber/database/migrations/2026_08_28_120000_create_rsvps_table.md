# `database/migrations/2026_08_28_120000_create_rsvps_table.php`

> **Kod dosyası:** `database/migrations/2026_08_28_120000_create_rsvps_table.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.2
> **Ön okuma:** [`kavramlar/veritabani-ve-migration.md`](../../kavramlar/veritabani-ve-migration.md)
> — migration'ın ne olduğu ve neden PHP'de yazıldığı orada anlatıldı; burada
> tekrarlanmıyor.
> **Kardeş dosya:** [`2026_08_19_120000_create_invitations_table.md`](2026_08_19_120000_create_invitations_table.md)

---

## 1. Bu dosya ne işe yarar?

`rsvps` tablosu, **kimliği bilinmeyen bir misafirin yazdığı** tek veri
yapısıdır. Sistemdeki diğer her yazma işlemi bir token'ın arkasında; bu değil.

Bu yüzden tablo tasarımı sıradan bir CRUD tablosundan farklı düşünülür. Üç soru
her kolonda tekrar soruldu:

1. Bu alanı **kim** dolduruyor? (misafir mi, sunucu mu?)
2. Bu alan **boş kalabilir mi**, yoksa boşluğu bir hata mı?
3. Bu alanı kötü niyetli biri doldurursa **ne kırılır**?

---

## 2. Kolon kolon: kim dolduruyor?

| Kolon | Dolduran | Boş olabilir mi | Not |
|---|---|---|---|
| `id` | sunucu | ❌ | ULID — §3 |
| `invitation_id` | sunucu (URL'den) | ❌ | ilişkiden gelir, gövdeden değil |
| `guest_name` | misafir | ❌ | adsız yanıt sahibe faydasız |
| `guest_count` | misafir | ❌ (varsayılan 1) | kotanın birimi |
| `status` | misafir | ❌ | K49 enum'u |
| `menu_preference` | misafir | ✅ | modül kapalıysa hiç sorulmaz |
| `message` | misafir | ✅ | isteğe bağlı not |
| `ip_hash` | sunucu | ❌ | §6 — KVKK |
| `created_at` / `updated_at` | sunucu | ❌ | |

🔴 **`invitations` tablosuyla en çarpıcı fark burada.** Orada içerik
alanlarının **tamamı** `nullable`'dı, çünkü autosave yarım veri gönderiyordu.
Burada tam tersi: misafir formu **tek seferde** gönderir, yarım LCV diye bir şey
yoktur. Aynı proje, aynı ORM, zıt kararlar — çünkü *veriyi kimin, nasıl ürettiği*
farklı.

---

## 3. Neden `ULID` birincil anahtar? (K52)

Faz 3'te K40 şunu söylemişti: `invitations.id` ULID olacak çünkü **link olarak
paylaşılıyor**; `timeline_events.id` bigint kalacak çünkü **hiçbir URL'de
geçmiyor**.

`rsvps` hangi tarafta? Faz 5'in uç noktalarına bak:

```
DELETE /api/rsvps/{id}      ← kimlik URL'de GEÇİYOR
```

Yani K40'ın kuralı doğrudan uygulanır: **URL'de geçen kimlik ULID olur** (K52).

Artan bir bigint kullansaydık, `.../rsvps/1834` yanıtı platformdaki **toplam
LCV sayısını** ele verirdi. Bu klasik bir *enumeration* sızıntısıdır: rakip bir
firma iki hafta arayla iki kayıt oluşturup aradaki farka bakarak iş hacmini
ölçebilir. Uç nokta auth'lu olsa bile kimlik değerinin kendisi bilgi taşır.

İkinci kazanç bedava geliyor: rota katmanında `whereUlid('rsvp')` yazabiliriz,
yani **biçimsiz bir kimlik veritabanına hiç ulaşmaz** (O6, Faz 4).

---

## 4. `foreignUlid` ve `cascadeOnDelete`

```php
$table->foreignUlid('invitation_id')->constrained()->cascadeOnDelete();
```

- **`foreignUlid`**, `foreignId` değil: üst tablonun anahtarı ULID olduğu için
  yabancı anahtarın tipi de ULID olmalı. Tip uyuşmazlığı olsaydı PostgreSQL
  kısıtı oluşturmayı reddederdi.
- **`constrained()`**: kolon adından tabloyu tahmin eder (`invitation_id` →
  `invitations`) ve gerçek bir `FOREIGN KEY` kısıtı kurar. Bu kısıt olmasaydı
  var olmayan bir davetiyeye bağlı "yetim" LCV satırları oluşabilirdi.
- **`cascadeOnDelete()`**: davetiye silinirse yanıtları da silinir. Bu bir
  KVKK gereğidir (unutulma hakkı), performans tercihi değil.

> ⚠️ `invitations` **soft delete** kullanıyor. Yani `$invitation->delete()`
> satırı gerçekten silmez, `deleted_at` damgalar — ve CASCADE **tetiklenmez**.
> LCV satırları durur. Bu bilinçli: kullanıcı davetiyeyi geri alabilir
> (`restore`) ve yanıtlarını geri isteyecektir. CASCADE yalnızca gerçek silmede
> (`forceDelete`, hesap silme) devreye girer.

---

## 5. İki CHECK kısıtı — hangi kuralın sahibi kim?

**E6** şunu söylüyordu: *veritabanı kısıtı yalnızca backend'in sahibi olduğu
kurallara konur.* Bu tabloda üç aday vardı, ikisi kısıt aldı:

| Kural | Kısıt aldı mı | Neden |
|---|---|---|
| `status` geçerli bir enum değeri | ✅ | Durum makinesi backend'in malı ve bir güvenlik sınırı |
| `guest_count >= 1` | ✅ | Veri bütünlüğü — §5.1 |
| `guest_count <= 10` | ❌ | `config('davetkart.rsvp.max_guests_per_entry')` bir **iş tercihi**; Gold plan yarın 20 diyebilir. Kısıt olsaydı fiyat değişikliği migration gerektirirdi |
| `menu_preference` geçerli bir seçenek | ❌ | Frontend kataloğunun anahtarı; yeni menü eklemek deploy istememeli |

### 5.1 🔴 PostgreSQL'de `UNSIGNED` yoktur

```php
$table->unsignedSmallInteger('guest_count')->default(1);
```

Bu satır MySQL'de `SMALLINT UNSIGNED` üretir ve negatif değeri **veritabanı
seviyesinde** engeller. PostgreSQL'de ise böyle bir tip yoktur; Laravel sessizce
düz `smallint` yazar. Yani:

```sql
INSERT INTO rsvps (..., guest_count) VALUES (..., -5);   -- kısıt olmasa GEÇER
```

`guest_count = -5` olan bir satır kotayı **aşağı çeker**: saldırgan önce birkaç
negatif yanıt gönderip sonra kotayı sonsuza kadar açabilirdi. Bu yüzden açık
`CHECK (guest_count >= 1)`.

**Ders:** bir yardımcı metodun adı (`unsigned...`) her veritabanında aynı şeyi
yapmaz. Faz 4'ün 36. dersinin akrabası — *elle yazılan (veya varsayılan) bir
korumanın gerçekten koruduğu, ancak kanıtlandığında bilinir.*

---

## 6. 🔴 `ip_hash` — ham IP neden saklanmıyor?

```php
$table->string('ip_hash', 64);
```

IP adresi **kişisel veridir** (KVKK ve GDPR'ın ortak yorumu: bir gerçek kişiyi
dolaylı olarak belirlenebilir kılar). Ham saklarsak:

- Veritabanı sızarsa davetiyeye kimin yanıt verdiğinin **coğrafi izi** sızar.
- Sahip, panelde misafirlerinin IP'sini görebilir hâle gelir — kimsenin
  istemediği bir özellik.
- Silme talebinde ("beni unut") tek tek satır aramak gerekir.

Hash'lemek bu üçünü birden kapatır ama **ihtiyacımız olan yeteneği korur**:
"aynı yerden ikinci kez mi geldi?" sorusu hâlâ cevaplanabilir, çünkü aynı IP
her zaman aynı hash'i üretir.

Formül `CLAUDE.md` §3'te yazılı: `hash(ip + app_key)`. `APP_KEY` burada bir
**pepper** (biber) görevi görür:

> IPv4 uzayı 4 milyar adrestir. Sadece `sha256(ip)` yazsaydık, bir saldırgan
> tüm IPv4 uzayının hash'ini birkaç saatte üretip tabloyu **geri çözebilirdi**.
> `APP_KEY` karışıma girdiğinde bu sözlük saldırısı imkânsızlaşır: anahtarı
> bilmeden tablo üretilemez.

Bu yüzden `ip_hash` **geri döndürülemez** ve bilerek öyle. Hash'ten IP'ye dönmek
bizim de yapamayacağımız bir şeydir; ihtiyacımız zaten yok.

`64` uzunluğu tesadüf değil: `sha256` 256 bit = 32 bayt = **64 onaltılık
karakter** üretir.

---

## 7. İndeks: neden bir tane yetiyor?

```php
$table->index(['invitation_id', 'status']);
```

Bileşik (composite) indeks **soldan sağa** çalışır. Yani bu tek indeks iki
sorguya da hizmet eder:

```sql
-- Kota (5.7): tam eşleşme, iki kolon da kullanılır
SELECT SUM(guest_count) FROM rsvps
 WHERE invitation_id = ? AND status IN ('attending','pending');

-- Liste (5.9): yalnızca ÖNEK kullanılır — yine de indeksten faydalanır
SELECT * FROM rsvps WHERE invitation_id = ? ORDER BY created_at DESC;
```

Tersi doğru değildir: `INDEX(status, invitation_id)` olsaydı ikinci sorgu
indeksi **kullanamazdı**, çünkü `status` filtresi yok. Bileşik indekste kolon
sırası bir tercih değil, bir tasarım kararıdır.

Üçüncü bir indeks (`invitation_id, created_at`) **eklenmedi**: her indeks her
`INSERT`'i yavaşlatır ve LCV tablosu yazma-ağırlıklıdır. Sıralama maliyeti
ölçülüp gerçekten sorun olduğunda eklenir — önce ölç, sonra ekle.

---

## 8. Neden `photo_url` / `video_url` kolonu yok?

Frontend'in `RSVPResponse` tipinde bu iki alan var ve `config/davetkart.php`
`rsvp_photo` / `rsvp_video` limitlerini şimdiden tanımlıyor. Yine de kolonları
**açmıyoruz**.

Gerekçe **ders 26**: *çalıştırılmayan kod, doğru olduğu varsayılan koddur.*
Medya modülü Faz 6'da yazılacak; kolonları bugün açarsak bir faz boyunca
hiçbir kodun yazmadığı, hiçbir testin doğrulamadığı iki kolonumuz olur. Faz 4'te
`InvitationPublished` olayı tam olarak bu sebeple `InvitationChanged`'e
dönüşmüştü (K48).

Faz 6 bunları `ALTER TABLE` ile ekleyecek — sıradan bir migration.

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `foreignId('invitation_id')` yazmak | Tip uyuşmazlığı: bigint ↔ ULID. Migration çalışma anında patlar |
| 2 | Ham IP saklamak | KVKK ihlali; sızıntıda kişisel veri sızar |
| 3 | `sha256(ip)` — `APP_KEY` olmadan | Tüm IPv4 uzayı önceden hesaplanıp tablo geri çözülebilir |
| 4 | `guest_count` üst sınırını CHECK'e koymak | Fiyat/paket değişikliği migration gerektirir (E6) |
| 5 | `status`'e native `ENUM` tipi kullanmak | K39: değer eklemek/çıkarmak sıradan migration olmaktan çıkar |
| 6 | CHECK kısıtındaki değerleri elle yazmak | Enum değişince kısıt sessizce eskir |
| 7 | `down()` içinde kısıtları tek tek düşürmek | Gereksiz: kısıt tabloya bağlıdır, tabloyla birlikte düşer |
| 8 | Migration dosyasını var olan bir tarihle adlandırmak | Sıra bozulur; `invitations` tablosu henüz yokken FK kurulmaya çalışılır |

---

## 10. Kendin dene

```powershell
php artisan migrate
php artisan migrate:status          # rsvps satırı 'Ran' görünmeli
```

Kısıtların gerçekten çalıştığını **kanıtla** (kural 14: beklediğin yanıtı
beklediğin sebeple mi alıyorsun?):

```powershell
php artisan tinker
```

```php
// 1) Geçersiz durum -> CHECK kısıtı reddetmeli
DB::table('rsvps')->insert([
    'id' => Str::ulid(), 'invitation_id' => Invitation::first()->id,
    'guest_name' => 'Test', 'guest_count' => 2, 'status' => 'Katılıyor',
    'ip_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
]);
// QueryException: rsvps_status_check   ← BEKLENEN

// 2) Negatif misafir -> ikinci CHECK reddetmeli
// ... 'guest_count' => -5, 'status' => 'attending' ...
// QueryException: rsvps_guest_count_check   ← BEKLENEN
```

🔴 İkisi de **geçerse** kısıtlar oluşmamış demektir — `migrate:fresh` çalıştırıp
tekrar dene. Bir korumanın var olduğunu, ancak bir şeyi reddettiğini gördüğünde
bilirsin (Faz 4, ders 35).

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Migration** | Şema değişikliğini kodla, sürümlenebilir biçimde tarif etme |
| **ULID** | Zaman sıralı, tahmin edilemez 26 karakterlik kimlik |
| **Enumeration** | Artan kimliklerden kayıt sayısını/varlığını çıkarma sızıntısı |
| **Foreign key** | Bir kolonun başka tablonun anahtarına işaret ettiğini garanti eden kısıt |
| **CASCADE** | Üst kayıt silinince alt kayıtların da silinmesi |
| **Soft delete** | Satırı silmeyip `deleted_at` damgalamak |
| **CHECK kısıtı** | Satır düzeyinde doğruluk kuralı; veritabanı zorlar |
| **Bileşik indeks** | Birden çok kolonu birlikte indeksleme; soldan sağa çalışır |
| **Pepper** | Hash'e karışan, veritabanında olmayan gizli anahtar |
| **Sözlük saldırısı** | Olası tüm girdilerin hash'ini önceden hesaplayıp eşleştirme |
| **Veri minimizasyonu** | Yalnızca gerekeni saklama ilkesi (KVKK m.4) |

---

## 12. Sırada ne var?

**5.3 — `app/Models/Rsvp.php`.** Bu tablonun PHP tarafındaki yüzü: `#[Fillable]`
beyaz listesi (🔴 `invitation_id` ve `ip_hash` **listede olmayacak**), cast'ler
ve `Invitation` ilişkisi.

| İlgili | Nerede |
|---|---|
| Durum enum'u | [`../../app/Enums/RsvpStatus.md`](../../app/Enums/RsvpStatus.md) |
| Kardeş migration | [`2026_08_19_120000_create_invitations_table.md`](2026_08_19_120000_create_invitations_table.md) |
| Faz özeti | [`../../fazlar/FAZ-5.md`](../../fazlar/FAZ-5.md) |
