# `app/Notifications/RefundRequestResolvedNotification.php`

> **Faz:** 14 (14.6) · **Ana kılavuz:** `ResolveRefundRequestAction.md` §4 · **Test:** `tests/Feature/RefundCommandsTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

İade talebinin sonucu (onaylandı / onaylanmadı) ve isteğe bağlı operatör notu.

## Neden böyle

Model değil **değerler** taşınır (karar, plan, tutar, not): kuyruk işi çalıştığında sipariş ya da talep değişmiş olabilir; posta kararın verildiği anı anlatmalı.

## Kurallar

- `ShouldQueue`: kuyruktan gider (15 saniye kuralı; yanıt süresi adresin varlığını sızdırmaz).
- Dil `config('davetkart.mail.locale')`; bağlantılar `davetkart.frontend.url`'den, isteğin Host başlığından değil.
- Tutar kuruştan `149,90 ₺` biçimine tam sayı aritmetiğiyle çevrilir.
- Ret postası hakkın hesapta kullanılabilir kaldığını söyler.

## Sık hata

| Hata | Sonuç |
|---|---|
| Siparişi `SerializesModels` ile taşıyıp gönderimde okumak | Posta karardan sonraki durumu anlatır |
