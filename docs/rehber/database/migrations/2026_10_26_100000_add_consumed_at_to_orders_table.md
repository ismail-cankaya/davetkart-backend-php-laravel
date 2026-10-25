# `2026_10_26_100000_add_consumed_at_to_orders_table` — yanmış hakkın damgası

> **Faz:** 14, adım 14.1 · **Karar:** K137
> **Kullanan:** `DeleteInvitationAction` (yazar) · `ClaimReleasedOrderAction`, `SummarizeEntitlementsAction` (okur)
> **Test:** `tests/Feature/OrderConsumptionTest.php`

---

## 1. Hangi deliği kapatıyor?

Bir siparişin hakkı üç yerde durabilir:

| Durum | Eski temsil |
|---|---|
| Serbest (kullanılmamış) | `invitation_id = NULL` |
| Bir davetiyede | `invitation_id = <davetiye>` |
| Yanmış (pencere kapandıktan sonra silinmiş davetiyede) | `invitation_id = <çöpteki davetiye>` |

Üçüncü satır **geçici** bir temsil. Çöp kutusundaki davetiye 30 gün sonra `data:purge` ile
kalıcı silinir. `orders.invitation_id` FK'si `nullOnDelete`: bağ NULL olur. Sipariş artık
birinci satırla **aynı görünür** ve `ClaimReleasedOrderAction` onu bir sonraki yayında
bağlar. Yanmış hak, otuz gün sonra bedava bir yayın olur.

Hiçbir test bunu yakalamıyordu, çünkü testler purge'ü ve yayını aynı senaryoda koşmuyordu.
Faz 14'ün hak sayacı ("Planlarım") bu üç durumu ekranda ayırmak zorunda kalınca açık ortaya
çıktı: sayaç yanmış hakkı *kullanılabilir* gösterecekti.

## 2. Neden FK'yi değiştirmedik?

| Seçenek | Sonuç |
|---|---|
| `restrictOnDelete` | `data:purge` ve hesap silme durur: kişisel veri silinemez (KVKK) |
| `cascadeOnDelete` | Muhasebe kaydı (sipariş) silinir — yasal saklama yükümlülüğü |
| **Ayrı damga (`consumed_at`)** | Bağ kopabilir, bilgi kalır ✅ |

Kural: **bir bilgi, kopabilecek bir bağın içinde saklanmaz.**

## 3. Kısıt

```sql
CHECK (consumed_at IS NULL OR paid_at IS NOT NULL)
```

Parası alınmamış bir hak yanamaz. `DeleteInvitationAction` yalnızca `grantingPublishRight()`
siparişleri damgalar; kısıt, bu kuralın yanlışlıkla delinmesini veritabanında durdurur.

## 4. Geriye dönük doldurma

Migration, çöpteki bir davetiyeye hâlâ bağlı ve parası alınmış siparişleri damgalar. Neden
güvenli? Pencere **açıkken** silinen davetiyenin siparişi zaten bağını kaybetmişti; çöpteki
bir davetiyeye bağlı kalmışsa pencere kapalıydı.

⚠️ **Sınır:** çöp kutusu zaten boşaltılmışsa o siparişler ayırt edilemez. Site yayına
çıkmadan önce yazıldığı için pratikte boş bir küme.

## 5. Sık hatalar

| Hata | Sonuç |
|---|---|
| Serbest hak sorgusuna `unconsumed()` eklemeyi unutmak | Delik geri gelir |
| `consumed_at`'i silme anında değil purge anında yazmak | Purge'ün bilgisi yok; hangi siparişin yandığını o an bilemez |
