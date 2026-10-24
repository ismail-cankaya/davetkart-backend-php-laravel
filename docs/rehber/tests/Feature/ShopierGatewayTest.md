# `tests/Feature/ShopierGatewayTest.php`

> **Faz:** 12 — adım 12.6 · **Sınadığı:** [`ShopierGateway`](../../app/Services/Payment/ShopierGateway.md)

## Ne sınanıyor, ne sınanmıyor?

Sürücü `new ShopierGateway` ile doğrudan kuruluyor; HTTP ucu, rota, controller yok.
Uçlar `ShopierCallbackTest` ve `ShopierOsbTest`'te. Bu dosyanın sorusu: *"sürücü
doğru formu üretiyor, doğru isteği kabul edip yanlışı reddediyor mu?"*

🔴 **Gerçek Shopier'e istek yok.** Sürücü zaten ağa çıkmıyor (formu tarayıcı
gönderir); `TestCase::setUp()`'taki `Http::preventStrayRequests()` yine de bir güvenlik
ağı. Sırlar sahte (`test-secret`), imzalar testte hesaplanıyor.

## Gruplar

| Grup | Testler | Kanıtladığı |
|---|---|---|
| `startCheckout` | 3 | Tutar **satırdan** (`19950 → "199.50"`); imza formdaki metinlerle yeniden hesaplanınca tutuyor; sır formda yok; `random_nr` her seferinde yeni |
| Kurulum (K123) | 7 | Sır boş/boşluk/yok, `osb` modunda OSB kimliği yok, bilinmeyen mod → `PaymentProviderException`; `callback` modu OSB'siz kurulabiliyor |
| `parseCallback` | 8 | `osb` modunda **bildirim yok**; `callback` modunda var; yanlış imza → 404 istisnası; eksik alan / bilinmeyen durum / ULID olmayan kimlik → 400 |
| `parseOsb` | 5 | Tutar ve birim bildirime giriyor; eşlenemeyen OSB → `null` + `critical`; gövdesi değiştirilmiş OSB → reddedildi; okunamayan fiyat → 400; OSB kurulu değilse → reddedildi |

## İki ince nokta

**İmza testi formülü kopyalıyor mu?** `checkout_returns_a_signed_form…` imzayı
`ShopierSignature::forPayment` ile yeniden hesaplıyor; yani formülün **kendisini**
değil, sürücünün formüle **doğru metinleri** verdiğini sınıyor. Formülün doğruluğu
`ShopierSignatureTest`'in bağımsız vektörlerinde.

**`a_non_ulid_order_id_is_refused_before_the_signature`**: `../../admin` gibi bir değer
imza kontrolüne bile ulaşmıyor. Bu değer ileride yönlendirme adresine (`?order=`)
girecekti; sınırda kesmek, aşağıdaki her katmanı ondan korur.
