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
| `expiration` | Token ömrü (dakika). `null` = süresiz. Bizde **30 gün** (aşağıda) |
| `token_prefix` | Token'a önek. Sızıntı taramaları (GitHub secret scanning) için |
| `middleware` | Çerez modunun middleware'leri |

## DavetKart kararları

- **`expiration`:** `60 * 24 * 30` → **30 gün, mutlak** (Faz 10, **K90**). Ayrıntı
  bir sonraki bölümde.
- **Token adı:** `'api'` — `LoginUserAction` ve `RegisterUserAction`'daki
  `TOKEN_NAME` sabiti. Etiket bilerek aynı (`LoginUserAction.md` §3.5).
  > `config/davetkart.php` → `auth.token_name` (`'davetkart-spa'`) **hiçbir
  > yerden okunmuyor**: ölü config, Faz 10 adım 10.77'de silinecek. Bu kılavuz
  > 10.10'a kadar onu kullanılıyor sanıyordu.

## Token ömrü: 30 gün, mutlak (Faz 10, K90)

### Neden artık süresiz değil?

Faz 2'den Faz 9'a kadar `expiration` `null`'dı. Gerekçe: *"kullanıcı davetiyesini
haftalarca düzenliyor, sık giriş istemek deneyimi bozar; iptal zaten `logout` ile
mümkün."*

Gerekçenin kaçırdığı şey: `logout` yalnızca kullanıcının **bildiği** token'ı
iptal eder. Çalınan bir token'ın (paylaşılan bilgisayar, sızan bir tarayıcı
eklentisi, log'a düşmüş bir başlık) sahibi haberdar değildir; süresiz token
**sonsuza kadar** geçerlidir. İkinci sonuç: süresi dolmayan token'ı hiçbir
temizlik işi silemez (`routes/console.md` §2'deki B4 notu), tablo yalnızca büyür.

İsmail'in kararı (25 Eylül 2026): *"aşırı hassas işlem yok"* → 30 gün yeter,
kayan pencereye gerek yok.

### "Mutlak" ne demek?

| Tür | Süre neye göre sayılır | Aktif kullanıcı |
|---|---|---|
| **Mutlak** ✅ | Token'ın **oluşturulduğu** an (`created_at`) | 30. günün sonunda yeniden giriş yapar |
| Kayan pencere | Token'ın **son kullanıldığı** an (`last_used_at`) | Hiç çıkış görmez |

Sanctum'un `Guard`'ı yalnızca mutlak olanı bilir
(`vendor/laravel/sanctum/src/Guard.php` → `isValidAccessToken()`):

```php
(! $this->expiration || $accessToken->created_at->gt(now()->subMinutes($this->expiration)))
```

Kayan pencere istenseydi `last_used_at`'e bakan kendi guard'ımızı yazmamız
gerekirdi — tam da K90'ın *"gerek yok"* dediği iş.

### Neden token başına `expires_at` değil de config?

Sanctum iki yol sunar:

```php
// 1) Token başına — yalnızca BUNDAN SONRA üretilen token'lara yazılır
$user->createToken('api', expiresAt: now()->addDays(30));

// 2) Genel — Guard her istekte, ESKİ token'lar dahil, created_at'e karşı okur
'expiration' => 60 * 24 * 30,
```

Birinci yol bugüne kadar üretilmiş her token'ı (hepsinin `expires_at`'i `NULL`)
**süresiz bırakırdı**. Kapatmak istediğimiz delik tam olarak o token'lar.

### Yan etki: yayına alındığı an

Config, eski token'lara da geriye dönük uygulanır. Deploy anında **30 günden
eski** her token bir sonraki istekte 401 `UNAUTHENTICATED` alır. Frontend bu
kodu zaten *"oturumu düşür"* olarak okuyor (`src/services/api.ts`): kullanıcı
giriş sayfasına döner, veri kaybı yok. Bilinçli ve kabul edilebilir.

### `env()` neden yok?

`token_prefix` env'den okunuyor, `expiration` okunmuyor. Fark: önek bir **ortam**
ayarı (her ortamın kendi taraması olabilir), ömür bir **ürün kararı**. Üretimin
testlerden farklı bir ömürle koşmasını istemiyoruz: testin kanıtladığı şey
üretimde de doğru olmalı.

### Süresi dolan token'ın satırı ne olur?

Guard onu reddeder ama **silmez**. Silme işi zamanlayıcıdaki
`sanctum:prune-expired`'ın — ve o komut ikinci sorgusunu **ancak `expiration`
doluyken** çalıştırır. Yani bu satır aynı zamanda temizlik işini de açıyor.
Ayrıntı: [`routes/console.md`](../routes/console.md) §2.

### Hangi test korur?

`tests/Feature/AuthTest.php` → `a_token_expires_thirty_days_after_it_was_issued`
(adım 10.11): login'den alınan token 30. günün son saniyesinde **200**, tam
sınırda **401** `UNAUTHENTICATED`. Son saniyedeki kullanım ömrü uzatmadığı için
aynı test *"mutlak"*ı da kanıtlıyor.
**Mutasyon:** `null`, 29 gün, 31 gün — üçü de testi kırıyor
([`AuthTest.md`](../tests/Feature/AuthTest.md) §3.6).

## Token nasıl saklanıyor?

Veritabanında token'ın **kendisi değil, SHA-256 hash'i** durur. Veritabanı
sızarsa token'lar kullanılamaz. Düz metin yalnızca üretildiği anda, bir kez
kullanıcıya döner — bu yüzden `createToken()` sonucu tekrar okunamaz.

## Dikkat

- `personal_access_tokens` migration'ı **kurulu** (28 Temmuz 2026).
- Token'ı frontend'e döndürürken `$token->plainTextToken` kullanılır;
  `$token` nesnesinin kendisi değil.
