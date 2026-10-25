# `refunds:list` · `refunds:approve` · `refunds:reject`

> **Faz:** 14, adım 14.6 · **Karar:** K141
> **Dosyalar:** `ListRefundRequests`, `ApproveRefundRequest`, `RejectRefundRequest`, `Concerns/ResolvesRefundRequests`
> **Kullanım kılavuzu:** [`PROJE-KOMUTLARI.md` §3.3](../../../PROJE-KOMUTLARI.md)
> **Test:** `tests/Feature/RefundCommandsTest.php`

## 1. Neden komut, neden panel değil?

Projede yönetim paneli yok; iletişim mesajları, hediye kodları ve kampanyalar da komutla
yönetiliyor. Talep hacmi küçük ve her biri bir **para hareketi**: elle, bilinçli bir adım
olması bir eksik değil, bir emniyet.

## 2. Onayın sorusu

`refunds:approve` önce *"Parayı Shopier panelinden geri gönderdiniz mi?"* diye sorar,
varsayılan cevap **hayır**. `--no-interaction` ile çalıştırılırsa soru varsayılanı alır ve
komut hiçbir şey yapmadan çıkar. Bir betik ya da yanlış yapıştırılmış bir satır, para
gönderilmeden kaydı "iade edildi" yapamaz.

Ret komutu soru sormaz: para hareketi yok, hak kullanıcıya döner.

## 3. Neden kalıtım değil trait?

İki komut birbirinin özel hâli değil. `RejectRefundRequest extends ApproveRefundRequest`
yazılsaydı onay sorusu ret komutuna da miras kalırdı ya da ezilmesi gerekirdi. Ortak olan
yalnızca "Action'ı çağır, hatayı yaz" adımı; o da `ResolvesRefundRequests`'te.

## 4. Liste

- Varsayılan: açık talepler, **en eski üstte** (iş kuyruğu sırası).
- `Shopier ödeme` sütunu `provider_payment_id`: Shopier paneli ödemeyi bu kimlikle tanır (K121).
- Açıklama kullanıcıdan geliyor: kontrol karakterleri görünür kılınır (`contact:list` kuralı).
