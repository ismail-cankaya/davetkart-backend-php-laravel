# `config/sanctum.php` — Kılavuz

Sanctum = Laravel'in API token paketi. `personal_access_tokens` tablosunda
saklanan, **iptal edilebilir** token'lar üretir.

## Sanctum'un iki modu

| Mod | Ne zaman | Bizde |
|---|---|:---:|
| **SPA / cookie** | Frontend aynı domainde, çerez tabanlı oturum | ❌ |
| **API token (Bearer)** | Mobil/SPA, `Authorization` başlığı ile | ✅ |

Biz **Bearer token** modundayız. Frontend `{user, token}` alıyor, token'ı saklıyor
ve her istekte başlığa koyuyor.

## Neden JWT değil?

Frontend'in `useAuthStore.logout()` fonksiyonu **sunucu tarafında token iptali**
bekliyor. JWT bunu yapısal olarak karşılayamaz: JWT kendi kendini doğrular, sunucu
onu "geçersiz" ilan edemez (kara liste tutmadıkça — ki o da JWT'nin tek avantajı
olan "DB'siz doğrulama"yı ortadan kaldırır).

Sanctum'da iptal tek satır:

```php
$request->user()->currentAccessToken()->delete();
```

Maliyeti: istek başına indeksli bir sorgu (~0.2 ms). Bu fiyata iptal edilebilirlik
ucuzdur.

## Önemli anahtarlar

| Anahtar | Ne işe yarar |
|---|---|
| `stateful` | Çerez modunun geçerli olacağı domain listesi. Bearer modunda etkisiz |
| `guard` | Çerez modunda kullanılacak guard |
| `expiration` | Token ömrü (dakika). `null` = süresiz |
| `token_prefix` | Token'a önek. Sızıntı taramaları (GitHub secret scanning) için |
| `middleware` | Çerez modunun middleware'leri |

## DavetKart kararları

- **`expiration`:** Şimdilik `null` (süresiz). Kullanıcı davetiyesini haftalarca
  düzenliyor; sık giriş istemek deneyimi bozar. İptal zaten `logout` ile mümkün.
- **Token adı:** `config('davetkart.auth.token_name')` → `'davetkart-spa'`.
  Sabit string koda gömülmüyor; ileride mobil istemci eklenirse ikinci ad
  tanımlanır ve token'lar kaynağına göre ayırt edilebilir.

## Token nasıl saklanıyor?

Veritabanında token'ın **kendisi değil, SHA-256 hash'i** durur. Veritabanı
sızarsa token'lar kullanılamaz. Düz metin yalnızca üretildiği anda, bir kez
kullanıcıya döner — bu yüzden `createToken()` sonucu tekrar okunamaz.

## Dikkat

- `personal_access_tokens` migration'ı **kurulu** (28 Temmuz 2026).
- Token'ı frontend'e döndürürken `$token->plainTextToken` kullanılır;
  `$token` nesnesinin kendisi değil.
