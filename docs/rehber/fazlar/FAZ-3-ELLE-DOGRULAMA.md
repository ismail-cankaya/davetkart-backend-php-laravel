# Faz 3 — Elle Doğrulama Kılavuzu

> **Ne için:** Davetiye CRUD'unun, sahiplik savunmasının ve program
> senkronizasyonunun **elle** doğrulanması.
> **Ne zaman:** Otomatik testler yeşil yandıktan sonra, faz kapanışından önce.
> **Neden elle?** Otomatik testler davranışı doğrular; tarayıcıdaki gerçek akış
> (autosave'in hangi metodu attığı, imlecin kaybolup kaybolmadığı, kartın anında
> kaybolması) yalnızca elle görülebilir.
> **Bağlantılı:** [`FAZ-3.md`](FAZ-3.md) ·
> [`InvitationTest.md`](../tests/Feature/InvitationTest.md) ·
> [`InvitationPolicy.md`](../app/Policies/InvitationPolicy.md)

---

## 0. Hazırlık

### 0.1 Önce otomatik testler

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
composer lint
composer check
```

**Beklenen:** Pint ✓ · PHPStan ✓ · `errors:export --check` ✓ · **39 test** yeşil
(7 HealthTest + 14 AuthTest + 18 InvitationTest).

Bu geçmeden aşağıdakilere geçme — elle doğrulama, otomatik testin yerine geçmez.

### 0.2 Temiz veritabanı ve sunucu

```powershell
php artisan migrate:fresh --seed
php artisan serve
```

Seeder çıktısında `Demo hesap: test@ornek.test / password` görmelisin.

🔴 **Bu terminali açık bırak.** Aşağıdakilerin tamamı **ikinci bir terminalde**
çalıştırılır.

### 0.3 Yardımcı fonksiyonlar

İkinci terminale bir kez yapıştır:

```powershell
$BASE = "http://127.0.0.1:8000/api"

function Giris($mail, $sifre = "password") {
  $body = @{ email = $mail; password = $sifre } | ConvertTo-Json
  $r = Invoke-RestMethod -Uri "$BASE/auth/login" -Method Post -Body $body -ContentType "application/json"
  return @{ Authorization = "Bearer $($r.token)" }
}

function Kayit($ad, $soyad, $mail, $sifre = "password") {
  $body = @{ firstName = $ad; lastName = $soyad; email = $mail; password = $sifre } | ConvertTo-Json
  $r = Invoke-RestMethod -Uri "$BASE/auth/register" -Method Post -Body $body -ContentType "application/json"
  return @{ Authorization = "Bearer $($r.token)" }
}

# Hata govdesini de gosteren cagri — 4xx'te PowerShell govdeyi yutar
function Cagir($metot, $yol, $baslik, $govde = $null) {
  try {
    $p = @{ Uri = "$BASE$yol"; Method = $metot; Headers = $baslik; ContentType = "application/json" }
    if ($govde) { $p.Body = $govde }
    $r = Invoke-WebRequest @p
    return @{ Status = [int]$r.StatusCode; Body = $r.Content }
  } catch {
    $s = [int]$_.Exception.Response.StatusCode
    $reader = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())
    return @{ Status = $s; Body = $reader.ReadToEnd() }
  }
}
```

```powershell
$ayse = Giris "test@ornek.test"
$mehmet = Kayit "Mehmet" "Yilmaz" "mehmet@ornek.test"
```

---

## 1. Rota kaydı (3.11)

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
php artisan route:list --path=invitations
```

**Beklenen — beş rota:**

```
GET|HEAD   api/invitations ............... invitations.index
POST       api/invitations ............... invitations.store
GET|HEAD   api/invitations/{invitation} .. invitations.show
PUT|PATCH  api/invitations/{invitation} .. invitations.update
DELETE     api/invitations/{invitation} .. invitations.destroy
```

Hepsinde `auth:sanctum` middleware'i görünmeli.

🔴 **`/api/v1/invitations` OLMAMALI** — versiyon namespace'te, URL'de değil
(K10). Görürsen frontend'in `baseURL = '/api'` ayarı anında kırılır.

---

## 2. Kimlik kapısı (3.11)

```powershell
(Cagir GET "/invitations" @{}).Status
```

**Beklenen:** `401`

```powershell
(Cagir GET "/invitations" @{}).Body
```

