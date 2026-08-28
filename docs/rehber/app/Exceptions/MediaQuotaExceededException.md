# `app/Exceptions/MediaQuotaExceededException.php`

> **Kod dosyası:** `app/Exceptions/MediaQuotaExceededException.php`
> **Faz:** 6 — Medya dilimi, dosya 6.5
> **Birlikte değişen:** `app/Enums/ErrorCode.php` · `contracts/error-codes.json`
> **Önce oku:** [`HasErrorCode.md`](HasErrorCode.md) · [`RsvpQuotaExceededException.md`](RsvpQuotaExceededException.md)

---

## 1. Yeni bir hata kodu eklemek — üç dosyalık bir zincir

`MEDIA_QUOTA_EXCEEDED` bu projede **Faz 1'den beri eklenen ilk yeni koddur**.
Zincir şöyle:

```
1. app/Enums/ErrorCode.php     → case + status() kolu + allowedParams() kolu
2. contracts/error-codes.json  → php artisan errors:export ile YENIDEN URETILIR
3. Exception sınıfı            → HasErrorCode arayüzünü uygular
```

🔴 **2. adım atlanırsa `composer check` kırılır** — ve bu iyi bir şeydir.
`errors:export --check` zincirde **testlerden önce** koşuyor (**K34**), yani
katalog eskirse saniyeler içinde anlaşılır, dakikalar süren testler hiç koşmaz.

**H5** hatırlatması: *kod adı yayınlandıktan sonra sözleşmedir.* Yeni kod
**eklemek** güvenlidir (frontend bilmediği kodu yedek metinle karşılar);
**yeniden adlandırmak** frontend'in çeviri anahtarını kırar.

---

## 2. Neden 403? (413 ve 429 neden değil)

Üç aday vardı ve üçü farklı şeyler söylüyor:

| Kod | Ne der | Bizim durumumuz |
|---|---|---|
| **413** `FILE_TOO_LARGE` | "Bu **dosya** çok büyük" | ❌ Dosya küçük olabilir; reddedilen **adet** |
| **429** `RATE_LIMITED` | "Çok hızlısın, **yavaşla**" | ❌ Bekleyerek çözülmez (K28) |
| **403** ✅ | "Bu işlem sana **kapalı**" | ✅ Kapasite sınırı |

413 ile 403 ayrımı ince ama önemli: bir kullanıcı 30 fotoğrafın 31.'sini
yüklerken **1 KB'lık** bir dosya gönderse bile reddedilir. Sorun boyut değil,
**yer**.

Frontend açısından da fark var: 413'te "dosyayı küçült" denir, 403'te
"galeriden bir şey sil ya da planını yükselt".

---

## 3. 🔴 İki adlandırılmış kurucu — ve `private __construct`

```php
private function __construct(private readonly ?int $limit) { ... }

public static function forOwner(int $limit): self { return new self($limit); }
public static function forGuest(): self          { return new self(null); }
```

Faz 5'te `RsvpQuotaExceededException`'ın kurucusu **parametresizdi**, çünkü tek
fırlatma yeri anonim misafirdi. Burada durum farklı — **iki ayrı okuyucu var**:

| Yol | Kim | `limit` verilir mi | Neden |
|---|---|---|---|
| `POST /api/media/upload` | 🔒 Davetiye sahibi | ✅ Evet | Kendi planının sınırı; zaten fiyat sayfasında yazıyor |
| `POST /api/public/invitations/{id}/media` | 🌍 Misafir | ❌ Hayır | **H9**: iç sayaçlar sadece kaynağın sahibine |

Neden `private __construct`? Çünkü o zaman şunu yazmak **imkânsız** olur:

```php
throw new MediaQuotaExceededException(30);   // ❌ Fatal error: private constructor
```

Yani misafir yolunda yanlışlıkla limit sızdırmak **derleme/çalışma anında
engelleniyor**, kod incelemesinde yakalanmayı beklemiyor.

Bu, `InvalidCredentialsException`'ın parametresiz kurucusuyla (A2) ve
`RegistrationFailedException::emailTaken()` adlandırılmış kurucusuyla aynı
aile: **bir güvenlik kuralını hatırlanmaya değil, sınıfın şekline bağla.**

### İkisi de ölü kod değil

**Ders 26** (*çağıranı olmayan kod, doğru olduğu varsayılan koddur*) burada
ihlal edilmiyor: `forOwner()` galeri yüklemesinde, `forGuest()` LCV
yüklemesinde çağrılıyor. İkisinin de gerçek bir yolu var.

Faz 5'te `RsvpQuotaExceededException::forOwner()` **yazılmamıştı** — çünkü o
gün sahibe dönük bir kota yolu yoktu. Fark bu.

---

## 4. `allowedParams()` neden `['limit']`, `['remaining', 'limit']` değil?

