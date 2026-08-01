# `app/Console/Commands/ExportErrorCodes.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Console/Commands/ExportErrorCodes.php`
> **Yol haritasındaki yeri:** Faz 1, dosya 1.6 (fazın son kod dosyası)
> **Bağlantılı:** [`ErrorCode.md`](../../Enums/ErrorCode.md) ·
> [`docs/08-HATA-SOZLESMESI.md`](../../../../08-HATA-SOZLESMESI.md) §6

---

## 0. Bir dakikalık özet

`ErrorCode` enum'unu okuyup **JSON kataloğu** üretir:

```powershell
php artisan errors:export
```

```json
{
  "generatedAt": "2026-07-31T12:00:00+00:00",
  "count": 19,
  "codes": {
    "FILE_TOO_LARGE": { "status": 413, "params": ["max"], "retryable": false },
    "RATE_LIMITED": { "status": 429, "params": ["retryAfter"], "retryable": true }
  }
}
```

Frontend bu dosyadan çeviri anahtarlarını türetir ve **eksikleri tespit eder**.

---

## 1. Hangi problemi çözüyor?

Backend `PAYWALL_TIER_INSUFFICIENT` kodunu ekledi. Frontend'in `errors.tr.json`
dosyasında karşılığı yok. Kullanıcı ne görür?

- İyi ihtimalle ham kodu: *"PAYWALL_TIER_INSUFFICIENT"*
- Kötü ihtimalle boş bir hata kutusu

Sorun **iki repo arasındaki sessiz uyumsuzluktur**. Kimse hata yapmadı; sadece
kimse diğerine haber vermedi.

### Denenen ve terk edilen çözüm

`davetkart-contracts` adında paylaşılan bir TypeScript tip paketi kurulmuştu.
**Hiç doldurulmadı ve kaldırıldı** (`07` §8).

Paylaşılan paketin maliyeti: iki repo birbirine **bağımlı** hâle gelir. Backend'de
bir kod eklemek için paketi yayınlamak, sürüm numarasını yükseltmek ve frontend'de
güncellemek gerekir. Tek geliştiricili bir projede bu tören, faydasından ağırdır.

### Seçilen çözüm: tek yönlü üretim

```
ErrorCode.php  →  [artisan errors:export]  →  error-codes.json  →  (kopyala)  →  frontend
```

Frontend backend'e **bağımlı değildir**; sadece bir dosyayı okur. Bağımlılık yerine
**kopya** vardır. Kopya eskiyebilir — ama `--check` bayrağı (bkz. §3.3) bunu
yakalar.

---

## 2. Artisan komutu nasıl çalışır?

### 2.1 `$signature` — komutun arayüzü

```php
protected $signature = 'errors:export
                        {--path=storage/app/error-codes.json : Cikti dosyasi}
                        {--check : Yazma, yalnizca guncel mi diye bak}';
```

Laravel bu tek satırdan komutun **tüm arayüzünü** çıkarır. Sözdizimi:

| Yazım | Anlamı |
|---|---|
| `errors:export` | Komut adı. `:` ile gruplama — `make:`, `migrate:` gibi |
| `{arg}` | Zorunlu argüman |
| `{arg?}` | İsteğe bağlı argüman |
| `{--flag}` | Boolean bayrak — varsa `true` |
| `{--opt=varsayilan}` | Değer alan seçenek |
| `: aciklama` | `--help` çıktısında görünür |

Bu, **bildirimsel** (declarative) bir tasarımdır: ne istediğini yazarsın, ayrıştırma
işini framework yapar. Elle `$argv` okumak zorunda kalmazsın.

### 2.2 `handle()` ve çıkış kodu

```php
public function handle(): int
{
    ...
    return self::SUCCESS;   // 0
}
```

Komut çalıştığında `handle()` çağrılır. Dönüş değeri **işletim sistemi çıkış
kodudur**:

| Sabit | Değer | Anlamı |
|---|---|---|
| `self::SUCCESS` | 0 | Başarılı |
| `self::FAILURE` | 1 | Başarısız |

Neden önemli? CI sistemleri (GitHub Actions vb.) bir adımın başarılı olup
olmadığını **yalnızca** çıkış koduna bakarak anlar. Ekrana "hata" yazıp `0`
döndüren bir komut, CI'da sessizce geçer.

### 2.3 `$this->components->info()`

```php
$this->components->info('19 hata kodu disari aktarildi: ...');
```

