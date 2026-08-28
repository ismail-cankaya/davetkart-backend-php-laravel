# `app/Exceptions/RsvpDeadlinePassedException.php`

> **Kod dosyası:** `app/Exceptions/RsvpDeadlinePassedException.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.5
> **Önce oku:** [`HasErrorCode.md`](HasErrorCode.md) — arayüzün ne olduğu ve
> `errorParams()`'ın neden bir izin değil öneri olduğu orada.

---

## 1. Ne zaman fırlar?

`SubmitRsvpAction` (5.7) şu sırayla karar verir:

```
1. Davetiye var mı + yayında mı + LCV modülü açık mı  →  yoksa 404
2. rsvp_deadline geçti mi                             →  geçtiyse BU EXCEPTION (403)
3. Kota doldu mu                                      →  dolduysa 403 (quota)
4. Kaydet
```

---

## 2. 🔴 Neden 403? (404 ve 422 neden değil)

### Neden 404 değil?

**H7** *"sahiplik yoksa 404"* diyor ve Faz 3-4 boyunca "var mı yok mu"
sorusunu hep gizledik. Burada gizlemiyoruz — çünkü **gizlenecek bir şey yok**:

Misafir zaten davetiyeyi görebiliyor ve `PublicInvitationResource` LCV modülü
açıkken `rsvpDeadline` alanını gövdede **ona gönderiyor**. Son tarihin geçtiğini
söylemek, misafirin elindeki veriden zaten çıkarabileceği bir sonucu
doğrulamaktan ibaret.

🔴 **Bir kuralı uygulamak, gerekçesini kontrol etmeden kopyalamak değildir.**
H7'nin gerekçesi *"404 kaynağın varlığını gizler"*; burada kaynağın varlığı
zaten açık.

Üstelik 404 dönmek **kötü bir kullanıcı deneyimi** olurdu: misafir "link bozuk"
sanır, davetiye sahibini arar. 403 + `RSVP_DEADLINE_PASSED` kodu ile frontend
"Katılım bildirimi süresi doldu" diyebilir.

### Neden 422 değil?

`422` doğrulama hatasıdır: *"gönderdiğin veri bozuk."* Oysa burada veri
**kusursuz** olabilir. Reddedilen şey biçim değil **zaman**. `422` dönseydi
frontend'in `fields` beklemesi gerekirdi, oysa hangi alan hatalı? Hiçbiri.

`08` §4 tablosu bunu zaten kodlamıştı: **403 = kimlik var, işlem yasak.**

---

## 3. Neden kurucu parametresiz?

Son tarihi (`2026-09-01`) exception'a koyup yanıta yazabilirdik. Yazmıyoruz:

- Değer **zaten misafirde** — gövdede `rsvpDeadline` olarak gitti.
- `ErrorCode::RsvpDeadlinePassed->allowedParams()` **boş**; koysak bile
  `filterParams()` düşürürdü (H12). Yani hiç çalışmayan kod olurdu.

`getMessage()` içindeki İngilizce metin yalnızca **log** ve yerel `debug`
bloğu içindir (H3, H8) — hiçbir kullanıcıya gösterilmez (K20).

---

## 4. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Bu kontrolü `StoreRsvpRequest`'e koymak | 422 döner; ayrıca iş kuralı HTTP'siz test edilemez olur |
| 2 | `implements HasErrorCode` yazmayı unutmak | 500 döner (H11'in yeni kılığı) |
| 3 | Türkçe mesaj yazmak | K20 ihlali |
| 4 | Son tarihi karşılaştırırken `Carbon::now()` ile saat kıyaslamak | `rsvp_deadline` bir **tarihtir** (`date`), saat taşımaz — 5.7'de ayrıntısı var |

---

## 5. Sırada ne var?

Kardeş exception: [`RsvpQuotaExceededException.md`](RsvpQuotaExceededException.md)
