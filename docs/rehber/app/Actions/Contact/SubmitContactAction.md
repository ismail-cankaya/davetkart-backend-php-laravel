# `app/Actions/Contact/SubmitContactAction.php`

> **Faz:** 8, dosya 8.14 · **Kurallar:** L2 · L3 · E7 · ders 26 · B4

---

## 1. `SubmitRsvpAction`'in sadelesmis kardesi

Faz 5'in bes katmanindan **ucu** devralindi, **ikisi bilerek alinmadi**:

| # | Katman | Burada | Neden |
|---|---|---|---|
| 1 | Honeypot | ✅ | Gonderen bir tarayici formu |
| 2 | "Hedef acik mi" | ❌ | Ortada **ust kaynak yok** — form bir davetiyeye ait degil; "yayinda mi / modul acik mi / son tarih" sorulari anlamsiz |
| 3 | Medya aidiyeti | ❌ | Dosya kabul etmiyor |
| 4 | Kota | ❌ | Kotanin cevaplayacagi bir soru yok; hacmi hiz siniri tutuyor |
| 5 | KVKK (`ip_hash`) | ✅ | `IpHasher` |

🔴 Bir katmani **kopyalamak yerine cikarmak**, savunmayi zayiflatmak degil
dogru boyutlandirmaktir: her katmanin cevapladigi bir soru olmali. Anlamsiz
bir katman, bakimda *"bu neden burada?"* diye silinir ve **gerekli olani da
beraberinde goturur.**

> 4. maddeye dikkat: bu **L3'un tersi degil**. L3 "hiz siniri kotanin yerine
> gecmez" der; burada gecmiyor, cunku ortada bir kota **sorusu yok**.
> Asistan ucunda ikisi de var ve ikisi de ayri.

---

## 2. Honeypot'ta neden sahte model uretilmiyor?

`SubmitRsvpAction` sahte, kaydedilmemis bir `Rsvp` uretiyordu: LCV ucu
**201 + govde** donduruyor ve bot yaniti gercek bir kayittan ayirt
edilememeliydi.

Bu uc **204** doner — govde yok, dolayisiyla ayirt edilecek bir sey de yok.
**Sessiz red burada bedavaya geliyor.**

---

## 3. 🔴 Bildirim yok — ve bu bir eksiklik degil, bir karar

Frontend'in `contact.ts` dosyasi soyle diyor:

> *"Mesajlar sunucu tarafinda kaydedilir ve **destek ekibine
> yonlendirilir**."*

Bugun oyle bir kanal **yok**: sistemde tek bir `Mailable`, tek bir bildirim
ayari yok. Buraya bir Job eklemek, `handle()` govdesi **yer tutucu** olan
bir sinif uretirdi — **ders 26** ve **K48**'in ayni gerekcesi.

Iki is acik karar olarak kaydedildi (`FAZ-8.md` §9):

1. Bildirim kanali secimi (kanal + dil + politika).
2. 🔴 Frontend'in yorumu **duzeltilmeli**: dokumanda verilen soz, kodda
   karsiligi yoksa **yalandir** (**B4**).

---

## 4. Kendin dene

```bash
curl -X POST http://localhost:8000/api/public/contact \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"Deniz","email":"d@example.test","subject":"pricing","message":"Merhaba"}' -i
# HTTP/1.1 204 No Content

# Honeypot dolu — YINE 204, ama satir yazilmaz:
curl -X POST http://localhost:8000/api/public/contact \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"Bot","email":"b@example.test","subject":"general","message":"spam","website":"http://x"}' -i
```

```php
// php artisan tinker
App\Models\ContactMessage::count();       // yalnizca birincisi
App\Models\ContactMessage::first()->ip_hash;   // ham IP DEGIL
```
