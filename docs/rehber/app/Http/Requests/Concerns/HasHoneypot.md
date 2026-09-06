# `app/Http/Requests/Concerns/HasHoneypot.php`

> **Faz:** 8, dosya 8.7 (Faz 5'ten cikarildi) · **Kurallar:** L2 · C3 · H10

---

## 1. Honeypot nedir?

Forma insana **gorunmez** bir alan konur (CSS ile gizli). Insan doldurmaz
cunku goremez; otomatik doldurma yapan botlarin cogu **her** input'u
doldurur. Alan doluysa gonderen bir bottur.

Ad bilerek masum ve cazip: `website`. `honeypot` deseydik bot da anlardi.

---

## 2. 🔴 Neden trait'e cikarildi?

Faz 5'te tek bir formda duruyordu. Iletisim formu ayni tuzagi kuruyor ve
kopyalamak iki sorun uretirdi:

1. Alan adini bir gun degistirmek **iki dosyayi** hatirlamayi gerektirirdi;
   unutulan form sessizce savunmasiz kalirdi.
2. Ucuncu bir form geldiginde kopyalama bir **aliskanliga** donerdi.

**C3:** ayni sozlesmeyi ureten iki nokta tek yerden uretir.

> PHP 8.2+ trait sabitlerini destekliyor; `StoreRsvpRequest::HONEYPOT_FIELD`
> erisimi (RsvpTest:524) aynen calisiyor.

---

## 3. 🔴 Alana neden dogrulama kurali konmaz?

```php
// ❌ 'website' => ['prohibited']
```

Ihlal 422 doner ve zarfin `fields` bolumunde **alanin adi gorunur** — yani
tuzagin yerini saldirgana biz soyleriz. Savunma bir kez kullanilip olur.

**L2: bot tespiti sessizdir.** Reddin kendisi bilgi sizintisidir.

---

## 4. Trait karar vermez, olguyu bildirir

`isHoneypotTripped()` yalnizca "alan dolu mu?" sorusunu cevaplar. Ne
yapilacagi (sessizce basarili gorunmek) bir **is kuralidir** ve Action'a
aittir — FormRequest bicim bilir, is bilmez (**H10** ailesi).

Ayrim onemli: karar burada olsaydi 422 donmek cazip gelirdi ve §3'teki
hataya dusulurdu.

---

## 5. Bos gonderilen alan tuzak degildir

`ConvertEmptyStringsToNull` global middleware'i `''` degerini `null` yapar,
yani alani gorup bos birakan durust bir istemci elenmez. `ContactTest`'te
test olarak duruyor: `an_empty_honeypot_field_is_not_a_trap`.

---

## 6. Neyi kapatmiyor (B6)

| Acik | Aciklama |
|---|---|
| Hedefli bot | Formu bir kez inceleyen saldirgan alani atlar |
| Tarayici otomatik doldurmasi | Bazi sifre yoneticileri gizli alanlari doldurabilir → **yanlis pozitif** |
| Frontend tarafi | 🔴 Alan **formda yoksa** tuzak hic kurulmaz. `ContactPage` bugun bu alani render **etmiyor**; frontend borc listesinde |

Honeypot ucuz bir ilk elemedir, tek savunma degildir — arkasinda hiz siniri
durur (**L1**).
