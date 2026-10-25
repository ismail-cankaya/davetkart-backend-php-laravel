# `ResolveRefundRequestAction` · `RefundRequestResolvedNotification`

> **Faz:** 14, adım 14.6 · **Karar:** K141 · **Çağıran:** `refunds:approve`, `refunds:reject`

## 1. Ne yapar?

| Karar | Talep | Sipariş | Hak |
|---|---|---|---|
| Onay | `approved` + `resolved_at` + not | `paid → refunded` | Sayaçta "iade edildi" |
| Ret | `rejected` + `resolved_at` + not | değişmez | Yeniden kullanılabilir |

## 2. 🔴 Para burada gönderilmez

Shopier'in iade API'si entegre değil. Yönetici **önce** Shopier panelinden iade eder, **sonra**
komutu çalıştırır. Kod tersini yapamaz: neyin gönderildiğini bilmiyor. Bu yüzden onay
komutu soruyla başlar (RefundCommands.md §2).

## 3. Kilitler ve iki yarış

- **Kullanıcı aynı anda "Vazgeç" der:** talep satırı kilitli; ikincisi kapalı talebi görür.
- **Shopier'in iade bildirimi aynı anda gelir:** sipariş satırı kilitli. Bildirim önce
  geldiyse sipariş zaten `refunded`; Action bunu hata saymaz, talebi kapatır.

Durum geçişleri iki ayrı makineden sorulur: `RefundRequestStatus::canTransitionTo()` ve
`OrderStatus::canTransitionTo()`. Kod "zaten onaylı mı?" diye elle sormaz.

## 4. Mail commit'ten SONRA

Bildirim transaction içinde kuyruğa atılsaydı ve transaction geri alınsaydı, kullanıcıya
"iadeniz onaylandı" maili gider ama kayıt değişmemiş olurdu. Bildirim model değil **değer**
taşır (plan, tutar, not): kuyruk işi çalıştığında sipariş değişmiş olabilir; mail kararın
anını anlatır. Hesabı silinmiş kullanıcıda (`user_id = NULL`) mail gönderilmez.