**Beklenen:** `{"error":{"code":"UNAUTHENTICATED"}}`

Metin yok, yalnızca kod (K20).

---

## 3. Liste — seeder verisi (3.11)

```powershell
$liste = Invoke-RestMethod -Uri "$BASE/invitations" -Headers $ayse
$liste.data.Count
$liste.data | ForEach-Object { "$($_.status) — $($_.invitation.title)" }
```

**Beklenen:**

```
2
saved — Taslak Davetiye
published — Yayindaki Davetiye
```

Sıra `updated_at` azalan olduğu için ikisinin yeri değişebilir; önemli olan
**ikisinin de** gelmesi.

### 3.1 Program eager-load edildi mi?

```powershell
$liste.data[0].invitation.timelineEvents.Count
```

**Beklenen:** `3`

Boş gelirse `with('timelineEvents')` unutulmuş demektir (3.11 §4).

### 3.2 🔴 Sorgu sayısı — N+1 kontrolü

`php artisan serve` terminalinde SQL log'u yoksa `.env`'de geçici olarak
`DB_LOG_QUERIES` yerine `tinker` kullan:

```powershell
php artisan tinker
```

```php
DB::enableQueryLog();
$u = App\Models\User::first();
$u->invitations()->with('timelineEvents')->get();
count(DB::getQueryLog());     // => 2   ✅ (davetiyeler + adimlar)

DB::flushQueryLog();
$u->invitations()->get()->each(fn ($i) => $i->timelineEvents);
// => LazyLoadingViolationException   ✅ N+1 yerelde exception'a cevriliyor
```

İkincisi Faz 0'ın `shouldBeStrict()` ayarının hâlâ çalıştığının kanıtı.

---

## 4. Oluşturma (3.11)

```powershell
$govde = @{
  invitation = @{
    categoryId = "kina"
    imageTheme = "kina-bordo"
    palette = "midnight"
    title = "Kina Gecemiz"
    names = "Elif & Burak"
    date = "2026-10-04T20:00"
    timelineEvents = @(
      @{ id = $null; time = "20:00"; title = "Kina Yakma" },
      @{ id = $null; time = "22:00"; title = "Eglence" }
    )
  }
} | ConvertTo-Json -Depth 6

$sonuc = Cagir POST "/invitations" $ayse $govde
$sonuc.Status
$yeni = $sonuc.Body | ConvertFrom-Json
$yeni.data.id
$yeni.data.status
$yeni.data.invitation.timelineEvents | ForEach-Object { "$($_.id) — $($_.title)" }
```

**Beklenen:**

```
201
01K3QX8FVBN3K7YHTM5RWDPC4E      ← 26 karakter ULID
saved
9 — Kina Yakma                   ← sunucunun urettigi kimlikler
10 — Eglence
```

🔴 **Kontrol listesi:**

| Ne | Beklenen | Kural |
|---|---|---|
| Durum kodu | `201`, `200` değil | RFC 9110 |
| `id` uzunluğu | 26 karakter | K40 |
| `status` | `saved` | K38 — istemci belirleyemez |
| Adım `id`'leri | **Dolu ve metin** | K44 — `null` gönderdik, sunucu verdi |

```powershell
$id = $yeni.data.id
```

---

## 5. 🔴 Sunucunun sahip olduğu alanlar yazılamaz (3.4, 3.8)

```powershell
$saldiri = @{
  invitation = @{
    categoryId = "dugun"; imageTheme = "moda-gece"; palette = "midnight"
    title = "Bedava Yayin"
    status = "published"
    user_id = 999
  }
  status = "published"
  user_id = 999
} | ConvertTo-Json -Depth 6

$r = Cagir POST "/invitations" $ayse $saldiri
($r.Body | ConvertFrom-Json).data.status
```

**Beklenen:** `saved`

Ödemesiz yayına geçme denemesi sessizce düştü. Veritabanından da doğrula:

```powershell
php artisan tinker
```

```php
App\Models\Invitation::query()->where('title', 'Bedava Yayin')->first(['user_id', 'status', 'published_at']);
// => user_id = 1 (Ayse), status = saved, published_at = null   ✅
```

Üç savunma katmanı birlikte çalıştı: FormRequest kuralı yok → `validated()`
düşürdü; `#[Fillable]` listesinde yok → `fill()` yok saydı; sarmal yapı
(`invitation` altında `status` tanımlı değil) zaten geçit vermedi.

