# `app/Policies/OrderPolicy.php`

> **Kod dosyası:** `app/Policies/OrderPolicy.php`
> **Faz:** 10 — Dilim C, adım 10.22
> **Önce oku:** [`InvitationPolicy.md`](InvitationPolicy.md) — Policy temelleri, IDOR, H7 (404), otomatik keşif
> **Kullanan:** [`OrderController.md`](../Http/Controllers/Api/V1/OrderController.md) (10.23)
> **Test:** [`tests/Feature/OrderTest.md`](../../tests/Feature/OrderTest.md) (10.25)

---

## 1. Neden şimdi?

Faz 7'den beri siparişler **yazılıyordu** (checkout ucu açar, webhook durumunu
değiştirir) ama kullanıcı onları hiç **okuyamıyordu**. Ödeme sonrası dönüş
sayfası (10.27) *"siparişim ödendi mi?"* diye sormak zorunda. Soru bir kayda
erişim demek, erişim de sahiplik kontrolü demek.

## 2. Yalnızca iki yetenek

| Yetenek | Cevap | Neden |
|---|---|---|
| `viewAny` | her zaman `true` | Liste sorgusu zaten kullanıcının **kendi** siparişleriyle sınırlı (`$user->orders()`, P3). Policy'nin söyleyeceği başka bir şey yok |
| `view` | `owns()` | Başkasının siparişi → `false` → **404** (H7) |

`update`, `delete`, `create` **yok** ve bilerek yok:

- **Oluşturma** checkout ucunun işi. O uç kendi yetkisini davetiye üzerinden
  soruyor (`InvitationPolicy::publish`): *"bu davetiye için ödeme başlatabilir misin?"*
- **Güncelleme** sistemin işi: durumu yalnızca imzalı webhook değiştirir
  (durum makinesi, K89).
- **Silme** yok: bir sipariş **muhasebe kaydıdır**. Kullanıcı hesabını silse bile
  kalması planlanıyor (H-1, 10.38).

Laravel'de Policy'de tanımlanmamış bir yetenek sorulursa cevap **`false`**'tur.
Yarın biri `Gate::authorize('delete', $order)` yazarsa kapı kapalı kalır.

## 3. `owns()` ve `===`

[`InvitationPolicy.md`](InvitationPolicy.md) §6 ile aynı: `Order` modeli
`'user_id' => 'integer'` cast'ini taşıyor. Cast olmasaydı PostgreSQL sürücüsü
`user_id`'yi string döndürebilir ve `1 === "1"` her sahibi reddederdi. Bu durumda
IDOR açığı değil, tersi olurdu: kimse kendi siparişini göremezdi.

## 4. Kayıt gerekmiyor

`App\Models\Order` → `App\Policies\OrderPolicy`: Laravel ad eşlemesiyle kendisi
bulur (otomatik keşif, `InvitationPolicy.md` §8).
