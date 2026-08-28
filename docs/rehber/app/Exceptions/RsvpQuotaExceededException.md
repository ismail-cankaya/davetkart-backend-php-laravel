# `app/Exceptions/RsvpQuotaExceededException.php`

> **Kod dosyası:** `app/Exceptions/RsvpQuotaExceededException.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.5
> **Önce oku:** [`HasErrorCode.md`](HasErrorCode.md)

---

## 1. Neden 403, neden 429 değil?

Bu ayrım Faz 1'de **K28** olarak karara bağlanmıştı ve LCV modülünün en ince
tasarım noktalarından biri:

| | 429 Too Many Requests | 403 Forbidden |
|---|---|---|
| Ne der? | "Çok hızlısın, **yavaşla**" | "Bu işlem sana **kapalı**" |
| Bekleyerek çözülür mü? | ✅ Evet | ❌ Hayır |
| `Retry-After` anlamlı mı? | ✅ | ❌ |

Kotamız bir **kapasite** sınırıdır: davetiyenin planı 100 misafir diyorsa,
101. misafir bir saat bekleyince içeri giremez. `429` dönseydi frontend
`Retry-After` gösterir ve misafire **yalan** söylerdik.

> Aynı davetiyeye gerçek bir **hız** sınırı da var (5.8: dakikada 10 istek/IP,
> saatte 60 istek/davetiye) ve o **429** döner. İkisi farklı katmandır:
> hız sınırı isteği **saymaya** bakar, kota **misafir sayısına**.

---

## 2. 🔴 Kurucu neden parametre almıyor?

`08` §3.4'teki `params` beyaz liste tablosu şunu diyor:

| Parametre | Kime |
|---|---|
| `remaining`, `limit` | 🔴 **Sadece davetiye sahibine** |

Bu exception'ın Faz 5'teki **tek fırlatma yeri** anonim misafirin gönderim
ucudur. Yani `remaining`/`limit` hiçbir koşulda dışarı çıkmamalı.

Kuralı üç şekilde koruyabilirdik:

1. **Yorumla:** "buraya params koymayın" diye not düşmek → biri okumaz.
2. **Çağrı yerinde:** `throw new RsvpQuotaExceededException([])` → biri bir gün
   dolu dizi geçer.
3. **Yapıyla:** sınıf o değerleri **taşıyamaz** hâle getirilir → imkânsız olur.

Üçüncüsü seçildi. Bu, projede daha önce kanıtlanmış bir desen:
`InvalidCredentialsException`'ın kurucusu da **bilerek** parametresizdir, çünkü
"kullanıcı yok" ile "parola yanlış" ayrımını taşıyabilecek tek kanal oydu (A2).

**Ders:** bir güvenlik kuralını hatırlanmaya değil, **sınıfın şekline** bağla.

---

## 3. Peki `ErrorCode` neden `remaining`/`limit`'e izin veriyor?

```php
self::RsvpQuotaExceeded => ['remaining', 'limit'],
```

Bu satır Faz 1'de yazıldı ve **kalıyor**. Beyaz liste bir *tavan*, bir *taban*
değil: "bu kod en fazla şunları verebilir" der, "vermek zorundadır" demez.

Faz 7'de sahibe dönük bir kota ucu doğduğunda (örneğin panelde "kotanızın 12'si
kaldı") ikinci bir adlandırılmış kurucu eklenecek:

```php
RsvpQuotaExceededException::forOwner($remaining, $limit)   // Faz 7
```

Bugün eklenmedi çünkü **çağıranı olmayan kod, doğru olduğu varsayılan koddur**
(ders 26) — Faz 4'te `InvitationPublished` olayı tam olarak bu sebeple
`InvitationChanged`'e dönüşmüştü (K48).

> **B6 gereği, bu savunmanın *kapatmadığı* şey:** kota reddi misafire "kota
> doldu" bilgisini **verir**. Yani bir saldırgan tek bir istekle davetiyenin
> dolu olduğunu öğrenebilir. Gizlemediğimiz şey bu; gizlediğimiz şey
> **kaç kişilik** olduğu ve **kaç kişi kaldığı**.

---

## 4. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `429` döndürmek | Misafire "bekle, düzelir" denmiş olur — yalan |
| 2 | Kurucuya `$remaining` eklemek | Anonim misafire iç sayaç sızar (H9) |
| 3 | Kota kontrolünü `COUNT(*)` ile yapmak | 100 kayıt × 4 kişi = 400 misafir geçer (`docs/09` §Faz 5) |
| 4 | Kotayı FormRequest'te kontrol etmek | 422 döner; kapasite reddi doğrulama hatası değildir |
| 5 | Kota kontrolü ile kayıt arasına transaction koymamak | Eşzamanlı iki istek kotayı birlikte aşabilir — 5.7'de ele alınıyor |

---

## 5. Sırada ne var?

**5.6 — `RsvpQuotaResolver`:** limitin nereden geldiği.
Kardeş exception: [`RsvpDeadlinePassedException.md`](RsvpDeadlinePassedException.md)
