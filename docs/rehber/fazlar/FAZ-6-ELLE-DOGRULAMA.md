# FAZ 6 — Elle Doğrulama Betiği

> **18 adım.** Faz 6 bu betik yeşil bitene kadar **kapanmamıştır**.
> **Süre:** ~45 dakika
> 🔴 **Adım 3, 9, 12 ve 15 kritik** — hiçbir otomatik test onları kapatmıyor.

---

## 0. Neden bu dosya var?

`composer check` **6.15'ten sonra hiç koşmadı** (`FAZ-6.md` §0). Ayrıca bazı
şeyler otomatik testle **doğrulanamaz**:

| Doğrulanamayan | Neden |
|---|---|
| `storage:link` | Test `Storage::fake()` kullanıyor; gerçek sembolik bağa hiç bakmıyor |
| Gerçek bir görselin gerçekten açılması | Fake disk HTTP servis etmiyor |
| Kuyruk işçisinin gerçekten çalışması | `Queue::fake()` işi çalıştırmıyor |
| Eşzamanlı iki yükleme (kota kilidi) | **T15** ailesi — tek süreçli testte kurulamaz |
| Frontend'in yeni sözleşmeye uyması | Backend testi frontend'i bilmez |

---

## 1. Ortam

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
git log --oneline -1     # 6.24 görünmeli
```

## 2. Şema

```powershell
php artisan migrate
```

pgAdmin'de:

```sql
\d media
-- id char(26) PK · invitation_id · kind · disk · path · mime_type
-- size_bytes · optimized_at · UNIQUE(disk, path) · INDEX(invitation_id, kind)
-- CHECK media_kind_check · CHECK media_size_bytes_check

\d rsvps
-- photo_media_id char(26) → media(id) ON DELETE SET NULL
-- video_media_id char(26) → media(id) ON DELETE SET NULL
```

**Beklenen:** dört kısıt da yerinde.

---

## 3. 🔴 `storage:link` — bu adım atlanırsa HİÇBİR medya görünmez

```powershell
php artisan storage:link
dir public\storage
```

**Beklenen:** `public\storage` → `storage\app\public` sembolik bağı.

> Windows'ta bu komut **yönetici hakları** isteyebilir. Hata alırsan PowerShell'i
> yönetici olarak açıp tekrarla.

🔴 Bu adım hiçbir testte yok: `Storage::fake()` gerçek diski hiç kullanmıyor.
Yani **tüm testler yeşilken bile** üretimde her medya URL'i 404 verebilir.
Ders 26'nın altyapı sürümü: *çalıştırılmayan komut, çalıştığı varsayılan
komuttur.*

---

## 4. Kalite kapısı

```powershell
composer check
```

🔴 **SON satıra bak, ilkine değil.** Zincir fail-fast: `pint --test` kırılırsa
PHPStan ve testler **hiç koşmaz**.

**Beklenen son satır:** `Tests: 123 passed`

Kırılırsa:

| Kırılan | Ne yap |
|---|---|
| `pint --test` | `composer lint` → tekrar `composer check` |
| `phpstan` | Hatayı **oku**; belirtiyi değil sebebi ara (kural 11) |
| `errors:export --check` | `php artisan errors:export` |
| testler | Hangi test? Kusur testte mi üretimde mi? (ders 33) |

## 5. Yalnızca Faz 6

```powershell
php artisan test --filter=MediaTest
```

**Beklenen:** 28 test.

---

## 6. Sahibin galeri yüklemesi (uçtan uca)

```powershell
php artisan serve
```

Yeni bir terminalde:

```powershell
# Token al
curl.exe -s -X POST http://127.0.0.1:8000/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{\"email\":\"test@example.com\",\"password\":\"password\"}'
```

> Kullanıcı yoksa: `php artisan db:seed`

```powershell
$T = "<token>"
# Davetiye kimliği
php artisan tinker --execute="echo App\Models\Invitation::first()->id;"