Laravel'in biçimli konsol çıktısı. `$this->info()` de çalışır ama `components`
Laravel'in kendi komutlarıyla aynı görünümü verir (girintili, renkli, hizalı).

### 2.4 `File::` cephesi

```php
File::ensureDirectoryExists(dirname($absolute));
File::put($absolute, $json);
```

PHP'nin `mkdir()` / `file_put_contents()` fonksiyonlarının Laravel sarmalayıcısı.
`ensureDirectoryExists` "yoksa oluştur, varsa dokunma" der — `mkdir()` var olan
klasörde uyarı üretirdi.

---

## 3. Tasarım kararları

### 3.1 Katalog neden elle yazılmıyor?

```php
foreach (ErrorCode::cases() as $case) {
    $codes[$case->value] = [
        'status' => $case->status(),
        'params' => $case->allowedParams(),
        'retryable' => $case->isRetryable(),
    ];
}
```

`cases()` enum'un hazır gelen statik metodudur; tüm case'leri dizi olarak verir.

Kritik nokta: **katalogun tamamı enum'dan türer.** Bu komut hiçbir bilgiyi kendisi
bilmez. Enum'a yeni bir kod eklendiğinde bu dosyaya dokunmak gerekmez — çıktı
kendiliğinden değişir.

Alternatif (elle yazılan JSON) klasik "iki doğruluk kaynağı" tuzağıdır: bir gün
biri eşitlenmez ve fark **kullanıcı hata gördüğünde** ortaya çıkar.

### 3.2 `ksort()` — neden sıralıyoruz?

```php
ksort($codes);
```

Çıktıyı kod adına göre alfabetik sıralar. Sebep **diff gürültüsü**.

Enum'da bir case'i iki satır yukarı taşırsan (okunabilirlik için), sıralama olmadan
JSON çıktısındaki 19 satırın hepsi yer değiştirir. Git diff'i "19 satır değişti"
der — oysa hiçbir anlam değişmemiştir.

Sıralamayla çıktı **enum sırasından bağımsız** hâle gelir; diff yalnızca gerçek
değişiklikleri gösterir. Buna **deterministik çıktı** denir ve üretilen tüm
dosyalar için iyi bir alışkanlıktır.

### 3.3 🔴 `--check` bayrağı — kopyanın eskimesini yakalamak

```powershell
php artisan errors:export --check
```

Yazmaz; dosyanın **güncel olup olmadığına** bakar ve değilse `1` (FAILURE) döner.

Bu, §1'deki "kopya eskiyebilir" riskinin cevabıdır. **`composer check` zincirine
eklendi** (Faz 2 girişi kararı): enum'a kod eklenip katalog yenilenmediyse
`composer check` **kırılır**. Uyumsuzluk kullanıcıya değil geliştiriciye ulaşır.

```json
"check": [
    "@php vendor/bin/pint --test",
    "@php vendor/bin/phpstan analyse --memory-limit=1G",
    "@php artisan errors:export --check",
    "@php artisan test"
],
```

Sıra bilinçli: `--check` **testlerden önce** koşar. Testler dakikalar sürebilir;
saniyelik bir kontrolü öne almak geri bildirimi hızlandırır (fail fast).

> Bu, tekrar eden ilkenin bir örneği daha: **hatayı sola kaydırmak** (shift left).
> Faz 0 kılavuzundaki ok — yazarken saniyeler, üretimde günler.

### 3.4 `withoutTimestamp()` — neden gerekli?

```php
private function withoutTimestamp(string $json): string
{
    return (string) preg_replace('/^\s*"generatedAt".*$/m', '', $json);
}
```

`generatedAt` **her çalıştırmada** değişir. Karşılaştırmaya dahil edilseydi
`--check` hiçbir zaman "güncel" diyemezdi — dosya bir saniye önce üretilmiş olsa
bile.

Bu, üretilen dosyalarda klasik bir tuzaktır: zaman damgası insana faydalıdır ama
makine karşılaştırmasını bozar. Çözüm ya damgayı çıkarmak ya da hiç yazmamaktır.

> `/m` bayrağı "çok satırlı kip" demektir: `^` ve `$` metnin tamamının değil,
> **her satırın** başını ve sonunu eşler.

### 3.5 Çıktı neden `contracts/error-codes.json`?

**Karar (Faz 2 girişi):** Katalog **repoya işlenir**, konumu proje kökünde
`contracts/` klasörüdür.

Üç aday vardı:

