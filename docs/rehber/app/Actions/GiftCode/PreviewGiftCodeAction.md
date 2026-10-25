# `app/Actions/GiftCode/PreviewGiftCodeAction.php` — kodu önizlemek

> **Faz:** 11, adım 11.8 · **Uç:** `POST /api/gift-codes/preview`
> **Test:** `tests/Feature/GiftCodePreviewTest.php`

---

## 1. Ne yapar?

Kullanıcı ödeme sayfasında "Hediye kodum var" alanına kodu yazıp **Uygula**'ya basar. Frontend
indirim satırını ve yeni toplamı göstermek için bu ucu çağırır. Hiçbir şey ayrılmaz:

| | Önizleme | Checkout |
|---|---|---|
| Satır kilidi | yok | `lockForUpdate` |
| Transaction | yok | var |
| Yazılan satır | yok | sipariş + kullanım |
| Kurallar | `QuoteGiftCodeAction` | `QuoteGiftCodeAction` (aynı) |

## 2. Kurallar neden aynı sınıftan?

Önizleme kendi kurallarını yazsaydı iki kopya bir gün ayrışırdı: önizleme "geçerli" der,
checkout "geçersiz" derdi ya da tersi. Kişi başı kontrol de önizlemede uygulanıyor: kodu
kullanmış biri Uygula'ya bastığında hemen "zaten kullandın" görür, ödeme ekranına kadar
gitmez.

## 3. 🔴 Önizleme bir söz değildir

Önizleme "geçerli" dedikten sonra, kullanıcı ödeme düğmesine basana kadar başka biri son hakkı
alabilir. Checkout o zaman `GIFT_CODE_INVALID` döner. Bu bir hata değil, kilitsiz okumanın
doğası. Alternatifi önizlemede kodu birkaç dakika "rezerve etmek" olurdu: yeni bir tablo, yeni
bir süre dolumu ve önizlemeyi bir kod tüketme saldırısına çevirmek (bir bot bütün kodları
önizleyip kilitlerdi). Karar checkout'ta verilir; frontend checkout'un 422'sini de göstermek
zorunda.

## 4. Neden auth'lu?

Önizleme kodun var olup olmadığını söyler, yani bir **kod tahmin aracı**dır. Auth'suz olsaydı
hız sınırının tek anahtarı IP olurdu. IP hem çok geniş (CGNAT arkasında binlerce abone) hem
çok ucuz (değiştirmek saatlik birkaç kuruş). Asistan ucuyla aynı gerekçe (Faz 8). Ayrıca
kişi başı kural kullanıcıyı bilmeden uygulanamaz.

## 5. Deneme

```powershell
$token = "..."   # giriş yanıtındaki token
curl -X POST http://127.0.0.1:8000/api/gift-codes/preview `
  -H "Authorization: Bearer $token" -H "Content-Type: application/json" `
  -d '{"code":"dugun-2026","tier":"gold"}'
# {"data":{"benefit":"percent","tier":"gold","priceMinor":39900,"discountMinor":7980,"totalMinor":31920,"currency":"TRY"}}
```

---

## 🆕 Faz 13 (13.5)

- Dönüş tipi `GiftCodeQuote` → `PriceQuote`. Önizleme artık **tam döküm** veriyor:
  liste fiyatı, otomatik indirim, kodun payı, toplam. Kod otomatik indirimden sonra kalan
  fiyata uygulanır (K133).
- `gift_code` yöntemi kapalıysa 403 `PAYMENT_METHOD_DISABLED` (`method: gift_code`), kod
  hiç aranmaz.