---

## 6. 🔴 IDOR — sahiplik savunması (3.7)

Mehmet kendi davetiyesini oluştursun:

```powershell
$mGovde = @{ invitation = @{ categoryId = "dugun"; imageTheme = "moda-gece"; palette = "midnight"; title = "Mehmet Dugun" } } | ConvertTo-Json -Depth 6
$mehmetinki = (Cagir POST "/invitations" $mehmet $mGovde).Body | ConvertFrom-Json
$mId = $mehmetinki.data.id
```

Şimdi **Ayşe'nin token'ıyla** Mehmet'in davetiyesini iste:

```powershell
$r = Cagir GET "/invitations/$mId" $ayse
$r.Status
$r.Body
```

**Beklenen:**

```
404
{"error":{"code":"RESOURCE_NOT_FOUND"}}
```

🔴 **403 GÖRÜRSEN DUR.** 403, kaynağın var olduğunu doğrular ve saldırgana
"denediğin kimlik gerçek" bilgisini verir (K20 §3.2).

### 6.1 Ayırt edilemezlik

```powershell
$yokOlan = Cagir GET "/invitations/01ARZ3NDEKTSV4RRFFQ69G5FAV" $ayse
$baskasinin = Cagir GET "/invitations/$mId" $ayse

$yokOlan.Status -eq $baskasinin.Status
$yokOlan.Body -eq $baskasinin.Body
```

**Beklenen:** `True` ve `True`

⚠️ `APP_DEBUG=true` ise `debug` bloğu exception sınıfını taşır ve gövdeler
farklı çıkar — bu **beklenen** davranıştır (H3: blok yalnızca yerelde çalışır).
Gerçek kontrolü `.env`'de `APP_DEBUG=false` yapıp `php artisan config:clear`
sonrası tekrarla.

### 6.2 Yazma ve silme de korunuyor mu?

```powershell
(Cagir PUT "/invitations/$mId" $ayse $govde).Status
(Cagir DELETE "/invitations/$mId" $ayse).Status
```

**Beklenen:** `404` ve `404`

Ve Mehmet'in davetiyesi bozulmamış olmalı:

```powershell
$kontrol = Invoke-RestMethod -Uri "$BASE/invitations/$mId" -Headers $mehmet
$kontrol.data.invitation.title
```

**Beklenen:** `Mehmet Dugun` — 404 dönmesi yazmanın yapılmadığını kanıtlamaz,
**etkiyi** doğruluyoruz (T14).

---

## 7. 🔴 Program senkronizasyonu (3.10)

Adım 4'te oluşturduğumuz davetiyeyle devam:

```powershell
$mevcut = Invoke-RestMethod -Uri "$BASE/invitations/$id" -Headers $ayse
$adimlar = $mevcut.data.invitation.timelineEvents
$ilkId = $adimlar[0].id
$ikinciId = $adimlar[1].id
```

### 7.1 Üç yol tek istekte: güncelle + ekle + sil

```powershell
$sync = @{
  invitation = @{
    categoryId = "kina"; imageTheme = "kina-bordo"; palette = "midnight"
    timelineEvents = @(
      @{ id = $ikinciId; time = "23:00"; title = "Eglence (guncellendi)" },
      @{ id = $null; time = "01:00"; title = "Kapanis" }
    )
  }
} | ConvertTo-Json -Depth 6

$r = (Cagir PUT "/invitations/$id" $ayse $sync).Body | ConvertFrom-Json
$r.data.invitation.timelineEvents | ForEach-Object { "$($_.id) — $($_.title)" }
```

**Beklenen:**

```
10 — Eglence (guncellendi)     ← id KORUNDU, silinip yeniden yaratilmadi
11 — Kapanis                   ← yeni satir
```

🔴 İki kontrol:

| Ne | Beklenen | Neden |
|---|---|---|
| `$ikinciId` aynı kaldı mı? | Evet | Sil-ve-yeniden-yarat yapılmıyor |
| `$ilkId` listede yok mu? | Evet, silindi | Gelen listede olmayan silinir |

Veritabanından `sort_order`'ı da doğrula:

```php
App\Models\TimelineEvent::query()->orderBy('id')->get(['id', 'title', 'sort_order']);
// Eglence → sort_order 0   ✅ sira LISTEDEKI KONUMDAN yazildi
// Kapanis → sort_order 1
```

