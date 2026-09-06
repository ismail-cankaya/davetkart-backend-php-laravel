# `database/factories/AssistantUsageFactory.php`

> **Faz:** 8, dosya 8.8

---

## 1. Rastgelelik yok

Kota testleri *"kac mesaj kalmisti"* sorusuna bakiyor. Rastgele bir
baslangic sayaci, testi **tesadufe** baglardi. `OrderFactory` ve
`InvitationFactory` ayni ilkeyi tasiyor: rastgelelik yalnizca **davranisi
etkilemeyen** alanda kullanilir — burada oyle bir alan yok.

---

## 2. Varsayilan: bugun, sifir mesaj

Bir fabrikanin varsayilani, testin **acikca istemesi gereken** seyi bedava
vermemelidir. `spent()` yazilmadan kota dolmus sayilsaydi, "kota calisiyor"
testleri sizinti testine donerdi.

---

## 3. Iki durum (state)

```php
AssistantUsage::factory()->on('2026-09-06')->spent(30)->create(['user_id' => $u->id]);
```

| Durum | Ne icin |
|---|---|
| `on(string $date)` | "Dunku kota bugunu etkilemez" testi |
| `spent(int $count)` | "Kota doldu" senaryosunu tek satirda kurmak |

Ikisi de `state()` uzerinden; fabrikanin `definition()`'i tek dogruluk
kaynagi olarak kaliyor.

---

## 4. Donus tipi neden yazilmadi?

`definition()` icin `@return` **bilerek** yok: ust siniftan devralinir ve
tip **daha iyi** olur (**ders 19** — kovaryans docblock kopyalayarak
bozulur).
