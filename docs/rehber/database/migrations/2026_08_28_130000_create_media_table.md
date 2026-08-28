# `database/migrations/2026_08_28_130000_create_media_table.php`

> **Kod dosyası:** `database/migrations/2026_08_28_130000_create_media_table.php`
> **Faz:** 6 — Medya dilimi, dosya 6.2
> **Kardeş dosya:** [`2026_08_28_120000_create_rsvps_table.md`](2026_08_28_120000_create_rsvps_table.md)

---

## 1. `media` tablosu neyi saklıyor?

**Dosyanın kendisini değil, dosya hakkındaki kaydı.** Dosya diskte durur;
bu tablo *"kim, neyi, nereye, ne zaman koydu"* sorusunu cevaplar.

Peki neden bir tabloya ihtiyaç var? Dosyayı diske yazıp URL'i döndürsek yetmez
miydi? Dört şey için yetmezdi:

| İhtiyaç | Tablo olmadan |
|---|---|
| **Kota** (`max_per_invitation`) | Kaç dosya olduğunu saymanın yolu yok — diski taramak gerekirdi |
| **Denetim** | "Bu dosya hangi MIME ile, ne boyutta kabul edildi?" cevapsız |
| **Optimizasyon** | Kuyruktaki iş hangi dosyayı işleyeceğini bilemez |
| **Temizlik** | Davetiye silinince hangi dosyaların gideceği bilinmez |

---

## 2. Kolon kolon: hangi soruyu cevaplıyor?

| Kolon | Soru | Not |
|---|---|---|
| `id` | Bu kayıt hangisi | ULID (K56) |
| `invitation_id` | Kime ait | 🔴 §3 |
| `kind` | Ne türden | CHECK, enum'dan |
| `disk` | **Nereye yazılmıştı** | 🔴 §4 |
| `path` | Diskte hangi yolda | URL değil — §5 |
| `mime_type` | İçeriği ne çıktı | İstemcinin beyanı değil |
| `size_bytes` | Kaç bayt | CHECK > 0 |
| `optimized_at` | Kuyruk işini yaptı mı | §7 |

---

## 3. 🔴 `user_id` kolonu neden yok?

İlk refleks "her dosyanın bir sahibi olur" demek. Bu tabloda **olmuyor**:

```
Galeri fotoğrafı  →  davetiye sahibi yükledi   →  bir kullanıcı var
LCV fotoğrafı     →  MİSAFİR yükledi           →  kullanıcı YOK
```

LCV medyasını kimliği bilinmeyen biri yüklüyor. `user_id` kolonu açsaydık
yarısı `null` olurdu — ve `null` bir kolon, "bu bilgi bazen yok" demektir,
oysa burada bilgi **hiç yok**.

Ama her dosyanın bir **davetiyesi** var. Yetki de oradan sorulur:

```
User ──sahip──> Invitation ──içerir──> Media
```

Bu, Faz 5'te `RsvpPolicy` için verilen kararın aynısı (**P5**): *alt kaynağın
yetkisi üst kaynağın policy'sine devredilir.* Sahiplik kuralı hâlâ tek yerde,
`InvitationPolicy::owns()` içinde.

**Ders:** bir kolonun yokluğu da bir tasarım kararıdır. "Olsun, lazım olur"
diye eklenen `null`'lu kolon, veri modelini yalan söyler hâle getirir.

---

## 4. 🔴 `disk` kolonu — config zaten var, neden saklıyoruz?

```php
$table->string('disk', 32);
```

`config('davetkart.media.disk')` zaten diski söylüyor. Neden tekrar saklıyoruz?
Bu **E1**'in (*türetilebilen veri saklanmaz*) ihlali değil mi?

Değil — çünkü ikisi **farklı soruların** cevabı:

| | Soru | Zaman |
|---|---|---|
| `config(...)` | Yeni dosyayı **şimdi** nereye yazayım? | Şimdi |
| `media.disk` | O dosya **o gün** nereye yazılmıştı? | Geçmiş |

Yarın `DAVETKART_MEDIA_DISK=s3` yapıldığı gün, config "s3" der. Ama diskte
duran on bin eski dosya hâlâ yerel diskte. Kolon olmasaydı hepsi **bir anda
çözülemez** hâle gelirdi.

