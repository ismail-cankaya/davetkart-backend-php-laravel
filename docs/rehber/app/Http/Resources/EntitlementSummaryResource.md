# `EntitlementSummaryResource` — "Planlarım" sözleşmesi

> **Faz:** 14, adım 14.4 · **Karar:** K140 · **Uç:** `GET /api/account/entitlements`

## 1. Biçim

```json
{ "data": {
  "tiers": [
    { "tier": "gold", "available": 1, "inUse": 0, "refundRequested": 0,
      "pending": 0, "consumed": 0, "refunded": 1 }
  ],
  "totalAvailable": 1,
  "rights": [
    { "orderId": "01j…", "tier": "gold", "state": "available",
      "createdAt": "…", "paidAt": "…", "totalMinor": 39900, "currency": "TRY",
      "invitation": null,
      "refund": { "eligible": true, "blocker": null, "windowEndsAt": "…", "request": null } }
  ]
} }
```

- **Üç plan her zaman** döner. Frontend "hiç Elit'i yok" durumunu ayrıca kodlamaz.
- `state` ve `refund.blocker` değerleri enum değerleridir (`in_use`, `window_closed`); kova
  **adları** (`inUse`) ise Resource'ta camelCase'e çevrilir. Değer bir sözleşme sabiti, alan
  adı bir JSON anahtarı — CLAUDE.md §1'in "dönüşüm Resource'ta" kuralı anahtarlar içindir.
- `invitation.deleted`: yanmış hakta davetiye çöpte. Kalıcı silinmişse `invitation: null`.

## 2. 🔴 Beyaz liste: neler gitmiyor?

| Alan | Neden |
|---|---|
| `provider`, `provider_ref`, `provider_payment_id` | Sağlayıcının iç kimliği (OrderResource'la aynı gerekçe) |
| `user_id` | İstemci zaten kendisi |
| İndirim dökümü | "Siparişlerim" ekranı `GET /api/orders`'tan okur; bu uç hak sorusuna cevap verir |
| Davetiyenin içeriği | Hak listesi bir düzenleyici değil; kimlik + başlık + iki tarih yeter |

Test: `provider_identifiers_never_leave_the_server`.

## 3. `@property EntitlementSummary $resource`

Resource bir model sarmıyor, saf veri sarıyor (PricingResource ile aynı). `@mixin` yerine
`@property` yazılır: tip denetimi `$this->resource`'un ne olduğunu bilir.
