# `app/Http/Controllers/Api/V1/PricingController.php`

> **Faz:** 13, adım 13.6 · **Rotalar:** `public.plans.index`, `pricing.show`

| Metot | Rota | Auth | Önbellek |
|---|---|---|---|
| `publicCatalog()` | `GET /api/public/plans` | yok | ETag (herkes için aynı yanıt) |
| `forUser()` | `GET /api/pricing` | `auth:sanctum` | ETag (kişiye özel) |

## Neden iki uç?

"Token varsa kişisel, yoksa genel" diyen tek bir uç cazip görünür ama `/api/public/`
önekinin sözünü bozar: o önek "burada kimlik **bilinmez**" demektir (K12). Kişiye özel bir
yanıt public önek altında durursa bir gün bir önbellek (CDN) kişisel yanıtı herkese
dağıtabilir. İki uç, iki ayrı soru:

- **Misafir:** "Fiyat sayfasında ne yazsın?" Herkese aynı yanıt.
- **Kullanıcı:** "Ben ne ödeyeceğim?" İlk yayın indirimi burada.

Test `the_public_catalog_never_applies_the_first_publish_discount_even_with_a_token`
bunu kilitliyor: token gönderilse bile public uç kişisel cevap vermez.

## ETag

İki uç da `SetEtag` arkasında. Yanıt değişmediyse `304 Not Modified`. Kampanya açılınca
gövde değişir, ETag de değişir. Böylece önbellek kendi kendine tazelenir, ayrıca bir
"önbelleği sil" adımı gerekmez.
