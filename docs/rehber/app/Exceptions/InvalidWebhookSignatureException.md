# `app/Exceptions/InvalidWebhookSignatureException.php`

> Bu sınıfın gerekçesi, kardeşleriyle **birlikte** anlatılıyor:
> [`PaywallViolationException.md`](PaywallViolationException.md)
>
> Faz 7'nin dört exception'ı aynı arayüzü (`HasErrorCode`) uygular, aynı
> adımda (7.5) yazıldı ve kararları birbirine referansla alındı — ayrı ayrı
> anlatmak **B8**'in yasakladığı ikinci doğruluk kaynağını üretirdi.

| Ne aradığın | Nerede |
|---|---|
| Bu sınıfın kodu / durumu / parametreleri | [PaywallViolationException.md §5](PaywallViolationException.md#5-dördünün-ortak-sözleşmesi) |
| Neden bu HTTP durum kodu | [§2](PaywallViolationException.md#2-invitationalreadypublishedexception--409-neden-doğru) · [§3](PaywallViolationException.md#3-invalidwebhooksignatureexception--404-bir-savunmadır) · [§4](PaywallViolationException.md#4-paymentproviderexception--502-ile-503ün-ayrımı-k27) |
| Arayüzün kendisi | [HasErrorCode.md](HasErrorCode.md) |
| Hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` |
