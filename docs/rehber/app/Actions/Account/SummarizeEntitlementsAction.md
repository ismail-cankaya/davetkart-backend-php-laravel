# `SummarizeEntitlementsAction` — "Planlarım" hak sayacı

> **Faz:** 14, adım 14.3 · **Karar:** K140
> **Dosyalar:** `app/Actions/Account/{SummarizeEntitlementsAction, EntitlementSummary, TierEntitlements, EntitlementRight}.php` · `app/Enums/EntitlementState.php`
> **Uç:** `GET /api/account/entitlements` (14.4) · **Test:** `tests/Feature/SummarizeEntitlementsActionTest.php`

---

## 1. Hangi soruyu cevaplıyor?

> *"Kullanıcı 2 Gold ödedi, sonra 1'ini iptal etti. Hesabında kaç Gold hakkı görünür?"* → **1**

Her ödenmiş sipariş bir davetiyelik bir haktır (K99). Sayaç siparişleri **kovalara** ayırır:

| Kova (`EntitlementState`) | Koşul | Sayılır mı? |
|---|---|---|
| `available` | `paid` + bağsız + yanmamış + açık iade talebi yok | ✅ **asıl sayı** |
| `in_use` | `paid` + canlı bir davetiyeye bağlı | bilgi |
| `refund_requested` | `paid` + açık iade talebi (hak donmuş) | bilgi |
| `pending` | `pending` + süresi dolmamış (ör. havale) | bilgi, hak değil |
| `consumed` | `paid` + `consumed_at` dolu **veya** çöpteki davetiyeye bağlı | geçmiş |
| `refunded` | `refunded` | geçmiş |

`failed` ve `expired` sayaca girmez; sipariş geçmişinde görünür.

## 2. 🔴 Neden yeni bir tablo ya da `credits` kolonu yok?

Cazip olan: `users.gold_credits = 1`. Ama hak **zaten siparişte duruyor**. İkinci bir sayaç:

- satın almada artırılmayı, yayında azaltılmayı, iadede, silmede, purge'te güncellenmeyi
  bekler — beş yer, birini unutmak sayıyı sessizce bozar;
- bozulduğunda hangisinin doğru olduğunu söyleyen kimse kalmaz.

Okuma modeli **türetilir**, saklanmaz (E1). Yanlış olabilecek ikinci bir kopya yoktur.

## 3. Sorgu: sabit sayıda

```
orders (bu kullanıcı, releasable, paid|refunded|pending)
  + invitations (withTrashed)     ← eager load
  + refund_requests (açık olan)   ← eager load
```

Sipariş sayısı ne olursa olsun **3 sorgu**. Planda "tek SQL, CASE WHEN" yazıyordu; bunun
yerine kovaya ayırma PHP'de yapıldı. Sebep: kova kuralı `OrderStatus`, `Order::isConsumed()`
ve `RefundEligibility`'de duruyor. SQL'e CASE olarak yazılsaydı kuralın **ikinci kopyası**
olurdu (K50: "hangi durum sayılır" sorusu sorguda değil enum'da cevaplanır). Bir kullanıcının
siparişi onlarca; PHP'de döngü ihmal edilebilir.

## 4. 🔴 `withTrashed()` neden şart?

Yanmış hakkın davetiyesi çöpte. Soft delete varsayılan kapsamı onu gizler, ilişki `null`
döner, kod "bağlı değil" sanır ve sipariş **kullanılabilir** kovasına düşer. Testi:
`a_burned_right_stays_consumed_before_and_after_the_purge`.

Davetiyeler `with()` ile değil **elle** yükleniyor (`loadInvitationsIncludingTrashed`):
`Invitation::withTrashed()->whereKey($ids)` + `setRelation()`. İlişki üzerinden
`withTrashed()` bir makro ve PHPStan (Larastan) onu göremiyor; elle yazılan sorgu aynı tek
sorguyu tip güvenli biçimde açıkça söylüyor.

## 5. Sıra (match kolları)

1. Açık talep → `refund_requested` (hak donmuş, başka hiçbir şey sayılmaz)
2. Yanmış → `consumed` (bağ kopmuş olabilir: K137)
3. Bağlı → `in_use`
4. Kalan → `available`

## 6. Sık hatalar

| Hata | Sonuç |
|---|---|
| `withTrashed()` unutmak | Yanmış hak "kullanılabilir" |
| `pending`'i süre kontrolsüz saymak | Dün vazgeçilen ödeme "onay bekliyor" görünür |
| Planı yalnızca sipariş varsa döndürmek | Frontend boş planı ayrıca kodlamak zorunda kalır |
