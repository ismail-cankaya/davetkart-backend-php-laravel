# `app/Http/Controllers/Api/V1/ShopierOsbController.php`

> **Faz:** 12 — adım 12.7 · **Rota:** `POST /api/public/payments/shopier/osb`
> **Kararlar:** K117 (paid kararı burada) · K69 (imza hatası 404) · K126 · **Test:** `tests/Feature/ShopierOsbTest.php`

## 1. Ne?

Shopier'in **Otomatik Sipariş Bildirimi**: ödeme tamamlanınca Shopier'in **sunucusu**
bu adrese `res` (base64 JSON) ve `hash` (HMAC) alanlarıyla POST atar. Tarayıcı kapansa,
kullanıcı geri tuşuna basmasa da gelir. Varsayılan `osb` modunda siparişi `paid`
yapan **tek** kanal budur.

## 2. Yanıtlar

| Durum | Yanıt | Neden |
|---|---|---|
| İmza doğru, eşlendi, işlendi | `200 success` | Shopier'in beklediği gövde |
| Tekrar (zaten `paid`) | `200 success` | "Aldım, bir daha gönderme" |
| Tutar uyuşmuyor | `200 success` + `critical` log | Tekrar göndermek tutarı değiştirmez; bir insan bakmalı |
| Eşlenemedi (başka bir satış) | `200 success` + `critical` log | Aynı |
| İmza yanlış / OSB kurulu değil | 404 (K69) | Makine ucu; ret bilgi sızdırmasın |
| Gövde anlamsız / üretimde test bildirimi | 400 | — |

🔴 `success` gövdesi **topluluk örneklerinden**; resmi dokümanla doğrulanmadı (§A-c).
Shopier başka bir gövde bekliyorsa bildirimi tekrarlamaya devam eder (zararsız: idempotan)
ve elle doğrulama adım 7'de görülür.

## 3. Neden Fake'in `/payments/webhook` ucu kullanılmadı?

O uç `PaymentGateway` arayüzünden çözülen sürücüyü çağırıyor ve 204 dönüyor. OSB'nin
yanıtı farklı (`200 success`) ve `PAYMENT_PROVIDER=fake` iken bile Shopier'in uçları
yanlış sürücüye gitmemeli. Bu controller `ShopierGateway`'i **doğrudan** istiyor:
sağlayıcıya özgü bir uç, sağlayıcıya özgü bir sürücü. Sır yoksa sürücü kurulamaz → 503.
