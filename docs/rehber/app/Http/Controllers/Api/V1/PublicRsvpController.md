# `app/Http/Controllers/Api/V1/PublicRsvpController.php`

> **Kod dosyası:** `app/Http/Controllers/Api/V1/PublicRsvpController.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.10
> **Kardeş dosya:** [`PublicInvitationController.md`](PublicInvitationController.md)

---

## 1. Controller ne yapar, ne yapmaz?

`CLAUDE.md` §1: *"Controller'lar sadece gelen isteği ilgili Action'a
yönlendirmekten ve Resource dönmekten sorumludur (maksimum 3-8 satır).
İçerisinde `if` blokları veya iş mantığı bulunamaz."*

Bu dosya o kuralın en net örneği. Sistemin **en tehlikeli ucu** burada ve
gövdesinde tek bir `if` yok:

```php
$rsvp = $action->handle(
    $invitation,
    $request->rsvpAttributes(),
    (string) $request->ip(),
    $request->isHoneypotTripped(),
);
```

Dört karar, dört farklı katmanda:

| Karar | Nerede |
|---|---|
| Girdi geçerli mi? | `StoreRsvpRequest` (5.4) |
| Bot mu? Yayında mı? Süre doldu mu? Kota var mı? | `SubmitRsvpAction` (5.7) |
| Hangi alanlar dışarı çıkar? | `RsvpResource` (5.8) |
| Hata hangi HTTP koduna karşılık gelir? | `ApiExceptionRenderer` (Faz 1) |

Controller yalnızca **bağlar**.

---

## 2. 🔴 `string $invitation` — neden model değil?

```php
public function store(StoreRsvpRequest $request, string $invitation, ...)
```

Parametre `Invitation $invitation` yazılsaydı Laravel **route-model binding**
yapar ve davetiyeyi kendisi çözerdi. Kolay görünüyor ama iki şeyi bozardı:

**1. Görünürlük kararı Action'ın dışına kaçardı.** Route-model binding
`Invitation::findOrFail($id)` yapar — yani **yayınlanmamış** bir davetiyeyi de
bulur. `$invitation->status` kontrolünü sonra controller'a yazmak zorunda
kalırdık: bir `if`, ve kural iki yere düşerdi (P3/C3).

**2. Faz 4'ün cache dersi.** `PublicInvitationController`'da aynı karar
verilmişti: binding `SubstituteBindings` middleware'inde çalışır, yani
Action'dan **önce**. Orada bir `SELECT` açardı ve cache'i etkisizleştirirdi.

Burada cache yok ama ilke aynı: **modeli, onun görünür olup olmadığına karar
veren kod çözsün.**

---

## 3. Neden her durumda 201?

```php
return (new RsvpResource($rsvp))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
```

Bu satır iki farklı olayda çalışır:

| Olay | Yanıt | Veritabanı |
|---|---|---|
| Gerçek misafir | `201` + kayıt | ✅ satır yazıldı |
| Honeypot dolu (bot) | `201` + kayıt **gibi görünen** gövde | ❌ hiçbir şey yazılmadı |

Ayırt edilebilir olsaydı bot yazarı honeypot'u öğrenir ve savunma ölürdü
(5.7 §3). Bu yüzden controller **fark olduğunu bile bilmiyor** — Action ona her
iki durumda da bir `Rsvp` nesnesi veriyor.

🔴 Bunun bedeli: **yanıt hiçbir şey kanıtlamaz.** Testin veritabanına bakması
zorunlu (T14).

---

## 4. `201 Created` neden doğru kod?

| Kod | Ne der | Uygun mu |
|---|---|---|
| `200 OK` | "İstek başarılı" | Doğru ama eksik — yeni bir kaynak doğdu |
| **`201 Created`** ✅ | "Yeni kaynak oluşturuldu" | RFC 9110 §15.3.2 |
| `202 Accepted` | "Aldım, sonra işleyeceğim" | Yanlış: işlem senkron tamamlandı |
| `204 No Content` | "Tamam, gövde yok" | Yanlış: frontend kaydı geri istiyor |

`202`, `POST /auth/forgot-password` için doğru olurdu (`08` §3.1) çünkü orada
gerçekten "bilmiyorum, belki mail gitti" deniyor. Burada işlem tamamlandı.

Frontend'in `useRsvpStore.submitDraft()` metodu dönen kaydı listeye ekliyor,
yani gövde gerekli — `204` olamaz.

---

## 5. `(string) $request->ip()` — neden cast?

`Request::ip()` imzası `?string` döner: `null` olabilir (konsol istekleri, bazı
proxy yapılandırmaları). `SubmitRsvpAction::handle()` ise `string` bekliyor.

`null` gelirse `(string) null` → `''` olur ve hash `sha256('' . APP_KEY)`
üretir: tüm bu istekler **aynı** `ip_hash`'i paylaşır. Doğru davranış budur —
"IP bilinmiyor" bir grup oluşturur, hata değil.

`?string`'i olduğu gibi geçirmek PHPStan level 8'de hata verirdi; cast bilinçli
ve belgelenmiş bir karardır.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `Invitation $invitation` type-hint'i | Yayınlanmamış davetiye çözülür; görünürlük kararı dağılır |
| 2 | Honeypot'u burada kontrol etmek | Controller'a `if` girer; iş kararı yanlış katmana düşer |
| 3 | `200` döndürmek | Yeni kaynak doğduğu bilgisi kaybolur |
| 4 | Hata yakalayıp `response()->json(...)` üretmek | H10 ihlali; zarf biçimi ikinci bir yerden çıkar |
| 5 | `$request->validated()` ile Action'ı beslemek | camelCase adlar Action'a sızar (D4) |
| 6 | Rotayı `/api/rsvps` gibi public grubun **dışına** koymak | K12 fail-safe kırılır |

---

## 7. Sırada ne var?

**5.11 — rotalar ve hız sınırı.** Bu controller'ın hangi URL'e bağlandığı ve
önündeki `throttle:rsvp` kovası.

| İlgili | Nerede |
|---|---|
| İş kuralı | [`../../../Actions/Rsvp/SubmitRsvpAction.md`](../../../Actions/Rsvp/SubmitRsvpAction.md) |
| Doğrulama | [`../../Requests/Rsvp/StoreRsvpRequest.md`](../../Requests/Rsvp/StoreRsvpRequest.md) |
| Sözleşme | [`../../Resources/RsvpResource.md`](../../Resources/RsvpResource.md) |
