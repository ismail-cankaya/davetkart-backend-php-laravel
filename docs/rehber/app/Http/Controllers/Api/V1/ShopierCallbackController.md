# `app/Http/Controllers/Api/V1/ShopierCallbackController.php`

> **Faz:** 12 — adım 12.7 · **Rota:** `POST /api/public/payments/shopier/callback`
> **Kararlar:** **K122** (E6: 303, K69'dan sapma) · K117 · K170 · **K172** (sürücü kurulamazsa da 303, §8) · **Test:** `tests/Feature/ShopierCallbackTest.php`

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

---

## 8. 🆕 Faz 18.5 (`SEC-03` · K172) — sürücü kurulamazsa da hata sayfası

### Ne değişti?

```php
public function __invoke(Request $request, HandlePaymentCallbackAction $action)
{
    try {
        $callback = (new ShopierGateway)->parseCallback($request);
    } catch (InvalidWebhookSignatureException|BadRequestHttpException|PaymentProviderException $e) {
        Log::warning('Shopier callback rejected', ['reason' => $e::class]);

        return $this->redirectTo('failure', null);
    }
    // …
}
```

Eskiden `ShopierGateway` metodun parametresiydi. Laravel parametreyi metot **çağrılmadan önce**
kurar; kurucu 503 fırlatırsa (sır yok, K123) istisna controller'ın `try`'ına hiç girmeden
genel hata işleyicisine gidiyordu ve tarayıcıda **503 JSON** görünüyordu. Faz 18.5'te
sürücü üretimde `callback` kipinde de kurulmuyor (K170); en olası anda (yanlış yapılandırma)
kullanıcı ödeme dönüşünde `{"error":…}` görecekti. Bu, K122'nin kaçındığı şeyin ta kendisi.

Şimdi kurulum `try`'ın içinde. Sürücü hangi nedenle kurulamazsa kurulmasın:

| | |
|---|---|
| Yanıt | 303 → `/odeme/hata` (**`?order=` yok**: imza doğrulanamadı, kimlik yankılanmaz, §2) |
| Sipariş | Değişmez: bildirim nesnesi hiç üretilmedi |
| Log | `warning Shopier callback rejected {"reason":"App\Exceptions\PaymentProviderException"}`; üretimde `callback` kipiyse sürücünün kendi `critical` satırı da |
| 500 / iç ayrıntı | Yok: istisna yakalanıyor, yanıtta yalnızca sabit adres |

### Neden `new`? Kapsayıcı (container) ya da `PaymentGatewayResolver` neden değil?

Üç yol denendi:

| Yol | Sonuç |
|---|---|
| `PaymentGatewayResolver` (K136) | Uymuyor: **aktif** sağlayıcıyı kurar; `PAYMENT_PROVIDER=fake` iken o Fake'tir. Bu uç her zaman Shopier'in ucu |
| `Container $container` + `$container->make(ShopierGateway::class)` | Çalışıyor ama PHPStan `catch (PaymentProviderException)` için *"dead catch"* dedi: `make()` yalnızca `BindingResolutionException` bildiriyor, kurucunun istisnası statik analizde görünmüyor. Üstüne `@throws` yazan bir yardımcı metot da *"bildirilen istisna fırlatılmıyor"* hatası aldı |
| **`new ShopierGateway`** | Kurucunun `@throws PaymentProviderException`'ı doğrudan görünüyor; `catch` gerçek bir istisnayı yakalıyor ve araç bunu doğruluyor |

`new` burada güvenli çünkü sınıfın kurucusunun hiçbir bağımlılığı yok (yalnızca config okuyor)
ve kapsayıcıda özel bir bağlaması yok; `AppServiceProvider` de onu düz `make()` ile kuruyor.
Bedeli: bir test bu sınıfı kapsayıcı üzerinden sahtesiyle değiştiremez. Bugün öyle bir test
yok; gerekirse kurucuya bağımlılık eklendiği gün bu satır da değişir.

**Ders:** statik analizin "bu catch ölü" demesi, çoğu zaman istisnanın yolunun gizlendiği
anlamına gelir. Aracı susturmak (`@phpstan-ignore`) yerine yolu görünür kılmak gerekir.

### Bu `catch` bir iş kuralı mı?

§5'teki ölçüt: silinse bozulan şey **hangi yanıtın** döneceği (yönlendirme mi, JSON mu).
"Üretimde `callback` kipi yasak" kuralı sürücünün kurucusunda; burada yalnızca sonucu
insana uygun biçimde gösteriyoruz.

### OSB ucu neden aynı şeyi yapmıyor?

OSB'nin karşısında bir **makine** var (Shopier'in sunucusu). Ona 503 doğru cevap: "şimdi
alamıyorum, sonra tekrar dene". Shopier'in tekrar deneyip denemediği **doğrulanmadı** (§A-c);
denerse, yapılandırma düzeltildikten sonra gelen bildirim siparişi `paid` yapabilir. 200
`success` dönseydik tekrar göndermesi için hiçbir sebep kalmazdı.

### Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Sürücüyü metot parametresi olarak bırakmak | Kurucu istisnası `try`'a girmez; tarayıcıda 503 JSON |
| PHPStan'in "dead catch" uyarısını `@phpstan-ignore` ile susturmak | Bir gün istisna gerçekten yolu değiştirirse kimse fark etmez |
| `catch (Throwable)` | Gerçek bir hata (veritabanı, kod) da sessizce "ödeme başarısız" olur; Sentry görmez |
| Hata sayfasına `?order=` eklemek | İmzası doğrulanamamış kimlik yankılanır |

### Kendin dene

```powershell
php artisan test --filter=ShopierCallbackTest
```

Mutant: `catch` listesinden `PaymentProviderException`'ı çıkar →
`a_forged_success_in_callback_mode_does_not_pay_in_production` ve
`without_secrets_the_callback_goes_to_the_failure_page` kırmızı (303 yerine 503).