### 7.2 Alan gönderilmezse programa dokunulmaz

```powershell
$sadeceBaslik = @{ invitation = @{ title = "Yalnizca baslik degisti" } } | ConvertTo-Json -Depth 6
$r = (Cagir PUT "/invitations/$id" $ayse $sadeceBaslik).Body | ConvertFrom-Json
$r.data.invitation.title
$r.data.invitation.timelineEvents.Count
```

**Beklenen:** `Yalnizca baslik degisti` ve `2`

🔴 Program **korundu**. `0` görürsen `null` ile `[]` ayrımı bozulmuş demektir
(N4) — kısmi güncelleme kullanıcıların programını siliyor.

### 7.3 Boş dizi hepsini siler

```powershell
$bosla = @{ invitation = @{ timelineEvents = @() } } | ConvertTo-Json -Depth 6
$r = (Cagir PUT "/invitations/$id" $ayse $bosla).Body | ConvertFrom-Json
$r.data.invitation.timelineEvents.Count
```

**Beklenen:** `0`

### 7.4 🔴 Başkasının adımı ezilemiyor mu?

Mehmet'in davetiyesine bir adım ekle, sonra Ayşe o adımın kimliğini kendi
davetiyesinde göndersin:

```powershell
$mProgram = @{ invitation = @{ categoryId = "dugun"; imageTheme = "moda-gece"; palette = "midnight"; timelineEvents = @(@{ id = $null; time = "18:00"; title = "MEHMET NIKAH" }) } } | ConvertTo-Json -Depth 6
$m = (Cagir PUT "/invitations/$mId" $mehmet $mProgram).Body | ConvertFrom-Json
$kurbanId = $m.data.invitation.timelineEvents[0].id

$saldiri = @{ invitation = @{ timelineEvents = @(@{ id = $kurbanId; time = "03:00"; title = "ELE GECIRILDI" }) } } | ConvertTo-Json -Depth 6
(Cagir PUT "/invitations/$id" $ayse $saldiri).Status

# Mehmet'in adimi bozuldu mu?
$kontrol = Invoke-RestMethod -Uri "$BASE/invitations/$mId" -Headers $mehmet
$kontrol.data.invitation.timelineEvents[0].title
$kontrol.data.invitation.timelineEvents[0].id
```

**Beklenen:**

```
200                    ← Ayse KENDI davetiyesini guncelledi, istek gecerli
MEHMET NIKAH           ← ✅ kurban satir BOZULMADI
<kurbanId ile ayni>    ← ✅ satir tasinmadi da
```

Ayşe'nin davetiyesinde ise **yeni** bir satır açılmış olmalı:

```powershell
$a = Invoke-RestMethod -Uri "$BASE/invitations/$id" -Headers $ayse
$a.data.invitation.timelineEvents[0].id -ne $kurbanId
```

**Beklenen:** `True`

Bu, fazın en sinsi açığının kapalı olduğunun kanıtı: üst kaynağın sahipliği
doğrulanmış olsa bile alt kaynağın aidiyeti ayrıca korunuyor (N1).

---

## 8. Sözleşme biçimleri (3.9)

```powershell
$c = Invoke-RestMethod -Uri "$BASE/invitations/$id" -Headers $ayse
$c.data.invitation.date
$c.data.updatedAt
$c.data.invitation.phoneBackground -eq $c.data.invitation.imageTheme
$c.data.invitation.galleryImages.Count
```

**Beklenen:**

```
2026-10-04T20:00              ← saat dilimi YOK  (<input type="datetime-local">)
2026-08-19T15:04:05+03:00     ← saat dilimi VAR  (JS Date)
True                          ← K41: turetiliyor
0                             ← Faz 6'da dolacak
```

🔴 `date` alanında `+03:00` görürsen `<input>` değeri **reddeder** ve
kullanıcının tarihi sessizce silinir (3.9 §3).

### 8.1 Sızıntı kontrolü

```powershell
$ham = (Cagir GET "/invitations/$id" $ayse).Body
$ham -match "user_id|userId|publishedAt|deleted_at|sortOrder"
```

**Beklenen:** `False`

Beyaz liste çalışıyor (C1). `True` görürsen Resource'ta `toArray()` benzeri bir
toplu dönüşüm yapılmış demektir.

---

