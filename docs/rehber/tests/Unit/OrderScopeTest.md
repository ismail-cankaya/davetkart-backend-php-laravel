# `tests/Unit/OrderScopeTest.php`

> **Faz:** 9 — Üretim hazırlığı, dosya 9.8
> **Test edilen:** [`../../app/Enums/OrderScope.md`](../../app/Enums/OrderScope.md)
> **Kurallar:** **T6** (bir davranışın hem varlığı hem yokluğu) · **K39** · ders 26

---

## 1. Projenin ilk birim testi — sekiz faz sonra

Sekiz faz boyunca `tests/Unit/` **boştu**. Bu bir ihmal değildi: yazılacak
saf bir şey yoktu. Her kural ya HTTP sınırında (FormRequest, Policy, zarf) ya
veritabanı kısıtında (CHECK, UNIQUE) yaşıyordu ve ikisi de Feature testi
ister — `Storage::fake()`, `RefreshDatabase`, gerçek bir istek.

`OrderScope` ilk istisna: girdisi bir enum değeri, çıktısı bir `bool`. Arada
ne ağ var ne disk.

```php
final class OrderScopeTest extends TestCase   // ← PHPUnit'in TestCase'i
```

🔴 `Tests\TestCase` **değil**. Bu bir ayrıntı değil, bir **sınırın** ifadesi:
bu testler Laravel uygulamasını hiç ayağa kaldırmaz, veritabanına hiç
dokunmaz. Kurulum maliyeti sıfır olduğu için milisaniyelerde biterler.

> **Ders 26 tersine işledi.** Boş `Unit` süiti kullanılmıyordu ve silinmeye
> adaydı (9.2'de tam bu tartışıldı). Silmek yerine **dolduruldu**, çünkü aynı
> fazda onu hak eden bir sınıf doğdu. "Ya kullan ya sil" bir silme emri
> değil, bir **karar verme** emridir.

---

## 2. Altı test, üç iş

| Test | Neyi korur |
|---|---|
| `it_exposes_exactly_two_raw_values` | Migration'ın CHECK kısıtının okuduğu liste (**K39**) |
| `only_the_account_scope_grants_rights_across_the_account` | Resolver'ın paket kolu |
| `only_the_invitation_scope_is_releasable` | Silme akışının ilk sorusu |
| `the_two_predicates_answer_different_questions` | 🔴 Aşağıya bak |
| `an_unknown_value_cannot_be_constructed` | Sihirli string yasağı |
| `try_from_returns_null_for_an_unknown_value` | `from()` / `tryFrom()` ayrımı |

İlk üçü **hem doğrulayıp hem yanlışlıyor** (`assertTrue` + `assertFalse`) —
**T6**: bir davranışın hem varlığı hem yokluğu test edilir. Yalnızca
`assertTrue(Account->grantsAcrossAccount())` yazsaydık, gövdesi `return true;`
olan bir metot da testi geçerdi.

---

## 3. 🔴 Dördüncü test bir yorum satırı değil, bir alarmdır

```php
$this->assertCount(2, OrderScope::cases(), 'Ucuncu bir kapsam eklendi: …');
```

Bu test bugün hiçbir hata yakalamıyor — **kasıtlı olarak**. İşi, üçüncü bir
kapsam (kampanya kodu, hediye çeki) eklendiği gün **kırmızı yanmak**.

Neden gerekli? `isReleasable()`'ı `! grantsAcrossAccount()` diye yazmak
cazipti; iki değerli bir enum'da ikisi aynı sonucu verir. Yazılmadı, çünkü
üçüncü bir kapsam hem hesap genelinde geçerli **hem de** bir davetiyeye
bağlanabilir olabilir. İki ayrı soru, iki ayrı cevap.

Ama bu bilinç **koda yazılamaz** — kod yalnızca bugünkü iki değeri anlatır.
Bu yüzden karar bir teste gömüldü ve mesajı, kırmızı yandığı gün ne yapılması
gerektiğini söylüyor.

> **Genel fikir:** bazı testler bir hatayı yakalamak için değil, bir
> **varsayımın değiştiği anı** yakalamak için yazılır. Faz 4'ün ETag
> testleriyle aynı aile: bir sözleşmeyi donduruyorlar.

---

## 4. Neden `covers()` veya `rank()` burada test edilmiyor?

Onlar `SubscriptionTier`'ın metotları ve o enum'un kendi testi yok — Faz 7'de
`PaywallTest` üzerinden dolaylı sınandılar. Bu dosya **yalnızca `OrderScope`**
ile ilgileniyor.

Bir birim testi dosyası, test ettiği sınıfın **sınırını** de belgeler: burada
sipariş yok, davetiye yok, kullanıcı yok. Olsalardı bu bir Feature testi
olurdu ve `tests/Unit/`'e ait olmazdı.

---

## 5. Çalıştır

```powershell
php artisan test --testsuite=Unit
php artisan test --filter=OrderScopeTest
```

Süre farkını görmek için Feature süitiyle karşılaştır: bu altı test
veritabanına hiç dokunmadığı için bir Feature testinin tek `RefreshDatabase`
kurulumundan bile hızlı biter.

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Birim testi** | Tek bir sınıfı, bağımlılıklarını ayağa kaldırmadan sınayan test |
| **Feature testi** | Uygulamayı gerçek bir istek gibi baştan sona koşturan test |
| **Backed enum** | Her case'in ham bir değeri olan enum |
| **`from()` / `tryFrom()`** | İlki geçersiz değerde `ValueError` fırlatır, ikincisi `null` döner |