`ErrorCode::RsvpQuotaExceeded` ikisini birden veriyor. Medyada `remaining`
**bilerek yok**:

> `remaining` = *"kaç dosya daha yükleyebilirsin"* → dolaylı olarak
> *"şu an kaç dosya var"* demektir.

Sahip için zararsız (galerisini zaten görüyor), ama **aynı kod misafir yolunda
da dönüyor**. Beyaz liste kod başınadır, okuyucu başına değil — yani
`remaining`'i listeye koysaydık, misafir yolunda onu vermemek yalnızca
`errorParams()`'ın dikkatine kalırdı.

İki kapı birden dar tutuluyor:

```
Exception der ki : "limit verilebilir" (sahipte) / "hiçbir şey" (misafirde)
ErrorCode der ki : "en fazla limit çıkabilir"
```

**H12**: ikisi aynı fikirde değilse dar olan kazanır.

---

## 5. Katalog nasıl yeniden üretilir?

```powershell
php artisan errors:export
php artisan errors:export --check     # composer check bunu koşturuyor
```

Üreteç **enum'dan türetir**, elle bakım yapılmaz (**G3**). Çıktı:

- Kod adına göre **sıralı** (`ksort`) → diff gürültüsü olmaz (**G1**)
- `generatedAt` karşılaştırmadan **çıkarılır** (**G2**) → yoksa `--check` hiç
  geçemezdi

Bu fazda katalog 19 koddan **20**'ye çıktı.

> ⚠️ **Frontend borcu:** `contracts/error-codes.json` frontend'e kopyalanmalı
> ve `locales/*/errors.json` içine `MEDIA_QUOTA_EXCEEDED` çevirisi eklenmeli.
> Eklenmezse kullanıcı ham kodu görür — `08` §7 madde 4 bunun için "bilinmeyen
> kod için yedek metin" istiyordu.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Enum'a case ekleyip kataloğu yeniden üretmemek | `composer check` kırılır (K34) — ve bu **doğru** davranış |
| 2 | 413 döndürmek | "Dosyayı küçült" denir; oysa sorun adet |
| 3 | 429 döndürmek | "Bekle, düzelir" denmiş olur — yalan (K28) |
| 4 | Kurucuyu `public` bırakmak | Misafir yolunda yanlışlıkla limit sızdırılabilir |
| 5 | `allowedParams()`'a `remaining` eklemek | Misafire dosya sayısı sızar (H9) |
| 6 | `implements HasErrorCode` yazmayı unutmak | `default` koluna düşer → **500** (H11) |
| 7 | Kod adını sonradan değiştirmek | Frontend çeviri anahtarı kırılır (H5) |

---

## 7. Kendin dene

```php
// php artisan tinker
use App\Exceptions\MediaQuotaExceededException as Q;
use App\Enums\ErrorCode;

$sahip   = Q::forOwner(30);
$misafir = Q::forGuest();

$sahip->errorCode()->status();     // 403
$sahip->errorParams();             // ['limit' => 30]
$misafir->errorParams();           // []            ← H9

// Beyaz liste hâlâ yolun üzerinde mi?
ErrorCode::MediaQuotaExceeded->allowedParams();                     // ['limit']
ErrorCode::MediaQuotaExceeded->filterParams(['limit'=>30,'remaining'=>5]);
// ['limit' => 30]   ← 'remaining' sessizce düştü

// 🔴 Sızdırmayı DENE — imkânsız olmalı
new Q(30);   // Error: Call to private ... __construct()
```

```powershell
php artisan errors:export --check
# "Katalog guncel."  ← beklenen
```

**Mutasyon (kural 14):** `errorParams()`'ı her zaman `['limit' => 30]`
döndürecek şekilde değiştir. `guest_quota_rejection_does_not_leak_the_limit`
testi kırılmalı.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Adlandırılmış kurucu** | `new` yerine niyeti anlatan statik fabrika metodu |
| **`private __construct`** | Nesnenin yalnızca sınıfın kendi metotlarıyla üretilebilmesi |
| **Beyaz liste** | Varsayılan kapalı; yalnızca sayılanlar çıkabilir |
| **Katalog** | Hata kodlarının makine okunur dışa aktarımı |
| **Kapasite sınırı** | "Kaç tane" sınırı (403); hız sınırından (429) farklı |

---

## 9. Sırada ne var?

**6.6 — Doğrulama katmanı.** `MediaRequest` tabanı + sahibin ve misafirin
istekleri. Orada dosya boyutu ve **içerikten MIME doğrulaması** kuralları
`MediaKind`'dan beslenecek.

| İlgili | Nerede |
|---|---|
| Arayüz | [`HasErrorCode.md`](HasErrorCode.md) |
| Kardeş exception | [`RsvpQuotaExceededException.md`](RsvpQuotaExceededException.md) |
| Kod kataloğu | [`../Enums/ErrorCode.md`](../Enums/ErrorCode.md) |
