# `config/session.php` — Kılavuz

Oturum = sunucunun, çerez üzerinden aynı tarayıcıyı tanıması.

## DavetKart'ta neredeyse kullanılmıyor

Bizim API'miz **stateless**: kimlik, çerezle değil `Authorization: Bearer <token>`
başlığıyla taşınıyor (Sanctum token modu). Yani `/api/...` rotalarında oturum
devreye girmez.

Dosya yine de duruyor çünkü:

- Laravel'in bazı web bileşenleri (hata sayfaları, `web.php` rotaları) kullanır,
- İleride bir yönetim paneli eklersek gerekir.

## Önemli anahtarlar

| Anahtar | Ne işe yarar |
|---|---|
| `driver` | Oturum verisi nerede saklansın (`file`, `database`, `redis`) |
| `lifetime` | Hareketsizlik sonrası oturumun ölme süresi (dakika) |
| `encrypt` | Oturum verisinin şifrelenip şifrelenmeyeceği |
| `secure` | Çerez yalnızca HTTPS üzerinden gönderilsin mi |
| `http_only` | JavaScript çereze erişebilsin mi (XSS savunması) |
| `same_site` | Çerez başka sitelerden gelen isteklerde gönderilsin mi (CSRF savunması) |

## Stateless ne demek, neden tercih ettik?

Stateful (çerezli) API'de sunucu her kullanıcı için hafızada/diskte durum tutar.
Stateless'ta istek kendi kimliğini taşır; sunucu hiçbir şey hatırlamaz.

Kazanç: sunucu sayısını artırmak kolaydır (istek hangi sunucuya düşerse düşsün
aynı sonuç), ve CSRF saldırı yüzeyi ortadan kalkar — çünkü tarayıcı `Authorization`
başlığını otomatik göndermez, çerezi gönderir.

## Dikkat

- `.env`'de `SESSION_DRIVER=database`. `sessions` tablosu yoksa web rotalarında
  hata alınır; API tarafını etkilemez.
- Üretimde `SESSION_SECURE_COOKIE=true` olmalı (HTTPS zorunluluğu).
