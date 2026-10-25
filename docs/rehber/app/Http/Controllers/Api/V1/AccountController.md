# `AccountController` — hesap ayarlarının okuma uçları

> **Faz:** 14, adım 14.4

| Metot | Uç | Action |
|---|---|---|
| `entitlements` | `GET /api/account/entitlements` | `SummarizeEntitlementsAction` |

**Policy neden yok?** Uç bir kimlik almıyor (`{order}` gibi). Okunan şey yalnızca isteği
yapanın kendi siparişleri ve sahiplik sorgunun kendisinde (`$user->orders()`). IDOR için bir
kimliğin istemciden gelmesi gerekir; burada gelmiyor. `OrderController::index` bunun yerine
`viewAny` soruyor, çünkü orada bir model koleksiyonu dönüyor; burada bir okuma modeli.

**SetEtag:** Ekran sekme değiştikçe yeniden ister; değişmediyse 304, gövde gönderilmez.
