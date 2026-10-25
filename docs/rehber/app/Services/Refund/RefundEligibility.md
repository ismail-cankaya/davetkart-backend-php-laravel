# `RefundEligibility` · `RefundBlocker` — "bu hak iptal edilebilir mi?"

> **Faz:** 14, adım 14.3 · **Karar:** K139
> **Kullanan:** `SummarizeEntitlementsAction` (düğme), `RequestRefundAction` (karar, 14.5)

## 1. Kural

Bir hak iade edilebilir **ancak ve ancak**:

| # | Koşul | Değilse (`RefundBlocker`) |
|---|---|---|
| 1 | Açık bir talep yok | `already_requested` |
| 2 | Sipariş `paid` | `not_paid` |
| 3 | Hiçbir davetiyeye bağlı değil ve yanmamış | `in_use` |
| 4 | Ücretli (hediye kodu değil, tutar > 0) | `free` |
| 5 | Ödemeden bu yana `davetkart.refunds.window_days` (14) gün geçmemiş | `window_closed` |

## 2. Neden yalnızca KULLANILMAMIŞ hak?

Yayında bir davetiyenin hakkı iade edilseydi davetiye ya yayında kalırdı (bedava yayın) ya da
iade anında yayından inmesi gerekirdi (misafirlerin elindeki bağlantı kırılır). Kullanıcının
önünde zaten bir yol var: 3 gün içinde davetiyeyi silerse hak serbest kalır, sonra iptal
edebilir. İki kural birbirine yaslanıyor, yeni bir istisna doğmuyor.

## 3. Neden 14 gün, neden config?

Mesafeli satışta cayma süresi 14 gün. Ticari bir söz olduğu için `env()` değil config
(`release_window_days` ile aynı gerekçe). ⚠️ Hukuki uygunluk İsmail'in kontrolünde: dijital
içerikte cayma hakkının istisnaları var; sayı tek satırla değişir.

## 4. Neden tek sınıf?

İki tüketici var: ekran düğmeyi gösterir, sunucu talebi kabul eder. Kural iki yerde yazılsaydı
"iptal et" düğmesi görünür ama basınca 409 dönerdi (ya da tersi). C3: tek üretici.

## 5. 🔴 İlişki yüklü olmalı

`blockerFor()` `$order->openRefundRequest`'i okur. Katı kipte tembel yükleme hata fırlatır —
bu bir **özellik**: döngüde N+1 sorgu açmak yerine gürültüyle kırılır.
