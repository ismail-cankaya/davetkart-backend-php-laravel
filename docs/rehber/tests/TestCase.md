# `tests/TestCase.php`

> **Kod dosyası:** `tests/TestCase.php`
> **Eklendiği yer:** Faz 3 girişi — Faz 2'nin kırık bir testi araştırılırken
> **Kurduğu kural:** **T13**

---

## 1. Bu dosya nedir?

Projedeki tüm testlerin türediği temel sınıf. Laravel kurulumla birlikte boş
gelir; ortak yardımcılar buraya konur.

```php
abstract class TestCase extends BaseTestCase
{
    protected function forgetAuthState(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
```

`abstract` = doğrudan örneklenemez, yalnızca miras alınır. `AuthTest extends
TestCase` yazan her test bu metoda sahip olur.

---

## 2. 🔴 Çözdüğü problem: guard kullanıcıyı önbelleğe alır

### 2.1 Belirti

Faz 2'nin şu testi kırmızı yanıyordu:

```php
$phone  = $user->createToken('api')->plainTextToken;
$laptop = $user->createToken('api')->plainTextToken;

$this->withToken($phone)->postJson(route('auth.logout'))->assertNoContent();

$this->withToken($phone)->getJson(route('auth.me'))->assertUnauthorized();  // ❌ 200 geldi
$this->withToken($laptop)->getJson(route('auth.me'))->assertOk();
```

İptal edilmiş token hâlâ çalışıyor gibi görünüyordu. İlk refleks:
"`RevokeTokenAction` bozuk."

**Değildi.** Aynı testin son satırı (`$user->tokens()->count() === 1`) geçiyordu
— yani token gerçekten silinmişti.

### 2.2 Sebep — `vendor/` okunarak bulundu

```php
// Illuminate\Auth\RequestGuard
public function user()
{
    if (! is_null($this->user)) {
        return $this->user;          // ← ÖNBELLEK
    }

    return $this->user = call_user_func($this->callback, $this->request, ...);
}

public function setRequest(Request $request)
{
    $this->request = $request;       // ← $this->user'a DOKUNMUYOR
    return $this;
}
```

Guard, çözdüğü kullanıcıyı bir özellikte tutuyor. Bu, üretimde **doğru ve
gereklidir**: tek bir HTTP isteği sırasında `auth()->user()` onlarca kez
çağrılabilir; her seferinde veritabanına gitmek saçma olurdu.

Sorun testte çıkıyor. Testte **uygulama nesnesi isteklere arasında yaşamaya
devam eder** — `AuthManager` kapsayıcıda durur, guard onun içinde durur, guard'ın
önbelleği de orada durur. Sanctum yeni isteği `setRequest()` ile bildiriyor ama
o metot önbelleği temizlemiyor.

Laravel'in test altyapısı da `forgetGuards()` çağırmıyor (framework kaynağında
arandı, yok).

Sonuç:

```
İstek 1 (logout, $phone)  →  guard kullaniciyi cozer ve ÖNBELLEĞE ALIR
İstek 2 (me, $phone)      →  guard token'a BAKMAZ, onbellekteki kullaniciyi doner  → 200
İstek 3 (me, $laptop)     →  yine onbellek                                          → 200
```

### 2.3 🔴 Asıl tehlike: yanlış kırmızı değil, yanlış yeşil

Testin 2. satırı haksız yere **kırmızı** yandı — can sıkıcı ama zararsız, çünkü
bakmaya gittik.

3. satır ise haksız yere **yeşil** yanıyordu. `$laptop` token'ının geçerli
olduğunu "doğruluyordu", ama o istekte token hiç okunmadı bile. Testi bozup
`$laptop` yerine anlamsız bir string koysan **yine geçerdi.**

Bu, Faz 2'nin **T10** kuralının kardeşidir:

> `actingAs()` guard'ı atlar; token yolunu test ediyorsan `withToken()` kullan —
> aksi hâlde **boş yeşil** test üretirsin.

