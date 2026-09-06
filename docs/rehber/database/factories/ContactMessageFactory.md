# `database/factories/ContactMessageFactory.php`

> **Faz:** 8, dosya 8.13

---

## 1. Konu sabit, rastgele degil

`ContactSubject::General` sabit. Rastgele bir enum degeri, konu bazli bir
testi tesadufe baglardi — `OrderFactory`'nin plan/tutar icin verdigi ayni
karar.

---

## 2. `ip_hash` neden sahte ama dogru genislikte?

```php
'ip_hash' => str_repeat('a', 64),
```

Fabrika **kisisel veri uretmez**: gercek bir IP'den turetilmis bir ozet,
test verisinde bile gereksizdir. Genislik gercek degerle ayni (64) ki kolon
kisitlari gercekci kalsin.

> Not: `ip_hash` modelin `#[Fillable]` listesinde **yok**, ama fabrikalar
> `Model::unguarded()` icinde calistigi icin bu deger yine de yazilir.
> Iliskiden `create()` cagrildiginda (`$parent->children()->create()`) beyaz
> liste **gecerlidir** — Faz 6'da `MediaTest` bu yuzden `forceCreate()`
> kullanmisti.