## 9. Silme ve soft delete (3.11)

```powershell
(Cagir DELETE "/invitations/$id" $ayse).Status
(Cagir DELETE "/invitations/$id" $ayse).Body
```

**Beklenen:** `204` ve **boş gövde**

```powershell
(Invoke-RestMethod -Uri "$BASE/invitations" -Headers $ayse).data.id -contains $id
```

**Beklenen:** `False` — listede yok.

Ama satır duruyor:

```php
App\Models\Invitation::withTrashed()->find('BURAYA_ID');
// => model doner, deleted_at dolu     ✅ soft delete

App\Models\TimelineEvent::query()->where('invitation_id', 'BURAYA_ID')->count();
// => 0'dan BUYUK   ✅ soft delete CASCADE tetiklemez (3.3 §3)
```

İkincisi önemli: kullanıcı geri isterse davetiye **programıyla birlikte** döner.

---

## 10. Doğrulama hataları (3.8)

```powershell
$bozuk = @{ invitation = @{ categoryId = "dugun"; imageTheme = "x"; palette = "y"; timelineEvents = @(
  @{ id = $null; time = "19:00"; title = "Gecerli" },
  @{ id = $null; time = "25:99"; title = "Bozuk" }
) } } | ConvertTo-Json -Depth 6

$r = Cagir POST "/invitations" $ayse $bozuk
$r.Status
$r.Body
```

**Beklenen:**

```
422
{"error":{"code":"VALIDATION_FAILED","fields":{
  "invitation.timelineEvents.1.time":[{"rule":"date_format","params":{"values":["H:i"]}}]}}}
```

🔴 İki kontrol:

| Ne | Beklenen | Neden |
|---|---|---|
| Anahtar **indeks** taşıyor mu? | `...1.time` | Frontend doğru satırı işaretleyebilsin |
| `...0.time` var mı? | **Yok** | Geçerli satır için hata üretilmemeli (T6) |
| Alan adları camelCase mi? | Evet | D1 — istek neyi gönderdiyse o |

### 10.1 D6 kontrolü — parola kuralı

```powershell
$r = Cagir POST "/auth/register" @{} (@{ firstName = "A"; lastName = "B"; email = "yeni@ornek.test"; password = "123" } | ConvertTo-Json)
$r.Body
```

**Beklenen:** `"rule":"min"` — `"illuminate\\_validation\\_rules\\_password"`
**GÖRÜLMEMELİ** (D6).

---

## 11. Uçtan uca — 🎯 Faz 3'ün asıl bitiş ölçütü

Backend doğru çalışıyor. Asıl ölçüt tarayıcıda.

```powershell
cd D:\Projects\davetkart\davetkart-frontent
npm run lint
npm run dev
```

`npm run lint` **yeşil olmalı** — F1'de açılan üç TypeScript hatası F7/F8'de
kapandı.

`http://localhost:3000` → giriş: `test@ornek.test` / `password`

| # | Adım | Beklenen | Neyin kanıtı |
|---|---|---|---|
| 1 | Dashboard | Seeder'ın **iki** davetiyesi listelenir | K37 · F5 |
| 2 | Sekmeler | "Yayında Olanlar 1" · "Kaydedilenler 1" | F5 |
| 3 | "Düzenlemeye Devam Et" | Editör o kaydı açar, metinler dolu gelir | F6 |
| 4 | Başlığı değiştir, 2 sn bekle | Network: **`PUT /api/invitations/{id}`** | 🔴 F4/F6 |
| 5 | Dashboard'a dön | Değişiklik görünür, **yeni kart oluşmaz** | 🔴 F6 |
| 6 | "Yeni Davetiye Oluştur" → bir şey yaz | Network: **`POST`** | F4 |
| 7 | Yazmaya devam et | Network: **`PUT`** — aynı kayıt | 🔴 F4 kuyruğu |
| 8 | Program adımı ekle, 2 sn bekle | Giden gövde: `"id": null` | K44 |
| 9 | Bir harf daha yaz, 2 sn bekle | Giden gövde: `"id": "12"` — **dolu** | 🔴 `adoptServerIds` |
| 10 | Program adımına hızlıca yaz | İmleç **kaybolmamalı** | F7 `localKey` |
| 11 | Üç adım ekle, ortadakini sil | Yalnızca o gitmeli | F7 |
| 12 | Kartta "Sil" → onayla | Kart **anında** kaybolur, bildirim çıkar | F5/F6 |
| 13 | Sayfayı yenile | Silinen geri **gelmez** | F5 |
| 14 | `/invite/{id}` bağlantısı | Sayfa açılır (henüz yerel veri — Faz 4) | ⚠️ beklenen |

