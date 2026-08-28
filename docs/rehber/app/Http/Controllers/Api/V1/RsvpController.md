# `app/Http/Controllers/Api/V1/RsvpController.php`

> **Kod dosyası:** `app/Http/Controllers/Api/V1/RsvpController.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.10
> **Kardeş dosya:** [`InvitationController.md`](InvitationController.md)

---

## 1. Sahibin paneli — sistemin en sık istenen auth'lu ucu

`config('davetkart.rsvp.poll_interval_seconds')` = **15**. Yani davetiye sahibi
paneli açık bıraktığı sürece, dakikada 4 kez `GET /api/invitations/{id}/rsvps`
çağrılıyor.

Bir düğün günü, 3 saat açık kalan bir panel = **720 istek**. Ve verinin çoğu
zaman hiç değişmiyor.

Çözüm Faz 4'te zaten yazılmıştı: **`SetEtag` middleware'i** (K46).

> **K46'nın karşılığı burada alındı.** Faz 4'te ETag'i controller içine değil
> ayrı bir middleware'e koymuştuk ve gerekçesi şuydu: *"Faz 5'in LCV polling
> ucu aynı katmanı yeniden kullanacak (C3)."* Bugün tek satır rota
> yapılandırmasıyla kullanıyoruz — yeni tek satır kod yazmadan.

Veri değişmediyse `304 Not Modified` döner: gövde **hiç gönderilmez**.

> **B6 — neyi kapatmıyor:** `304` dönerken bile gövde bir kez **üretilir**
> (sorgu koşar, Resource çalışır, hash alınır). ETag **ağı** kurtarır, CPU'yu
> ve veritabanını değil. LCV listesi cache'lenmiyor — çünkü sık değişiyor ve
> yalnızca tek bir kullanıcı okuyor.

---

## 2. `index()` — sahiplik neden iki kez korunuyor?

```php
Gate::authorize('view', $invitation);

return RsvpResource::collection(
    $invitation->rsvps()->latest()->get(),
);
```

İki ayrı savunma var ve ikisi de gerekli:

1. **`Gate::authorize`** — kararı verir. Başkasının davetiyesiyse
   `AuthorizationException` → `404` (H7).
2. **`$invitation->rsvps()`** — sorgunun **kapsamı**. Sorgu zaten yalnızca bu
   davetiyenin yanıtlarını görüyor.

**P3** ikincisinin neden şart olduğunu söylüyor: *koleksiyon uçlarında sahiplik
Policy ile değil sorgu ile korunur; filtreyi unutmak gözden kaçmaz olmalı.*

Düşün: `Rsvp::latest()->get()` yazsaydık ve `Gate::authorize` satırını bir gün
biri silseydi — **tüm platformun LCV yanıtları** herkese açılırdı. `$invitation->rsvps()`
yazıldığında böyle bir kaza mümkün değil: yanlışlıkla "hepsini getir" yazmak
için ilişkiyi terk etmek gerekir, ki o gözden kaçmaz.

### `->latest()` neden burada, ilişkide değil?

`Invitation::rsvps()` ilişkisinde **sıralama yok** (5.3 §5). Sıra burada
veriliyor çünkü bu bir **sunum tercihi**: panel en yeniyi üstte ister, kota
sorgusu hiç sıralamaz.

`latest()` = `orderBy('created_at', 'desc')`.

---

## 3. `destroy()` — `loadMissing()` neden şart?

```php
Gate::authorize('delete', $rsvp->loadMissing('invitation'));
```

`RsvpPolicy::delete()` kararı `$rsvp->invitation`'a bakarak veriyor (P1
gereği, kural kopyalanmıyor). Route-model binding ise yalnızca `Rsvp`'yi
yükler — ilişki yüklenmez.

Faz 0'da **S2** ile `preventLazyLoading` açılmıştı: geliştirmede örtük ilişki
yüklemesi **exception fırlatır**. Yani `loadMissing()` olmadan bu satır yerelde
patlardı.

`loadMissing()` **açık** bir yüklemedir; yasaklanan örtük olanıdır. İkisinin
farkı niyet: biri "bunu istiyorum" der, diğeri "bir şekilde gelsin" der.

**Neden policy kendisi yüklemiyor?** Yükleyebilirdi. Ama o zaman policy sessizce
sorgu açan bir sınıf olurdu ve bir listede N+1 fark edilmeden çoğalırdı.
Yükleme kararını çağırana bırakmak maliyeti **görünür** kılar — Faz 3'te
`whenLoaded()` reddedilirken de aynı ilke işlemişti.

---

## 4. `noContent()` — neden gövde yok?

```php
$rsvp->delete();

