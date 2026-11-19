# `RefundEligibility` · `RefundBlocker` — "bu hak iptal edilebilir mi?"

> **Faz:** 14, adım 14.3 · **Karar:** K139 · **Faz 18.4 (`SEC-02`):** K166, K167 (§6–§9)
> **Kullanan:** `SummarizeEntitlementsAction` (düğme), `RequestRefundAction` (karar, 14.5),
> `ResolveRefundRequestAction` ve `refunds:approve` (onay anı, 18.4)

## 1. Kural

Bir hak iade edilebilir **ancak ve ancak**:

| # | Koşul | Değilse (`RefundBlocker`) |
|---|---|---|
| 1 | Açık bir talep yok | `already_requested` |
| 2 | Sipariş `paid` | `not_paid` |
| 3 | Hiçbir davetiyeye bağlı değil ve yanmamış | `in_use` |
| 3b | Hiçbir davetiyeyi **yayınlamamış** (Faz 18.4, K166) | `used` |
| 4 | Ücretli (hediye kodu değil, tutar > 0) | `free` |
| 5 | Ödemeden bu yana `davetkart.refunds.window_days` (14) gün geçmemiş | `window_closed` |

## 2. Neden yalnızca KULLANILMAMIŞ hak?

Yayında bir davetiyenin hakkı iade edilseydi davetiye ya yayında kalırdı (bedava yayın) ya da
iade anında yayından inmesi gerekirdi (misafirlerin elindeki bağlantı kırılır). Kullanıcının
önünde zaten bir yol var: 3 gün içinde davetiyeyi silerse hak serbest kalır, sonra iptal
edebilir. İki kural birbirine yaslanıyor, yeni bir istisna doğmuyor.

> ⚠️ **Bu paragrafın son cümlesi `SEC-02`'nin ta kendisiydi** (Faz 18.4'te düzeltildi, §6).
> "Sil, sonra iptal et" yolu yalnızca **hiç yayınlanmamış** davetiye için doğru. Yayınlanmış
> davetiye silinince hak serbest kalır ve yeniden **kullanılır**, ama iade edilmez.

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

---

## 🆕 Faz 18.4 (`SEC-02` · K166, K167)

## 6. Kapatılan delik: bağın yokluğu kanıt değildir

Eski 3. koşul "kullanıldı mı?" sorusunu iki şeye bakarak cevaplıyordu: hak şu an bir davetiyede mi
(`invitation_id`), yanmış mı (`consumed_at`). Üç günlük pencerede silinen davetiyenin siparişi
ikisi de değildi: bağ kopmuş, hak yanmamıştı (K82). Kural "kullanılmamış" dedi ve talep açıldı.

| Adım | Ne oldu | Eski kuralın gördüğü |
|---|---|---|
| Öde, yayınla, bağlantıyı dağıt, LCV topla | Hizmet kullanıldı | `invitation_id` dolu → `in_use` ✅ |
| 3 gün dolmadan davetiyeyi sil | Bağ koptu (K82) | `invitation_id = NULL`, `consumed_at = NULL` |
| "Hakkı iptal et" | — | engel yok → **201** ❌ |

Düzeltme: üçüncü bir kanıt, `orders.first_published_at`. Yayın anında basılır, hiçbir yol silmez
([göç kılavuzu](../../../database/migrations/2026_11_17_100000_add_first_published_at_to_orders_table.md)).

```php
public function usageBlockerFor(Order $order): ?RefundBlocker
{
    return match (true) {
        $order->invitation_id !== null || $order->isConsumed() => RefundBlocker::InUse,
        $order->hasPublished() => RefundBlocker::Used,
        default => null,
    };
}
```

**Ders:** bir durumu (kullanıldı) bir bağın **yokluğundan** türetme. Yokluk iki şey anlatabilir:
"hiç olmadı" ve "oldu, sonra koptu". İkisini ayıran bilgi bir olay kaydıdır.

