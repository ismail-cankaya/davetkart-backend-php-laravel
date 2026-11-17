# `app/Http/Requests/GiftCode/PreviewGiftCodeRequest.php`

> **Faz:** 11, adım 11.8

---

| Alan | Kural | Neden |
|---|---|---|
| `code` | `required`, `string`, `max:64` | Uzunluk sınırı savunma; biçim regex'i **yok** (K113) |
| `tier` | `required`, `string`, `in:standart,gold,elit` | Liste enum'dan (K39). `Rule::enum` değil düz `in:`, çünkü kural nesnesinin sınıf adı hata zarfına sızar (`StoreCheckoutRequest.md`, Faz 7 D6) |

`authorize()` `true` döner. Uç `auth:sanctum` arkasında ve önizleme bir kaynağa ait değil:
sahiplik sorusu yok.

Neden `tier` zorunlu? İndirim plana bağlı (`free/gold` kodu yalnızca Gold'da geçer, yüzde
indirim planın fiyatından hesaplanır). Plansız bir önizleme "ne kadar indirim" sorusunu
cevaplayamazdı.

Erişimciler (`code()`, `tier()`) doğrulanmış veriden okur; Action'a ham `$request->input()`
gitmez.

---

## 🆕 Faz 18.3 (`SEC-01` · K163) — `invitationId`

```php
'invitationId' => ['sometimes', 'nullable', 'string', 'ulid'],
```

Kodun uygulanacağı taban "otomatik indirimden sonra kalan fiyat"tır (K133) ve ilk yayın indirimi
artık hedefe bağlı (K159). Önizleme hedefi bilmezse tabanı yanlış hesaplar. Alan isteğe bağlı:
yoksa davetiyesiz (paket). Sahiplik sınanmaz; gerekçesi
[`ShowPricingRequest.md`](../Pricing/ShowPricingRequest.md) §3 ile aynı. Kimlik burada gövdede,
çünkü uç zaten `POST` ve `code`, `tier` de gövdede.