Burada `withToken()` doğru kullanılmış ama guard önbelleği aynı sonucu doğurmuş.
Ortak ders: **bir testin yeşil yanması, doğrulamak istediğin şeyi doğruladığı
anlamına gelmez.**

### 2.4 Faz 3 için neden kritik?

Faz 3'ün en önemli testi şu olacak (3.12):

```php
$this->withToken($ayseninTokeni)->getJson("/api/invitations/{$mehmetinDavetiyesi}")
     ->assertNotFound();
```

Eğer aynı test metodunda daha önce Ayşe'nin bir isteği koştuysa ve guard
sıfırlanmadıysa, bu satır **Mehmet'in token'ıyla bile Ayşe'yi** döndürür. Yani
IDOR savunmasını test ettiğini sanırken hiçbir şey test etmemiş olursun —
**güvenlik testinin sessizce boşa çıkması.**

Yardımcının `AuthTest` içinde değil ortak `TestCase`'te durmasının sebebi budur.

---

## 3. `forgetGuards()` ne yapıyor?

```php
$this->app['auth']->forgetGuards();
```

`$this->app` = servis kapsayıcısı (service container). `['auth']` yazımı
kapsayıcıdan `AuthManager`'ı ister — `app('auth')` ile aynı şey.

`forgetGuards()` kayıtlı tüm guard örneklerini atar. Bir sonraki
`auth()->user()` çağrısında guard **sıfırdan** kurulur, token yeniden okunur ve
veritabanına gerçekten bakılır.

Yani metot "oturumu kapat" demiyor — **"unut ve yeniden sor"** diyor.

---

## 4. Ne zaman çağrılır?

| Durum | Gerekli mi? |
|---|---|
| Test metodunda tek bir kimlikli istek | ❌ Hayır |
| İkinci bir istek, **aynı** token | ✅ Evet — token iptal edilmiş olabilir |
| İkinci bir istek, **farklı** token/kullanıcı | ✅ **Kesinlikle** |
| Kimliksiz (public) istekler | ❌ Hayır |
| Ayrı test metotları | ❌ Hayır — her metotta uygulama yeniden kurulur |

Kural olarak: **aynı test metodunda ikinci bir kimlikli istekten önce çağır.**

```php
$this->withToken($a)->getJson('/api/...');

$this->forgetAuthState();          // ← arada
$this->withToken($b)->getJson('/api/...');
```

---

## 5. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | İki kimlikli istek arasında çağırmamak | İkinci istek token'a bakmaz — **boş yeşil** test |
| 2 | Kırmızı testi görüp **kodu** düzeltmeye çalışmak | Doğru kod bozulur; asıl kusur testtedir |
| 3 | `refreshApplication()` ile çözmeye çalışmak | `RefreshDatabase` işlemini bozar, veri kaybolur |
| 4 | Üretim koduna `forgetGuards()` koymak | Önbellek üretimde **doğru** davranıştır; her çağrı bir sorgu olur |

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Guard** | "Bu istek kim adına geliyor?" sorusunu cevaplayan bileşen (`sanctum`, `web`…) |
| **`RequestGuard`** | İsteğe bakarak kullanıcıyı çözen guard türü; Sanctum bunu kullanır |
| **Servis kapsayıcısı** | Nesneleri üreten ve tutan merkezi kayıt (`$this->app`) |
| **Memoization** | Hesaplanan sonucu saklayıp tekrar hesaplamama |
| **Boş yeşil test** | Geçen ama aslında hiçbir şey doğrulamayan test |
| **`abstract` sınıf** | Doğrudan örneklenemeyen, yalnızca miras alınan sınıf |

---

## 7. Kurulan kural

> **T13** — Aynı test metodunda birden fazla kimlikli istek yapılıyorsa,
> istekler arasında `forgetAuthState()` çağrılır. Guard çözdüğü kullanıcıyı
> önbelleğe alır ve `setRequest()` onu temizlemez; çağrılmazsa sonraki istek
> token'a hiç bakmadan önceki kullanıcıyı döndürür.