return response()->noContent();      // 204
```

`204 No Content`: "işlem başarılı, söyleyecek bir şeyim yok." Silinen kaydı geri
döndürmek anlamsız olurdu — istemcide zaten var, ve zaten silmek istedi.

Frontend `useRsvpStore.deleteRsvp()` **iyimser silme** yapıyor: önce listeden
çıkarıyor, sonra isteği atıyor, hata gelirse geri koyuyor. Yani yanıt gövdesini
zaten kullanmıyor.

`InvitationController::destroy()` de aynı deseni kullanıyor — sözleşme tutarlı.

### Bu bir *soft delete* mi?

Hayır. `Invitation` `SoftDeletes` kullanıyor, `Rsvp` **kullanmıyor**: silme
gerçek. Gerekçe 5.2'de: sahip moderasyon için siliyor (spam, tekrar kayıt) ve
"geri al" diye bir akış yok. Ayrıca misafirin kişisel verisini gerçekten silmek
KVKK açısından da doğru yön.

---

## 5. `AnonymousResourceCollection` nedir?

`RsvpResource::collection(...)` tek tek Resource'lardan oluşan bir koleksiyon
döndürür ve Laravel onu otomatik `{ "data": [ ... ] }` zarfına sarar.

**K11**: auth uçları dışındaki her yanıt `{data: ...}` zarfıyla döner. Frontend
`unwrapEnvelope()` ile açıyor (`src/services/rsvps.ts`).

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `Rsvp::latest()->get()` + sadece Gate | Gate silinirse tüm platformun verisi açılır (P3) |
| 2 | `loadMissing()` unutmak | Yerelde `LazyLoadingViolationException` |
| 3 | `Gate::authorize('view', $rsvp)` (davetiye yerine) | Ekstra sorgu; ayrıca liste ucunda tek tek yetki sorulmuş olur |
| 4 | `->latest()`'i ilişkiye gömmek | Kota sorgusu da sıralar — gereksiz maliyet |
| 5 | `destroy()`'dan silinen kaydı döndürmek | Anlamsız gövde; sözleşme kardeş uçlardan ayrışır |
| 6 | ETag'i controller içinde hesaplamak | K46 ihlali; aynı mantık iki yerde olur |
| 7 | Liste ucuna `throttle:rsvp` koymak | O kova **yazma** için; okuma polling'i 15 sn'de bir gelir ve 429 yer |

---

## 7. Kendin dene

```powershell
$token = "<login'den gelen token>"
$id    = "<davetiye-ulid>"

# 1) Liste
curl.exe -s -H "Authorization: Bearer $token" `
  "http://localhost:8000/api/invitations/$id/rsvps"

# 2) ETag: ilk yanıttaki ETag başlığını al, ikinci isteğe koy
curl.exe -s -D - -o NUL -H "Authorization: Bearer $token" `
  "http://localhost:8000/api/invitations/$id/rsvps"
# ETag: "a1b2c3..."

curl.exe -s -o NUL -w "%{http_code}`n" -H "Authorization: Bearer $token" `
  -H 'If-None-Match: "a1b2c3..."' `
  "http://localhost:8000/api/invitations/$id/rsvps"
# 304  ← beklenen

# 3) 🔴 IDOR: başka bir hesabın token'ıyla aynı davetiye
# 404 dönmeli — 403 DEĞİL
```

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Polling** | İstemcinin belirli aralıklarla sunucuya sorması |
| **ETag** | Gövdenin parmak izi; koşullu istekte kullanılır |
| **`304 Not Modified`** | "Elindeki sürüm hâlâ geçerli, gövde göndermiyorum" |
| **Route-model binding** | URL parametresinden modeli otomatik çözme |
| **Lazy loading** | İlişkinin erişim anında sorguyla çekilmesi |
| **İyimser güncelleme** | Sunucu onaylamadan arayüzü güncelleme, hatada geri alma |
| **Soft delete** | Satırı silmeyip `deleted_at` damgalamak |

---

## 9. Sırada ne var?

**5.11 — rotalar ve hız sınırı.**

| İlgili | Nerede |
|---|---|
| Yetki | [`../../../Policies/RsvpPolicy.md`](../../../Policies/RsvpPolicy.md) |
| ETag katmanı | [`../../Middleware/SetEtag.md`](../../Middleware/SetEtag.md) |
| Kardeş controller | [`InvitationController.md`](InvitationController.md) |
