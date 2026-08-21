# `app/Http/Requests/Invitation/UpdateInvitationRequest.php`

> **Kod dosyası:** `app/Http/Requests/Invitation/UpdateInvitationRequest.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.8 (3/3)
> **Ortak kurallar:** [`InvitationRequest.md`](InvitationRequest.md) — asıl anlatım orada

---

## 1. Tek fark: `sometimes` + `required` birlikte

```php
final class UpdateInvitationRequest extends InvitationRequest
{
    /** @return list<string> */
    protected function catalogPresence(): array
    {
        return ['sometimes', 'required'];
    }
}
```

Üretilen kural:

```php
'invitation.categoryId' => ['sometimes', 'required', 'string', 'max:32'],
```

İlk bakışta çelişkili görünür: "bazen" ve "zorunlu" aynı anda nasıl olur?

---

## 2. İkisi farklı soruları cevaplıyor

| Kural | Sorusu |
|---|---|
| `sometimes` | Alan istekte **var mı**? Yoksa kalan kuralları hiç çalıştırma. |
| `required` | Alan var — **dolu mu**? |

Birlikte yazınca üç durum netleşir:

```
categoryId istekte YOK          → gecer   (kismi guncelleme, kolon aynen kalir)
categoryId var, "dugun"         → gecer
categoryId var, null veya ""    → 422     ✅ engellenen durum
```

Yalnızca `sometimes` yazsaydık üçüncü durum geçerdi ve `category_id` kolonuna
`null` yazılmaya çalışılırdı — kolon **NOT NULL**, sonuç 500.

Yalnızca `required` yazsaydık kısmi güncelleme imkânsız olurdu: her autosave
isteğinin üç katalog anahtarını da taşıması gerekirdi.

---

## 3. Neden kısmi güncelleme destekliyoruz?

Frontend şu an tasarımın **tamamını** gönderiyor, yani pratikte üç alan da her
istekte var. Ama sözleşmeyi buna bağlamak gereksiz bir kısıt olurdu:

- Editör yarın yalnızca değişen alanları göndermeye başlayabilir (daha az veri,
  daha hızlı autosave)
- Bir mobil istemci farklı davranabilir
- Tek bir alanı güncelleyen bir yönetim ekranı yazılabilir

`sometimes` bu esnekliği **bedelsiz** verir: bugünkü istemciyi kırmaz, yarınkine
izin verir.

> **İlke:** API sözleşmesi, bugünkü tek istemcinin davranışını değil,
> **anlamlı olan davranış kümesini** tarif etmelidir.

---

## 4. `PUT` mu `PATCH` mi?

Tam anlamıyla REST'te `PUT` "kaynağı bütünüyle değiştir", `PATCH` "kısmen
güncelle" demektir. Kurallarımız kısmi güncellemeye izin verdiğine göre `PATCH`
daha doğru görünebilir.

`PUT` kullanıyoruz, çünkü:

- `03-MIMARI-PLAN.md` §4.3 endpoint tablosu `PUT` diyor
- Frontend'in HTTP istemcisi (`api.ts`) `PUT` ile çağıracak
- Laravel rota tanımı ikisini birden kabul edebilir (`Route::match`)

Ayrım pratikte önemsiz; sözleşmenin **tutarlı** olması, akademik olarak doğru
olmasından önemlidir. Değiştirmek istersek maliyeti tek satır — ama iki tarafta
birden.

---

## 5. Sırada ne var?

**3.9 — `InvitationResource` ailesi.** İstek tarafı bitti; şimdi yanıt tarafı ve
🔴 **C1** sınavı: kapalı bir modülün verisi (`iban`) yanıta sızmamalı.