🔴 **4. ve 5. adım en kritiği.** `POST` görürsen `loadRecord` kimliği taşımıyor
demektir ve kullanıcı her düzenlemeye dönüşünde bir kopya davetiye üretir.

🔴 **7. adım yarış kontrolü.** İki `POST` görürsen kaydetme kuyruğu çalışmıyor
demektir. Hızlıca yazıp durmayı birkaç kez tekrarla.

---

## 12. Kontrol listesi

Faz 3 ancak hepsi ✅ ise kapanır.

- [ ] `composer check` yeşil — **39 test**
- [ ] `php artisan route:list --path=invitations` → 5 rota, `/api/v1/` **yok**
- [ ] Kimliksiz istek → `401` `UNAUTHENTICATED`
- [ ] Liste yalnızca kendi davetiyelerini döndürüyor
- [ ] Liste programı eager-load ediyor (2 sorgu)
- [ ] Oluşturma `201` + ULID + `status: saved`
- [ ] `status` / `user_id` enjeksiyonu **sessizce düşüyor**
- [ ] 🔴 Başkasının davetiyesi → `404`, **403 değil**
- [ ] 🔴 Yok olan ile başkasının kaydı **ayırt edilemiyor** (`APP_DEBUG=false`)
- [ ] Güncelleme ve silme de `404` alıyor, **hedef bozulmuyor**
- [ ] 🔴 Senkronizasyon: güncellenen id korunuyor, listede olmayan siliniyor
- [ ] 🔴 Alan gönderilmezse program **korunuyor**; `[]` gönderilince siliniyor
- [ ] 🔴 Başkasının program adımı **ezilemiyor**
- [ ] `date` saat dilimsiz, `updatedAt` saat dilimli
- [ ] `user_id` / `sortOrder` / `publishedAt` yanıta **sızmıyor**
- [ ] Silme `204` + boş gövde; satır soft delete, program duruyor
- [ ] Doğrulama hatası **indeks** taşıyor, geçerli satır hatasız
- [ ] `"rule":"min"` — framework sınıf adı **yok** (D6)
- [ ] `npm run lint` yeşil
- [ ] 🎯 Tarayıcı: düzenlemeye devam → **PUT**, yeni kart **oluşmuyor**
- [ ] 🎯 Tarayıcı: hızlı yazmada **tek** kayıt oluşuyor
- [ ] 🎯 Tarayıcı: program adımlarında imleç kaybolmuyor
- [ ] 🎯 Tarayıcı: silme çalışıyor ve kalıcı

---

## 13. Otomatik testlerin kapsamadığı şeyler

Bu kılavuz olmadan görülemeyecekler:

| Konu | Neden teste konmadı |
|---|---|
| Autosave'in **hangi metodu** attığı | Tarayıcı davranışı; Network sekmesi gerekir |
| Yarış durumu (iki hızlı kaydetme) | Zamanlamaya bağlı — kararsız test olurdu (T12) |
| İmlecin kaybolup kaybolmadığı | React render davranışı; e2e araç gerektirir |
| `<input type="datetime-local">`'in biçimi reddedişi | Tarayıcının kendi davranışı |
| Sorgu sayısı (N+1) | Ölçüm; `tinker` ile elle bakılır |
| `APP_DEBUG=false` altındaki ayırt edilemezlik | Test `config()` ile taklit ediyor, gerçek ortam değil |

---

## 14. Bağlantılar

| İlgili | Nerede |
|---|---|
| Faz özeti ve kurallar | [`FAZ-3.md`](FAZ-3.md) |
| Otomatik testler | [`InvitationTest.md`](../tests/Feature/InvitationTest.md) |
| Sahiplik savunması | [`InvitationPolicy.md`](../app/Policies/InvitationPolicy.md) |
| Senkronizasyon algoritması | [`SyncTimelineEventsAction.md`](../app/Actions/Invitation/SyncTimelineEventsAction.md) |
| Hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` |
| Frontend uyarlaması | `davetkart-frontent/docs/rehber/src/` |
