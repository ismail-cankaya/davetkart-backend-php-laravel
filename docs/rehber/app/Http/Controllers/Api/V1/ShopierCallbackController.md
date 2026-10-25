# `app/Http/Controllers/Api/V1/ShopierCallbackController.php`

> **Faz:** 12 — adım 12.7 · **Rota:** `POST /api/public/payments/shopier/callback`
> **Kararlar:** **K122** (E6: 303, K69'dan sapma) · K117 · **Test:** `tests/Feature/ShopierCallbackTest.php`

---

## 1. Karşıda bir insan var

Bu uç **tarayıcıdan** çağrılıyor: Shopier ödeme sonrası kullanıcının tarayıcısına
bu adrese otomatik bir form POST ettiriyor. Yanıtımız kullanıcının **ekranında**
görünüyor. Bu yüzden diğer auth'suz uçlardan farklı davranıyor:

| | Fake webhook / OSB | Bu uç |
|---|---|---|
| Gönderen | Bir sunucu | Kullanıcının tarayıcısı |
| İmza hatası | 404 JSON (K69) | 303 → `/odeme/hata` |
| Başarı | 204 / `success` | 303 → `/odeme/basarili?order=…` |

## 2. 🔴 K69'dan bilinçli sapma (K122)

K69 *"imza hatası 404"* der, gerekçesi: reddin kendisi bilgi sızdırmasın. Bir
makineye 404 "burada bir şey yok" der. Bir insana ise **boş bir JSON sayfası**
gösterir: ödemesini yapmış (ya da yapamamış) biri ekranda `{"error":…}` görür.

Sapmanın bedeli var mı? Saldırgan açısından iki yanıt da aynı bilgiyi verir: her
durumda iki sabit adresten birine yönlendiriliyor. Geçersiz imzada `?order=` **hiç
eklenmez**: imzasız bir istekte sipariş kimliğini yankılamak, "bu kimlik bizde var
mı" denemesine kapı açardı.

## 3. 303 See Other — neden 302 değil?

| Kod | Tarayıcı ne yapar |
|---|---|
| 302 | Tarihsel olarak belirsiz: bazı istemciler POST'u **tekrarlar** |
| 307 | POST'u **aynen tekrarlar** (gövdeyle birlikte frontend'e!) |
| **303** | "Sonucu **GET** ile şu adreste gör". Yöntem değişimi zorunlu |

Bu, "Post/Redirect/Get" desenidir: kullanıcı yenile tuşuna bastığında form
yeniden gönderilmez.

## 4. 🔴 Open redirect koruması

```php
$url = Config::string("payment.return_urls.{$kind}");   // hedef İSTEKTEN değil
if ($orderId !== null && Str::isUlid($orderId)) {        // order yalnızca ULID
    $url .= '?order='.$orderId;
}
```

Open redirect: güvenilir bir alan adının (`api.davetkart.com`) kullanıcıyı
saldırganın sitesine yönlendirmesi. Oltalama e-postalarının sevdiği bir araçtır:
link bizim alan adımızla başlar, kurban sahte bir "ödeme başarısız, kartını tekrar
gir" sayfasına düşer. Gövdeye `return_url=https://evil…` eklemek hiçbir şeyi
değiştirmiyor (testli). `order` değeri ULID değilse eklenmiyor; böylece
`?order=https://evil…` gibi bir sorgu dizesi de kurulamıyor.

## 5. Controller'daki `try/catch` ve `if` iş kuralı mı?

CLAUDE.md'nin ölçütü: *"bu `if` silinirse bozulan şey bir iş kuralı mı, yoksa
yalnızca hangi kodun çağrıldığı mı?"*

- `catch` → hangi yanıtın döneceği (yönlendirme mi, istisna JSON'u mu). Yanıt
  biçimi controller'ın işi.
- `if ($callback->notification !== null)` → Action çağrılsın mı. Karar (`osb` modunda
  bildirim yok) **sürücüde** verildi; burası yalnızca sonucunu izliyor. Silinse
  `null` bir argümanla `TypeError` alınır, bir iş kuralı atlanmaz.

İş kuralları (imza, mod, durum çevirisi, idempotans) sürücüde ve Action'da.

## 6. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| `redirect($request->input('return_url'))` | Open redirect |
| 302 ya da 307 kullanmak | POST tekrarı; 307'de gövde frontend'e gider |
| Geçersiz imzada da `?order=` eklemek | Kimlik yankılanır |
| Callback'te `status=success` görüp `paid` yapmak (`osb` modunda) | K117'nin saldırısı |

## 7. Kendin dene

```bash
curl -i -X POST http://localhost:8000/api/public/payments/shopier/callback \
     -d platform_order_id=01JB2Z8Q4M7X3K9V5N6P0R1T2W -d status=success \
     -d random_nr=123456 -d signature=yanlis
# HTTP/1.1 303 See Other
# Location: /odeme/hata
```