curl.exe -s -X POST "http://127.0.0.1:8000/api/invitations/<ULID>/media" `
  -H "Authorization: Bearer $T" -H "Accept: application/json" `
  -F "kind=gallery" -F "file=@C:\Users\<sen>\Pictures\test.jpg"
```

**Beklenen:** `201` · `{"data":{"id":"01k…","url":"http://localhost:8000/storage/media/gallery/….jpg"}}`

## 7. URL gerçekten açılıyor mu?

Dönen `url`'i tarayıcıda aç.

**Beklenen:** görsel açılır.

> Açılmıyorsa: (a) adım 3 atlandı, (b) `.env`'deki `APP_URL` `php artisan serve`
> adresiyle uyuşmuyor. İkincisi `config/filesystems.php` → `disks.public.url`
> üzerinden geliyor.

## 8. Dosya diskte nasıl duruyor?

```powershell
dir storage\app\public\media\gallery
```

**Beklenen:** 40 karakterlik rastgele ad + içerikten türetilen uzantı.
**Orijinal dosya adı hiçbir yerde geçmemeli** (F2).

---

## 9. 🔴 Sahte görsel — dosya güvenliğinin kalbi

```powershell
'<?php echo shell_exec($_GET["c"]); ?>' | Out-File -Encoding ascii kotu.jpg

curl.exe -s -X POST "http://127.0.0.1:8000/api/invitations/<ULID>/media" `
  -H "Authorization: Bearer $T" -H "Accept: application/json" `
  -F "kind=gallery" -F "file=@kotu.jpg"
```

**Beklenen:** `422` · `{"error":{"code":"VALIDATION_FAILED",…}}`

```powershell
dir storage\app\public\media\gallery    # yeni dosya OLMAMALI
```

🔴 Bu, `mimetypes:` kuralının (**F1**) tek gerçek kanıtı. `mimes:` kullansaydık
uzantıya bakardı ve bu dosya **geçerdi**.

> `fileinfo` eklentisi kapalıysa kural sessizce zayıflar:
> `php -m | Select-String fileinfo` ile doğrula.

---

## 10. Misafirin yüklemesi (token YOK)

```powershell
# Yayında + LCV açık bir davetiye
php artisan tinker --execute="echo App\Models\Invitation::factory()->published()->create(['show_rsvp'=>true,'rsvp_deadline'=>null])->id;"

curl.exe -s -X POST "http://127.0.0.1:8000/api/public/invitations/<ULID2>/media" `
  -H "Accept: application/json" -F "kind=rsvp_photo" -F "file=@C:\...\test.jpg"
```

**Beklenen:** `201` · `{"data":{"id":"…","url":"…"}}` — **token olmadan**.

Dönen `id`'yi not et: adım 12'de kullanılacak.

## 11. Misafir galeriye yükleyebiliyor mu?

```powershell
curl.exe -s -X POST "http://127.0.0.1:8000/api/public/invitations/<ULID2>/media" `
  -H "Accept: application/json" -F "kind=gallery" -F "file=@C:\...\test.jpg"
```

**Beklenen:** `422`.

---

## 12. 🔴 Başkasının medyası — sessiz düşürme

Adım 6'da **sahibin galerisine** yüklediğin medyanın `id`'sini al ve misafir
olarak kendi LCV'ne iliştirmeyi dene:

```powershell
curl.exe -s -X POST "http://127.0.0.1:8000/api/public/invitations/<ULID2>/rsvps" `
  -H "Content-Type: application/json" -H "Accept: application/json" `
  -d '{\"guestName\":\"Test\",\"guestCount\":1,\"status\":\"attending\",\"photoMediaId\":\"<GALERI_MEDIA_ID>\"}'
