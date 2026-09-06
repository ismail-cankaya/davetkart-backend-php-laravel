# `app/Enums/ContactSubject.php`

> **Faz:** 8, dosya 8.12 · **Kurallar:** K21 · K39 · ders 26

---

## 1. Degerler frontend sozlesmesidir

`davetkart-frontent/src/services/contact.ts`:

```ts
export type ContactSubject =
  | 'general' | 'support' | 'pricing' | 'partnership' | 'kvkk';
```

Enum'un `value`'lari **birebir** bunlar. Biri degisirse form sessizce 422
almaya baslar ve kullanici sebebini goremez. `ContactTest` bunu test olarak
sabitliyor: `the_subject_values_match_the_frontend_contract`.

---

## 2. Neden Ingilizce?

**K21:** veritabani tek dil konusur, ceviri frontend'in isidir.
`'Genel Soru'` gibi bir **gosterim metni** asla veri degeri olamaz — o
durumda arayuzun dilini degistirmek **veritabanini** degistirmek olurdu.
`RsvpStatus` ve `OrderStatus` ayni karari tasiyor.

---

## 3. 🔴 `label()` metodu neden yok?

Cazip: *"konu basligini Turkce'ye cevirelim."* Uc gerekceyle reddedildi:

1. Gosterim metni backend'in isi degil (**K20/K21**).
2. `RsvpStatus` ve `OrderStatus`'te de yok — desen kirilmasin.
3. **Ders 26'nin hazir ornegi elimizde:** `SubscriptionTier::label()` Faz
   0'da yazildi ve **sekiz faz boyunca** hicbir yerden cagrilmadi. Bugun
   hala acik bir karar olarak bekliyor (silinmeli mi?).

Yazilmayan kod, silinmesi gerekmeyen koddur.

---

## 4. `values()` tek kaynak

Hem migration'daki `CHECK` kisiti hem `ContactRequest`'teki `in:` kurali
buradan beslenir. Elle yazilsalardi enum'a altinci bir konu eklendigi gun
**ikisi de sessizce eskirdi** (**K39**).

```php
$allowed = "'".implode("', '", ContactSubject::values())."'";
```

---

## 5. `kvkk` neden bir konu?

KVKK basvurulari (veri sahibi haklari: erisim, duzeltme, silme) yasal olarak
**takip edilebilir** olmali ve digerlerinden ayirt edilebilmeli. Ayri bir
konu degeri, ileride bir SLA veya raporlama gerektiginde tek `WHERE` ile
ayrilmasini saglar.
