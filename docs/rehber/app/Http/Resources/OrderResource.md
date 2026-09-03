# `app/Http/Resources/OrderResource.php`

> **Kod dosyası:** `app/Http/Resources/OrderResource.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.13
> **Sözleşme:** `davetkart-frontent/src/types.ts` → `CheckoutResult`

---

## 1. Resource bir beyaz listedir (C1)

```php
return [
    'orderId' => (string) $this->id,
    'tier' => $this->tier->value,
    'status' => $this->status->value,
];
```

Üç alan. Tabloda on bir kolon var. **Olmayanlar bilinçli:**

| Kolon | Neden dışarı çıkmıyor |
|---|---|
| `provider`, `provider_ref` | 🔴 Sağlayıcı iç kimliği. Altyapıyı ele verir **ve** idempotans anahtarını istemciye öğretir |
| `user_id` | İstemci zaten kendisi |
| `amount_minor`, `currency` | 🔴 Açık karar — §3 |
| `paid_at`, `expires_at` | Bugün hiçbir ekran okumuyor |
| `invitation_id` | İstemci zaten gönderdi |

**C5** (Faz 3): *"giden alanlar da beyaz listedir."* Bir kolonun var olması,
sözleşmeye ait olduğu anlamına gelmez.

---

## 2. `orderId` — kolon adı değil sözleşme adı

```php
'orderId' => (string) $this->id,
```

Sütun `id`, sözleşme `orderId` (`types.ts` → `CheckoutResult.orderId`).
Dönüşüm **yalnızca burada** yapılır (`CLAUDE.md` §1: Resource, snake→camel
dönüşümünün tek yeri) ve **açıkça** yazılır, sihirli bir fonksiyonla değil.

`(string)` cast'i: sözleşme `id: string` diyor. ULID zaten string ama cast,
sözleşmeyi tesadüfe değil **koda** bağlar — Faz 3'te `UserResource`'ta aynı
gerekçeyle yazılmıştı.

---

## 3. 🔴 Fiyat neden yanıtta yok?

Cazip: *"kullanıcı ne ödeyeceğini görsün."* Reddedildi.

Fiyat frontend'in **kataloğunda zaten var** (`data.ts` → `SubscriptionPlan.price`)
ve plan kartlarında gösteriliyor. Yanıtta ikinci bir kaynak olarak göndermek,
iki fiyatın **ayrışabildiği** bir ekran üretirdi: katalog 249 der, yanıt 279
der, kullanıcı hangisine inanacak?

> Bu, K31'in (*"paylaşılan tip paketi yerine tek yönlü üretim"*) fiyat
> eksenindeki hâli: iki kopya varsa hangisinin doğru olduğu **açıkça**
> tanımlanmalı. Bugünkü tanım: **fiyatın sunum kaynağı frontend
> kataloğudur**, `config/davetkart.php` ise **tahsilatın** kaynağıdır.

Fatura ucu doğduğunda (Faz 9) o uç kendi Resource'unu alacak — **C4**: *aynı
veri, farklı okuyucular için farklı Resource.*

---

## 4. `withRedirectUrl()` — akıcı ayarlayıcı

```php
$resource = (new OrderResource($order))->withRedirectUrl($result->redirectUrl);
```

### Neden kurucu parametresi değil?

`JsonResource::__construct($resource)` framework tarafından da çağrılır —
`collection()`, `whenLoaded()`, `ResourceCollection` içinden. İmzasını
değiştirmek bu yolları **kırar**.

### Neden `null` gönderilmiyor?

```php
if ($this->redirectUrl !== null) {
    $payload['redirectUrl'] = $this->redirectUrl;
}
```

**C7** (Faz 5): *"sözleşmede zorunlu alan her zaman gider; opsiyonel alan
yoksa hiç gitmez."* `null` göndermek `string | undefined` sözleşmesini kırar —
TypeScript tarafında `if (result.redirectUrl)` yazan kod `null`'ı da
yakalardı ama tip `string | null | undefined` olurdu ve sözleşme bulanıklaşırdı.

### Neden bir sipariş alanı değil?

`redirectUrl` sağlayıcıdan gelir, veritabanında saklanmaz (**E1**) ve yalnızca
**bu isteğin** yanıtı için anlamlıdır. Order'a geçici bir özellik olarak
iliştirmek onu kolonmuş gibi gösterirdi — `Media::url()`'un accessor değil
metot olmasıyla aynı gerekçe (Faz 6).

---

## 5. Zarf: `{data: {...}}`

Auth uçları zarfsız döner (K11); **diğer her şey** Laravel'in varsayılan
`{data: ...}` zarfıyla. Ödeme uçları "diğer"dir.

```json
{ "data": { "orderId": "01J…", "tier": "elit", "status": "pending", "redirectUrl": "…" } }
```

> ⚠️ **Frontend uyuşmazlığı:** `paymentService.checkout()` bugün zarfsız ve
> `status: 'paid'` bekliyor (mock, ödemeyi anında başarılı sayıyordu). Gerçek
> akışta checkout `pending` döner; `paid`'e geçişi **webhook** yapar. Frontend
> uyarlaması `FAZ-7.md` §8'de.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `provider_ref`'i yanıta koymak | İdempotans anahtarı istemciye öğretilir |
| 2 | Fiyatı yanıta koymak | İki fiyat kaynağı ayrışabilir |
| 3 | `redirectUrl`'i her zaman göndermek (`null` ile) | C7 ihlali |
| 4 | `withRedirectUrl` yerine kurucuyu değiştirmek | `collection()` ve `whenLoaded()` kırılır |
| 5 | `'id' => $this->id` yazmak | Sözleşme `orderId` diyor |
| 6 | Enum'u ham göndermek | `json_encode` değere çevirir ama sözleşme tesadüfe bağlanır |

---

## 7. Kendin dene

```php
// php artisan tinker
use App\Http\Resources\OrderResource;
use App\Models\Order;

$o = Order::factory()->create();

(new OrderResource($o))->toArray(request());
// ['orderId' => '01J…', 'tier' => 'standart', 'status' => 'pending']

(new OrderResource($o))->withRedirectUrl('/odeme/basarili')->toArray(request());
// + 'redirectUrl' => '/odeme/basarili'
```

**Mutasyon denemesi (kural 14):** `toArray()`'e `'providerRef' => $this->provider_ref`
ekle. `php artisan test --filter=PaywallTest` çalıştır. 🔴 `assertJsonStructure`
**fazladan alanı yakalamaz** — `the_checkout_response_never_exposes_the_provider_ref`
testi bu boşluğu `assertJsonMissingPath` ile kapatıyor. Faz 6'nın mutasyon
tablosundaki 20. satırın (`MediaResource`'a `path` eklenirse hiçbir test
kırılmaz) bu fazda **kapatılmış** hâli.

---

## 8. Sırada ne var?

**7.14 — Controller'lar.** İki uç, iki tehdit modeli.
