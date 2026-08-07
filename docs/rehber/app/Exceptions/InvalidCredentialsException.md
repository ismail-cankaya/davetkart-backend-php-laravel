# `app/Exceptions/InvalidCredentialsException.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Exceptions/InvalidCredentialsException.php`
> (+ `ApiExceptionRenderer.php`'ye eklenen bir kol)
> **Yol haritasındaki yeri:** Faz 2, dosya 2.8c-1 — `LoginUserAction`'ın ön koşulu
> **Bağlantılı:** [`RegistrationFailedException.md`](RegistrationFailedException.md)
> (exception deseninin tam anlatımı orada) · `docs/08-HATA-SOZLESMESI.md` §3.1

---

## 0. Bir dakikalık özet

```php
final class InvalidCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Login failed: invalid email or password.');
    }
}
```

Dört satır. Ve bu dört satırın **en önemli kısmı boş olan parantez**:

```php
public function __construct()      // ← parametre YOK
```

> Exception sınıfı yazma deseninin tamamı (neden ayrı sınıf, `RuntimeException`
> seçimi, `resolveCode()` kaydı, `final`) [`RegistrationFailedException.md`](RegistrationFailedException.md)'de
> anlatıldı. Burada yalnızca **fark** var.

---

## 1. 🔴 Kurucu neden parametre almıyor?

`RegistrationFailedException`'ın adlandırılmış bir kurucusu vardı:

```php
public static function emailTaken(): self { ... }     // sebep KODDA açık
```

Burada öyle bir şey **yok** ve olmayacak. Sebep şu: girişte **iki farklı
başarısızlık durumu** var ve ikisi **birbirinden ayırt edilmemeli**.

```
Durum A:  Bu e-postayla kayıtlı kullanıcı yok
Durum B:  Kullanıcı var ama parola yanlış
```

Kod yazarken doğal refleks şudur:

```php
if ($user === null) {
    throw InvalidCredentialsException::userNotFound();     // ❌
}
if (! Hash::check($password, $user->password)) {
    throw InvalidCredentialsException::wrongPassword();    // ❌
}
```

Çalışır. Yerelde `debug.message` iki farklı cümle gösterir ve hata ayıklarken
işine yarar. **Ve tam olarak bu yüzden tehlikelidir:** bir gün biri
`APP_DEBUG=true` ile üretime çıkar, ya da mesajlar log'a düşer ve log'lar
sızar, ya da yeni bir geliştirici bu ayrımı görüp "madem ayrı, yanıtta da
ayıralım" der.

**Parametresiz kurucu bu yolu kapatır.** Farklı bir mesaj üretmek *mümkün
değildir* — hatırlanması gereken bir kural değil, dilin izin vermediği bir şey.

> **Genel ilke:** Bir kuralı **yorumla** değil **tiple** zorla. `$fillable`
> beyaz listesi, `ErrorCode::filterParams()`, `APP_DEBUG`'a bağlı `debug` bloğu
> ve bu parametresiz kurucu — hepsi aynı ailenin üyesidir: *"yanlış olanı
> yapmak imkânsız olsun."*

---

## 2. Neden `AuthenticationException` kullanmadık?

Laravel'in hazır bir exception'ı var ve `ApiExceptionRenderer` onu zaten tanıyor:

```php
$e instanceof AuthenticationException => ErrorCode::Unauthenticated,   // 401
```

Kullanmadık, çünkü **iki farklı olayı** anlatıyorlar — ikisi de 401 olsa bile:

| Kod | Anlamı | Frontend ne yapmalı |
|---|---|---|
| `UNAUTHENTICATED` | *"Token yok / geçersiz / süresi dolmuş"* | Oturumu düşür, giriş sayfasına at |
| `INVALID_CREDENTIALS` | *"Girdiğin e-posta veya parola yanlış"* | **Formda kal**, hatayı göster |

İkisini birleştirseydik, kullanıcı yanlış parola girdiğinde frontend onu
"oturumun düştü" sanıp giriş sayfasına yönlendirir — zaten orada olduğu için
form sıfırlanır, yazdıkları uçar.

`docs/08-HATA-SOZLESMESI.md` §4 bu ayrımı ihlal edilemez sayar:

> 🔴 **401 ile 403 ayrımı ihlal edilemez.** Frontend `api.ts` interceptor'ı
> 401'de oturumu düşürüyor.

`ErrorCode` enum'unda ikisinin ayrı case olarak durmasının sebebi buydu — Faz
1'de yazıldığında henüz kullanılmıyorlardı.

> ⚠️ **Frontend'e düşen bir iş var:** `api.ts` interceptor'ı şu an **her** 401'de
> oturumu düşürüyor. `INVALID_CREDENTIALS` geldiğinde bunu yapmamalı — zaten
> oturum yok, ve form sıfırlanmamalı. `claude/Notlar/03-FRONTEND-YAPILACAKLAR.md`'ye
> eklenecek.

---

## 3. `ApiExceptionRenderer`'a eklenen kol (H11)

```php
// H6: auth hatalari ASLA `fields` tasimaz — enumeration savunmasi.
$e instanceof RegistrationFailedException => ErrorCode::RegistrationFailed,
$e instanceof InvalidCredentialsException => ErrorCode::InvalidCredentials,
```

`fields` neden otomatik yok? Renderer'da `fields` üretimi yalnızca
`ValidationException` için çalışır; bu sınıf o tipte değil. H6, hatırlanarak
değil **yapısal olarak** sağlanıyor.

Durum kodu `ErrorCode::status()`'tan geliyor: `InvalidCredentials => 401`.

---

## 4. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `userNotFound()` / `wrongPassword()` ayrı kurucular | 🔴 Enumeration açığının kapısı | Tek, parametresiz kurucu |
| `AuthenticationException` kullanmak | Frontend oturumu düşürür, form sıfırlanır | Ayrı sınıf, ayrı kod |
| `403` kullanmak | Kimlik yok, yetki sorunu değil | `401` |
| `resolveCode()`'a kol eklemeyi unutmak | 500 `SERVER_ERROR` | H11 |
| Mesaja e-posta yazmak | Log sızarsa hesap listesi sızar | Sabit, veri taşımayan mesaj |

---

## 5. Kendin dene

```powershell
php artisan tinker
```

**1. Farklı mesaj üretmek mümkün mü?** (olmamalı)

```php
new App\Exceptions\InvalidCredentialsException('kullanici yok');
// ArgumentCountError: too many arguments — DİL İZİN VERMİYOR
```

**2. Renderer doğru kodu üretiyor mu?**

```php
$e = new App\Exceptions\InvalidCredentialsException;
$r = app(App\Exceptions\ApiExceptionRenderer::class)->render($e);
$r->getStatusCode();     // 401
$r->getContent();
// {"error":{"code":"INVALID_CREDENTIALS","debug":{...}}}
```

**3. `fields` sızıyor mu?** (sızmamalı)

```php
str_contains($r->getContent(), 'fields');    // false
```

**4. Üretim kipinde:**

```php
config(['app.debug' => false]);
app(App\Exceptions\ApiExceptionRenderer::class)->render($e)->getContent();
// {"error":{"code":"INVALID_CREDENTIALS"}}
```

---

## 6. Bağlantılar

| İlgili | Nerede |
|---|---|
| Exception deseninin tam anlatımı | [`RegistrationFailedException.md`](RegistrationFailedException.md) |
| Enumeration kuralı (H6) | `docs/08-HATA-SOZLESMESI.md` §3.1 |
| 401 / 403 ayrımı | `docs/08-HATA-SOZLESMESI.md` §4 |
| Kod → durum eşlemesi | [`ErrorCode.md`](../Enums/ErrorCode.md) |
| Sıradaki dosya | `app/Actions/Auth/LoginUserAction.php` (2.8c-2) — zamanlama saldırısı |