**Genel kural:** bir değer *"şu anki ayar"* ise config'ten okunur; *"o anki
gerçek"* ise satıra yazılır. Aynı ayrım Faz 5'te de vardı: `guest_count`
satırda saklanır, `max_guests_per_entry` config'te durur.

---

## 5. `path` saklanıyor, `url` değil

URL şöyle türetilir:

```php
Storage::disk($media->disk)->url($media->path);
```

Ham URL'i saklasaydık, `APP_URL` **veritabanına gömülmüş** olurdu:

- Alan adı değişince (`davetkart.test` → `davetkart.com`) tüm bağlantılar kırılır
- Yerelde üretilen kayıtlar üretimde `localhost` gösterir
- CDN eklendiği gün her satır güncellenmeli

Bu **E1**'in gerçek bir uygulaması: URL, `disk + path + yapılandırma`dan
türetilebilir, dolayısıyla saklanmaz.

> ⚠️ Frontend **URL** görüyor (`galleryImages: string[]`), yani sözleşmede URL
> var. Ama sözleşmede olması, veritabanında olması gerektiği anlamına gelmez —
> dönüşüm Resource katmanının işi (**C1**).

---

## 6. İki kısıt ve bir benzersizlik

### `CHECK (kind IN (...))`

Değerler `MediaKind::values()`'tan geliyor, elle yazılmıyor —
`rsvps.status` ve `invitations.status` ile birebir aynı desen.

### 🔴 `CHECK (size_bytes > 0)`

Faz 5'te öğrenilen dersin tekrarı: **PostgreSQL'de `UNSIGNED` yoktur.**
`unsignedInteger` düz `integer`'a düşer ve `-5` kabul eder.

Negatif boyut ne kırardı? Toplam disk kullanımı hesabı (`SUM(size_bytes)`)
aşağı çekilirdi — Faz 5'teki `guest_count` sorununun aynısı. Sıfır bayt da
anlamsız: yükleme yarım kalmış demektir.

### `UNIQUE (disk, path)`

Dosya adları rastgele üretiliyor (6.6), çarpışma ihtimali astronomik derecede
düşük. O hâlde kısıt neden?

**E2**: *benzersizlik veritabanı kısıtıyla korunur, `if` ile değil.* Rastgelelik
bir **olasılıktır**, garanti değil. Kısıt, garantiyi alışkanlığa değil **yapıya**
bağlar. Ve maliyeti sıfıra yakın: zaten bir indekse ihtiyacımız var.

---

## 7. `optimized_at` — neden boolean değil zaman damgası?

```php
$table->timestamp('optimized_at')->nullable();
```

`bool optimized` de yazılabilirdi. Zaman damgası **iki bilgi birden** taşır:

1. İşlendi mi? (`null` ise hayır)
2. **Ne zaman** işlendi? (hata ayıklarken paha biçilmez)

Ayrıca kuyruk işinin **idempotans**ını veriyle sağlıyor: iş ikinci kez koşarsa
damgayı görüp erken çıkabilir. `ShouldBeUnique` gibi bir kuyruk mekanizmasına
güvenmek yerine — çünkü o mekanizma cache sürücüsüne bağlıdır ve cache
temizlenirse sessizce devre dışı kalır.

`null`'ın iki anlamı var ve bu bilinçli: *"henüz işlenmedi"* ve *"bu tür zaten
optimize edilmiyor"* (video). Ayırmak gerekseydi ayrı bir kolon gerekirdi;
bugün gerekmiyor.

---

## 8. `cascadeOnDelete` ve soft delete tuzağı

```php
$table->foreignUlid('invitation_id')->constrained()->cascadeOnDelete();
```

Davetiye silinirse medya kayıtları da gider. Ama:

> ⚠️ `Invitation` **soft delete** kullanıyor. `$invitation->delete()` satırı
> gerçekten silmez, `deleted_at` damgalar — ve CASCADE **tetiklenmez**.
> Medya kayıtları durur. Bu bilinçli: kullanıcı davetiyeyi geri alabilir.

