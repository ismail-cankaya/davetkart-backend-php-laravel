# `app/Http/Requests/Contact/ContactRequest.php`

> **Faz:** 8, dosya 8.14 · **Kurallar:** L1 · L2 · D4 · D6 · E6

---

## 1. Dorduncu auth'suz yazma yolu

| # | Yol | Faz | En ucuz katmani |
|---|---|---|---|
| 1 | LCV gonderimi | 5 | Honeypot |
| 2 | Misafir medyasi | 6 | Hiz siniri (honeypot **imkansiz**) |
| 3 | Odeme webhook'u | 7 | Imza (honeypot **anlamsiz**) |
| 4 | **Iletisim formu** | **8** | **Honeypot** |

Webhook'tan farki, honeypot'un **mumkun** olmasi: orada gonderen bir
makineydi ve gorunmez bir alan diye bir sey yoktu; burada gonderen bir
tarayici formu.

Katmanlar (**L1**, ucuzdan pahaliya):

```
0. throttle:contact  ->  IP basina 3/dk ve 10/saat
1. honeypot          ->  bot SESSIZCE yutulur, veritabanina HIC gidilmez
2. bicim dogrulama   ->  burasi
3. yazma + KVKK      ->  SubmitContactAction
```

---

## 2. 🔴 D6: `Rule::enum()` degil `'in:'`

```php
'subject' => ['required', 'string', 'in:'.implode(',', ContactSubject::values())],
```

Kural **nesnesi** kullanilsaydi `$validator->failed()` anahtari **sinif
adi** olurdu ve hata zarfina

```json
{"rule": "illuminate_validation_rules_enum"}
```

diye sizardi. Faz 3'te `Password::min(8)` ile yasandi (**D6**), Faz 7'de
`Rule::enum` ile kod yazilmadan yakalandi (**ders 54**), Faz 8'de ucuncu
kez. **Kural adi sozlesmenin parcasidir** — frontend ceviri anahtarini ona
gore yazar.

---

## 3. `email` kurali neyi dogrulamiyor?

**Bicimi** dogrular, adresin **var oldugunu** degil. Dogrulamanin dogru
siniri budur: var olan bir adres olup olmadigi ancak bir posta gonderilerek
ogrenilir ve bu uc posta gondermez.

---

## 4. `COLUMN_MAP` adlar ayniyken neden yaziliyor?

Isi **eslemek** degil **beyaz listelemek**. Bugun dort ad birebir ayni;
yarin kurallara bir alan eklendiginde (ornegin bir onay kutusu) o alan
kolona **sessizce sizmasin**. `StoreRsvpRequest`'teki ayni gerekce (**D4**).

---

## 5. Sik yapilan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Honeypot alanina kural koymak | Tuzagin yeri saldirgana soylenir (L2) |
| 2 | `Rule::enum()` kullanmak | Framework sinif adi hata zarfina sizar (D6) |
| 3 | `validated()`'i dogrudan `create()`'e vermek | Yeni bir kural kolona sessizce sizar |
| 4 | Mesaj sinirini kodda sabitlemek | Is tercihi kod degisikligi ister (E6) |