| Konum | Sorun |
|---|---|
| `storage/app/error-codes.json` | `storage/app/.gitignore` içinde `*` var → **repoya girmez** |
| `docs/error-codes.json` | `docs/` bu projede **eğitim dokümanı** klasörü; üretilmiş veri oraya karışmamalı |
| **`contracts/error-codes.json`** ✅ | Adı ne olduğunu söylüyor: bu bir **sözleşme çıktısı** |

🔴 **Neden repoya işleniyor?** Çünkü `--check` `composer check` zincirine eklendi.
`storage/` altında kalsaydı, projeyi yeni klonlayan biri ilk `composer check`
çalıştırmasında *"Katalog dosyasi yok"* hatası alırdı — dosya git'te olmadığı için.

Bu iki karar birbirine bağlıdır:

```
--check zincirde  →  dosya repoda olmalı  →  gitignore'lu klasör olamaz
```

**Genel ilke:** Üretilen dosyalar normalde versiyon kontrolüne konmaz (aynı bilgiyi
ikinci kez saklamak). İstisna, dosyanın **başka bir sistemle sözleşme** olduğu
durumdur. Burada tüketici ayrı bir repodaki frontend'dir; `--check` kopyanın
eskimesini engelleyerek çift saklamanın riskini ortadan kaldırır.

> `contracts/` adı, kaldırılan `davetkart-contracts` TypeScript paketinin
> kavramsal yerini doldurur — ama bağımlılık değil, **kopya** üreterek.

### 3.6 `retryable` alanı neden katalogda?

`ErrorCode::isRetryable()` frontend için üretilmişti. Katalogda yer alması,
frontend'in durum kodu listesini **kendi tarafında tekrar yazmasını** önler:

```ts
if (catalog.codes[code].retryable) { retryWithBackoff(); }
```

Bilgi tek yerde tanımlanır (enum), iki yerde kullanılır.

---

## 4. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| Katalogu elle yazmak | İki doğruluk kaynağı, kaçınılmaz uyumsuzluk | `cases()` ile üret |
| `ksort()` atlamak | Anlamsız diff gürültüsü | Deterministik çıktı |
| `generatedAt`'i karşılaştırmaya katmak | `--check` hiç geçmez | Damgayı çıkar |
| Hata basıp `SUCCESS` dönmek | CI sessizce geçer | `self::FAILURE` |
| `mkdir()` doğrudan çağırmak | Var olan klasörde uyarı | `ensureDirectoryExists` |
| Çıktıya kod **metni** eklemek | K20 ihlali — metin frontend'in işi | Yalnızca `status`/`params` |
| Komutu `composer check`'e eklemeyi unutmak | Kopya sessizce eskir | `--check` bayrağını zincire ekle |

---

## 5. Kendin dene

```powershell
php artisan errors:export
php artisan errors:export --check          # "Katalog guncel."
php artisan list errors                    # komutu listede gör
php artisan errors:export --help           # signature'dan üretilen yardım
```

Çıktıyı incele:

```powershell
Get-Content storage\app\error-codes.json
```

**Kasten kır:** `app/Enums/ErrorCode.php`'ye geçici bir case ekle
(`case Deneme = 'DENEME';` + `status()` içinde `self::Deneme => 418,`). Sonra:

```powershell
php artisan errors:export --check
# "Katalog guncel degil. `php artisan errors:export` calistir."   → çıkış kodu 1
```

CI'ın kırılma anı budur. Case'i geri sil ve `--check`'in tekrar yeşile döndüğünü
doğrula.

Çıkış kodunu PowerShell'de görmek için:

```powershell
php artisan errors:export --check; $LASTEXITCODE
```

---

## 6. Sözlük

| Terim | Anlamı |
|---|---|
| **Artisan** | Laravel'in komut satırı aracı |
| **`$signature`** | Komutun adını, argüman ve seçeneklerini tanımlayan metin |
| **Çıkış kodu** | Komutun işletim sistemine döndürdüğü başarı/hata sayısı |
| **Bildirimsel (declarative)** | "Ne" istediğini yaz, "nasıl"ını araca bırak |
| **Deterministik çıktı** | Aynı girdiden her zaman birebir aynı çıktı |
| **Diff gürültüsü** | Anlam taşımayan ama diff'te görünen değişiklikler |
| **Shift left** | Hatayı yaşam döngüsünde mümkün olduğunca erkene çekmek |
| **CI** | Sürekli entegrasyon — her commit'te otomatik kontrol |