🔴 **Ve burada B6 gereği yazılması gereken bir boşluk var:** CASCADE yalnızca
**veritabanı satırını** siler, **diskteki dosyayı silmez**. Yani gerçek bir
`forceDelete` sonrası dosyalar diskte yetim kalır.

Bunun çözümü bir **çöp toplama** (garbage collection) işidir: periyodik olarak
"hiçbir media satırının işaret etmediği dosyaları sil". Faz 6'da yazılmadı,
`FAZ-6.md` §9'da açık madde olarak duruyor. Yazmamak bir eksiklik; **yazdığını
sanmak** bir hata olurdu.

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `user_id` kolonu eklemek | Yarısı `null` olur; veri modeli yalan söyler |
| 2 | `disk` kolonunu atlayıp config'ten okumak | Disk değiştiği gün eski dosyalar çözülemez |
| 3 | `path` yerine `url` saklamak | `APP_URL` veritabanına gömülür; alan adı değişince her şey kırılır |
| 4 | `size_bytes` CHECK'ini atlamak | PG'de negatif değer geçer; toplam hesabı bozulur |
| 5 | `mime_type`'a istemcinin gönderdiğini yazmak | Bütün MIME savunması anlamsızlaşır |
| 6 | CHECK değerlerini elle yazmak | Enum değişince kısıt sessizce eskir |
| 7 | Diskteki dosyanın CASCADE ile silindiğini sanmak | Yetim dosyalar birikir (B6) |
| 8 | `path` uzunluğunu 255'ten kısa tutmak | Derin dizin yapısı veya uzun rastgele ad taşabilir |

---

## 10. Kendin dene

```powershell
php artisan migrate
psql -U postgres -d davetkart -c "\d media"
```

**Beklenen:** çıktının sonunda `media_kind_check`, `media_size_bytes_check` ve
`media_disk_path_unique`.

Kısıtların gerçekten *reddettiğini* kanıtla (kural 14 — beklediğin sebeple mi?):

```php
// php artisan tinker
$inv = App\Models\Invitation::first();

// 1) Geçersiz tür -> CHECK reddetmeli
DB::table('media')->insert([
    'id' => Str::ulid(), 'invitation_id' => $inv->id,
    'kind' => 'profil_foto',           // ← enum'da yok
    'disk' => 'public', 'path' => 'a/b.jpg',
    'mime_type' => 'image/jpeg', 'size_bytes' => 100,
    'created_at' => now(), 'updated_at' => now(),
]);
// QueryException: media_kind_check   ← BEKLENEN

// 2) Sıfır bayt -> ikinci CHECK reddetmeli  ('size_bytes' => 0)
// 3) Aynı disk+path iki kez -> UNIQUE reddetmeli
```

🔴 Üçü de **geçerse** kısıtlar oluşmamış demektir. Bir korumanın var olduğunu,
ancak bir şeyi reddettiğini gördüğünde bilirsin (Faz 4, ders 35).

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **CHECK kısıtı** | Satır düzeyinde doğruluk kuralı; veritabanı zorlar |
| **CASCADE** | Üst kayıt silinince alt kayıtların da silinmesi |
| **Soft delete** | Satırı silmeyip `deleted_at` damgalamak |
| **Idempotans** | Aynı işlemin tekrarının tek etki üretmesi |
| **Çöp toplama (GC)** | Artık referans edilmeyen kaynakları temizleme |
| **Yetim (orphan) dosya** | Diskte olan ama hiçbir kaydın işaret etmediği dosya |
| **MIME tipi** | Dosyanın içerik türü; uzantıdan bağımsız |

---

## 12. Sırada ne var?

**6.3 — `app/Models/Media.php`.** Bu tablonun PHP yüzü: beyaz liste, cast'ler,
`Invitation` ilişkisi ve URL'i türeten `url()` accessor'ı.

| İlgili | Nerede |
|---|---|
| Tür enum'u | [`../../app/Enums/MediaKind.md`](../../app/Enums/MediaKind.md) |
| Kardeş migration | [`2026_08_28_120000_create_rsvps_table.md`](2026_08_28_120000_create_rsvps_table.md) |
| Faz özeti | [`../../fazlar/FAZ-6.md`](../../fazlar/FAZ-6.md) |
