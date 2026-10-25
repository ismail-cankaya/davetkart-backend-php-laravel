# `app/Http/Resources/GiftCodeQuoteResource.php` — önizleme yanıtı

> **Faz:** 11, adım 11.8

---

## 1. Yanıt

```json
{
  "data": {
    "benefit": "percent",
    "tier": "gold",
    "priceMinor": 39900,
    "discountMinor": 9975,
    "totalMinor": 29925,
    "currency": "TRY"
  }
}
```

## 2. 🔴 Para alanları neden `Minor`?

Prompt `discount` / `total` adlarını öneriyordu. API'de bugüne kadar hiç para alanı yoktu
(OrderResource tutar göndermiyor), yani izlenecek bir biçim de yoktu. Frontend'in kataloğu
(`types.ts → price`) ise **TL** cinsinden. `discount: 9975` alanı TL sanılırsa ekranda
"9.975 ₺ indirim" yazardı. Adın sonundaki `Minor`, veritabanındaki `amount_minor` kuralıyla
aynı: değer **kuruş**, tam sayı. Frontend `/ 100` ile gösterir.

`priceMinor` da eklendi: frontend toplamı kendi kataloğundan değil bu yanıttan çizsin.
Katalog ile sunucu fiyatı bir gün ayrışırsa kullanıcı yanlış toplam görmez.

## 3. Beyaz liste (C1): neler GİTMEZ?

| Alan | Neden gitmez |
|---|---|
| kodun `id`'si, `code_hash`, `code_hint` | İstemcinin işine yaramaz; kimlik ileride başka bir uçta tahmin edilebilir bir anahtar olurdu |
| `label` | Operatörün iç notu ("Instagram çekilişi") |
| `max_redemptions`, kalan kullanım | **Kampanyanın büyüklüğünü** ele verir. "3 hak kaldı" bir aciliyet mesajı gibi görünse de rakibe dağıtım hacmini söyler |
| `expires_at` | Bilgi değeri düşük. Gerekirse açılabilir; açmak geri almaktan kolay |

Test: `GiftCodePreviewTest::the_preview_never_leaks_the_code_internals` ham gövdede bunları arar.

## 4. Neden `JsonResource` ama arkasında model yok?

`GiftCodeQuote` bir DTO. Laravel'in `JsonResource`'u herhangi bir nesneyi sarar. Model olması
gerekmez. Dönüşümün "Resource'ta yapılır" kuralı (CLAUDE.md §1) nesnenin türüne bağlı değil.

---

## 🆕 Faz 13 (13.5) — yanıt genişledi

```json
{
  "benefit": "percent",
  "tier": "gold",
  "priceMinor": 39900,
  "promotion": { "kind": "first_publish", "percent": 60, "discountMinor": 23940, "endsAt": null },
  "discountMinor": 31920,
  "totalMinor": 7980,
  "giftDiscountMinor": 7980,
  "currency": "TRY"
}
```

- Faz 11 sözleşmesi korunuyor: `priceMinor − discountMinor = totalMinor`. Eski frontend
  bu yanıtı okuyunca yine doğru toplamı gösterir.
- `discountMinor` artık **toplam** indirim. Kodun kendi payı `giftDiscountMinor`.
- Ortak alanlar `PriceQuoteResource`'tan geliyor. Fiyat ucuyla önizleme aynı biçimi konuşur.