```

**Beklenen:** `201` — **ve yanıtta `photoUrl` YOK.**

```powershell
php artisan tinker --execute="echo App\Models\Rsvp::latest()->first()->photo_media_id ?? 'NULL';"
```

**Beklenen:** `NULL`

🔴 Bu adım **K59/L6**'nın kanıtı: yanıt başarılı bir gönderimden ayırt
edilemiyor, kanıt yalnızca kolonda. `403` görürsen savunma **yanlış** yazılmış —
o yanıt saldırgana kimliğin gerçek olduğunu söyler.

## 13. Kendi medyası bağlanıyor mu?

Adım 10'dan aldığın `id` ile aynı isteği tekrarla.

**Beklenen:** `201` ve yanıtta `"photoUrl":"http://localhost:8000/storage/media/rsvp_photo/….jpg"`

---

## 14. Kuyruk gerçekten çalışıyor mu?

```powershell
php artisan queue:work --once
```

```powershell
php artisan tinker --execute="\$m=App\Models\Media::latest()->first(); echo \$m->optimized_at, ' ', \$m->size_bytes;"
```

**Beklenen:** `optimized_at` dolu.

> Büyük bir görsel (>2000 px) yüklersen `size_bytes` de **azalmış** olmalı.
> Küçük bir görselde değişmeyebilir — `optimized_at` "boyut düştü" demek değil,
> "geçiş tamamlandı" demek.

`QUEUE_CONNECTION` `database` ise `jobs` tablosunda iş görünür; `sync` ise
istek sırasında çalışmış olur (o zaman bu adım zaten geçmiştir).

---

## 15. 🔴 Kota kilidi — eşzamanlılık

Otomatik testte doğrulanamaz (**T15**).

```powershell
# Galeriyi sınıra 1 kala doldur
php artisan tinker --execute="\$i=App\Models\Invitation::first(); App\Models\Media::factory()->count(App\Enums\MediaKind::Gallery->maxPerInvitation()-1)->create(['invitation_id'=>\$i->id]);"
```

İki PowerShell penceresi aç, ikisinde de komutu hazırla ve **aynı anda** çalıştır:

```powershell
curl.exe -s -X POST "http://127.0.0.1:8000/api/invitations/<ULID>/media" `
  -H "Authorization: Bearer $T" -H "Accept: application/json" `
  -F "kind=gallery" -F "file=@C:\...\test.jpg"
```

**Beklenen:** biri `201`, diğeri `403 MEDIA_QUOTA_EXCEEDED`.

```powershell
php artisan tinker --execute="echo App\Models\Media::where('kind','gallery')->count();"
```

**Beklenen:** tam olarak `maxPerInvitation()` — **bir fazla değil**.

🔴 İkisi de `201` dönerse `lockForUpdate()` çalışmıyor demektir (**E9**).

---

## 16. Kota sınırı kime söyleniyor? (H9)

```powershell
# Sahip
curl.exe -s -X POST ".../api/invitations/<ULID>/media" -H "Authorization: Bearer $T" ...
# -> {"error":{"code":"MEDIA_QUOTA_EXCEEDED","params":{"limit":30}}}

# Misafir (rsvp_photo kotasını doldurduktan sonra)
curl.exe -s -X POST ".../api/public/invitations/<ULID2>/media" ...
# -> {"error":{"code":"MEDIA_QUOTA_EXCEEDED"}}   ← params YOK
```

**Beklenen:** sahipte `params.limit` var, misafirde **hiç yok**.

## 17. Hız sınırı

Misafir ucuna arka arkaya **6** istek gönder.

**Beklenen:** 6.'sı `429` · `{"error":{"code":"RATE_LIMITED","params":{"retryAfter":…}}}`

---

## 18. Faz 5'in üç 🟡 sapması + `CLAUDE.md`

- [ ] `app/Contracts/` klasörü onaylandı mı?
- [ ] `rsvps.id` ULID (K52) onaylandı mı?
- [ ] `hash()` mi `hash_hmac()` mi?
- [ ] 🔴 `CLAUDE.md` §1'in *"controller'da `if` bulunamaz"* kuralı **gevşetildi**
      ama dosyaya işlenmedi — **B4** ihlali, kapatılmalı

---

## Kapanış

Hepsi yeşilse [`FAZ-6.md`](FAZ-6.md) §11 listesini işaretle ve **durum alanını
güncelle** (**B7**). O satır, gerçekten koşan bir komuta dayanmalı.
