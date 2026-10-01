# `app/Http/Controllers/Api/V1/OrderController.php`

> **Kod dosyası:** `app/Http/Controllers/Api/V1/OrderController.php`
> **Faz:** 10 — Dilim C, adım 10.23
> **Önce oku:** [`RsvpController.md`](RsvpController.md) (aynı desen: Gate + sorgu kapsamı) ·
> [`OrderPolicy.md`](../../../../Policies/OrderPolicy.md)
> **Test:** [`tests/Feature/OrderTest.md`](../../../../../tests/Feature/OrderTest.md) (10.25)

---

## 1. Neden var?

Gözden geçirme raporu §2.1: *"kullanıcı ödemeden sonra hâlâ hiçbir şey göremiyor."*
Dilim A parayı doğru saydırıyor, ama frontend'in *"ödemen onaylandı"* diyebilmesi
için siparişin durumunu **sorabilmesi** gerekiyor. Bu controller o soruyu açıyor.

| Uç | Kim çağırır | Ne zaman |
|---|---|---|
| `GET /api/orders/{order}` | Ödeme dönüş sayfası (10.27) | Sağlayıcıdan dönüşte, birkaç saniye yoklayarak |
| `GET /api/orders` | İleride bir *"siparişlerim"* ekranı | — (bugün frontend'de `listOrders()` servisi var, ekran yok) |

## 2. Neden Action yok? (K15)

İki metot da **tek bir sorgu ve bir Resource**. Sarılacak bir iş kuralı yok.
CLAUDE.md'nin Action kuralı *"her kullanıcı işlemi için bir sınıf"* diyor, ama
K15 onu şöyle inceltmişti: bir Action sardığı kadar değil **taşıdığı kural kadar**
değerlidir. Okuma uçlarında (`InvitationController::index`, `RsvpController::index`)
aynı karar verilmişti.

## 3. `index`: sahiplik iki kez

```php
Gate::authorize('viewAny', Order::class);                  // karar
$user->orders()->latest()->orderByDesc('id')->get();       // zorlama
```

`viewAny` her zaman `true` döner. Asıl koruma **sorgunun kapsamı** (P3):
`$user->orders()` yazıldığı için başkasının siparişi sorguya giremez.
`Order::query()` yazılsaydı `Gate` hiçbir şeyi engellemezdi.

**Sıralama:** `latest()` = `created_at DESC`. Aynı saniyede açılan iki sipariş
için `created_at` eşit olur ve PostgreSQL sırayı garanti etmez. `orderByDesc('id')`
bu eşitliği bozar: ULID zamana göre sıralı, sıra her istekte aynı.

**Sayfalama yok:** bir kullanıcının sipariş sayısı düğün başına bir-iki. Liste
gerçek bir ekrana kavuşup büyürse `paginate()` o gün eklenir (B6).

## 4. `show`: rota model bağlama + Policy

```php
public function show(Order $order): OrderResource
{
    Gate::authorize('view', $order);

    return new OrderResource($order);
}
```

- `{order}` ULID değilse rota hiç eşleşmez → 404, veritabanına sorgu yok (`whereUlid`).
- ULID ama yoksa → bağlama `ModelNotFoundException` → 404.
- Var ama başkasının → `Gate` reddeder → `AuthorizationException` → **404** (H7).

Son ikisi **aynı gövdeyi** döndürür: saldırgan başkasının sipariş kimliğini tahmin
etse bile *"bu kimlik var ama senin değil"* bilgisini alamaz.

## 5. Neden `N+1` yok?

`OrderResource` hiçbir ilişkiye dokunmuyor. `invitationId` bir **kolon**, ilişki
değil. Bu yüzden `with()` gerekmiyor ve `preventLazyLoading` bir şey yakalamıyor.
Resource yarın davetiye başlığını da göstermek isterse, o gün `with('invitation')`
eklenmeli. Kılavuzun bu satırı o günün uyarısı.