## 7. Neden yeni bir engel (`used`), neden `in_use` değil? (K166)

`in_use`'un kullanıcıya görünen metni "hak bir davetiyede kullanılıyor". Serbest kalmış hak ise
ekranda **"Kullanılabilir"** rozetiyle durur: kullanıcı onunla yeni bir davetiye yayınlayabilir.
Aynı satırda "kullanılabilir" ve "kullanılıyor" demek çelişkidir ve kullanıcıya yanlış eylemi
önerir ("davetiyeyi silersem iade alırım").

| Engel | Hak şu an | Kullanıcıya | Önündeki eylem |
|---|---|---|---|
| `in_use` | bir davetiyede ya da yanmış | "hak bir davetiyede kullanılıyor" | (taslaksa) davetiyeyi sil |
| `used` | serbest, kullanılabilir | "bu hakla daha önce bir davetiye yayınlandı" | yok: yeni bir davetiyede kullan |

**Sıra:** `in_use` önce. Hak şu an yayındaki bir davetiyedeyse iki engel de doğrudur; kullanıcıya
en güncel olanı söylenir. Test: `a_right_in_a_published_invitation_reports_in_use_before_used`.

**Sözleşme:** hata kodu aynı (`REFUND_NOT_ALLOWED`, 409); yalnızca `params.reason` yeni bir değer
alabilir. `contracts/error-codes.json` parametrenin **adını** taşır, değerlerini değil; dosya
değişmedi. Frontend'de tip (`RefundBlocker`), çeviri (`refundReasons.used`) ve Planlarım satırı
güncellendi.

## 8. `usageBlockerFor()` neden ayrı bir metot? (K167)

Üç tüketici "kullanıldı mı?" sorusunu soruyor, ama yalnızca biri beş koşulun hepsini istiyor:

| Tüketici | Sorduğu | Neden |
|---|---|---|
| `RequestRefundAction`, hak listesi | `blockerFor()` (bütün koşullar) | Talep açılabilir mi? |
| `ResolveRefundRequestAction` (onay) | `usageBlockerFor()` | Talep zaten açık (`already_requested` her zaman dolu olurdu); süre talepte sınandı, yöneticinin gecikmesi kullanıcıyı cezalandırmamalı |
| `refunds:approve` (ön denetim) | `usageBlockerFor()` | Para sorusundan önce dur |

Onayda neden yeniden soruluyor:
[`ResolveRefundRequestAction.md`](../../Actions/Refund/ResolveRefundRequestAction.md) §5.

`usageBlockerFor()` ilişki okumaz (§5'in uyarısı ona uygulanmaz): yalnızca siparişin kendi
kolonlarına bakar.

## 9. Sık hatalar ve deneme

| Hata | Sonuç |
|---|---|
| `hasPublished()` kolunu "bağ zaten yok" diye silmek | `SEC-02` geri gelir |
| Yeni koşulu yalnızca `blockerFor()`'a yazmak | Onay eski kuralla çalışır; düzeltmeden önce açılmış talepler onaylanır |
| `used`'ı `in_use`'tan önce sormak | Yayındaki hakkın sahibine "daha önce kullanıldı" denir |
| Frontend'de `used` çevirisini eklememek | Kullanıcı ham `used` kelimesini görür (`defaultValue`) |

```powershell
php artisan test --filter=RefundRequestTest
```

```php
// php artisan tinker
$e = app(App\Services\Refund\RefundEligibility::class);
$o = App\Models\Order::query()->whereNotNull('first_published_at')->whereNull('invitation_id')->first();
$e->usageBlockerFor($o);          // RefundBlocker::Used
$o->load('openRefundRequest'); $e->blockerFor($o);
```

Mutant: `usageBlockerFor()`'dan `$order->hasPublished() => RefundBlocker::Used,` satırını sil →
`a_right_that_published_an_invitation_cannot_be_refunded_after_release` kırmızı (409 yerine 201).
