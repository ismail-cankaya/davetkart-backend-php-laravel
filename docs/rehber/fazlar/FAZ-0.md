# Faz 0 — Zemin ve Kalite Kapıları

> **Durum:** ✅ Tamamlandı · 31 Temmuz 2026
> **Yazılan dosya:** 6 · **Yazılan kılavuz:** 6
> **Bitiş ölçütü:** `composer check` yeşil

---

## 1. Fazın amacı

**Tek cümle:** Kod yazmaya başlamadan önce, *yanlışı anında söyleyen* araçları
kurmak.

Bu fazda **hiçbir iş kodu yazılmadı** — tek satır endpoint, model veya migration
yok. Kurulan şey **zemin**: veritabanı, kalite araçları ve hata sözleşmesi.

### Neden önce zemin?

Bir hatanın maliyeti, keşfedildiği ana göre katlanarak artar:

```
Yazarken bulunursa      →  saniyeler
Testte bulunursa        →  dakikalar
İncelemede bulunursa    →  saatler
Üretimde bulunursa      →  günler + itibar
```

Faz 0'ın tamamı bu okun **sol tarafına** yatırımdır. Statik analiz, katı model
kipi ve test ortamı; hatayı müşteriden önce sana gösteren mekanizmalardır.

### Öğrenme hedefleri

| Soru | Cevabın bulunduğu kılavuz |
|---|---|
| Ortam değişkeni nedir, neden koda yazılmaz? | [`env.md`](../env.md) |
| Statik analiz nedir, testten farkı ne? | [`phpstan.md`](../phpstan.md) |
| Biçimlendirici neden gerekli? | [`pint.md`](../pint.md) |
| Test ortamı neden ayrılır? | [`phpunit.md`](../phpunit.md) |
| N+1 sorgusu nasıl exception'a çevrilir? | [`app/Providers/AppServiceProvider.md`](../app/Providers/AppServiceProvider.md) |
| Hata neden metin değil kod olarak döner? | `docs/08-HATA-SOZLESMESI.md` |

---

## 2. Hedefler ve sonuçlar

| # | Hedef | Sonuç |
|---|---|---|
| 0.1 | PHP `pdo_pgsql` sürücü kontrolü | ✅ |
| 0.2 | PostgreSQL kurulumu | ✅ **Sürüm 18** |
| 0.3 | `davetkart` + `davetkart_test` veritabanları | ✅ pgAdmin ile |
| 0.4 | `.env` düzenlemesi | ✅ `.env.example` ile senkron |
| 0.5 | Bağlantı doğrulama (`migrate`) | ✅ 9 tablo |
| 0.6 | SQLite'ı kaldırma | ✅ |
| 0.7 | Dil dosyaları | ⚠️ **Kapsam değişti** — K21 ile backend tek dil |
| 0.8 | Pint yapılandırması | ✅ `pint.json` |
| 0.9 | Larastan kurulumu | ✅ `phpstan.neon`, level 5 |
| 0.10 | Test ortamı | ✅ `phpunit.xml` → PostgreSQL |
| 0.11 | `AppServiceProvider` sıkılaştırma | ✅ 3 ayar |
| — | **Hata sözleşmesi** (plan dışı) | ✅ `docs/08-HATA-SOZLESMESI.md` |

---

## 3. Yazılan dosyalar

| Dosya | İşi | Kılavuz |
|---|---|---|
| `.env` | Ortama özel ayarlar ve sırlar | [`env.md`](../env.md) |
| `.env.example` | Repodaki şablon — değerler boş | (aynı) |
| `pint.json` | Kod biçimlendirici kuralları | [`pint.md`](../pint.md) |
| `phpstan.neon` | Statik analiz yapılandırması | [`phpstan.md`](../phpstan.md) |
| `phpunit.xml` | Test ortamı ve katılık bayrakları | [`phpunit.md`](../phpunit.md) |
| `app/Providers/AppServiceProvider.php` | Çalışma anı sıkılaştırma | [`AppServiceProvider.md`](../app/Providers/AppServiceProvider.md) |
| `composer.json` | `lint` / `analyse` / `check` scriptleri | — |
| `docs/08-HATA-SOZLESMESI.md` | API hata sözleşmesi | — |
| `claude/Notlar/03-FRONTEND-YAPILACAKLAR.md` | Frontend'e düşen iş | — |

---

## 4. Kurulan kurallar

Bu bölüm fazın **kalıcı çıktısıdır**. Aşağıdaki kurallar bundan sonraki her
fazda geçerlidir.

### 4.1 Ortam ve yapılandırma

| # | Kural | Gerekçe |
|---|---|---|
| **Y1** | Kod içinde **`env()` çağrılmaz**, `config()` çağrılır | `config:cache` sonrası `env()` sessizce `null` döner |
| **Y2** | `env()` yalnızca `config/` dosyalarında geçer | Derleme anında `.env` hâlâ okunabilir durumdadır |
| **Y3** | Repoya giren hiçbir dosyaya **sır yazılmaz** | `phpunit.xml`, `config/*.php` repodadır; sadece `.env` değildir |
| **Y4** | Yeni ortam anahtarı **`.env` ve `.env.example`'a birlikte** eklenir | Şablon eskirse yeni geliştirici çalışmayan projeyle başlar |
| **Y5** | `DB_HOST` olarak **`127.0.0.1`** yazılır, `localhost` değil | Windows'ta `localhost` önce IPv6 (`::1`) dener |

### 4.2 Veritabanı

| # | Kural | Gerekçe |
|---|---|---|
| **V1** | Üç ortamda da **PostgreSQL 18** | Dev/prod parity — 12-Factor X |
| **V2** | Test **ayrı veritabanında** koşar (`davetkart_test`) | `RefreshDatabase` her koşuda tabloları siler |
| **V3** | Üretimde `migrate:fresh`/`db:wipe` **yasak** | `DB::prohibitDestructiveCommands()` ile yapısal engel |
| **V4** | Migration'lar sürüme özgü SQL kullanmaz | Barındırıcı farklı bir PostgreSQL sürümü sunabilir |

### 4.3 Kod kalitesi

| # | Kural | Gerekçe |
|---|---|---|
| **K1** | Her PHP dosyası **`declare(strict_types=1)`** ile başlar | Sessiz tip dönüşümü hata gizler; Pint otomatik ekler |
| **K2** | Commit öncesi **`composer check`** yeşil olmalı | Biçim + statik analiz + test tek kapıda |
| **K3** | Riskli Pint kuralları (`strict_comparison` vb.) **kapalı** | Biçimlendirici görünümü değiştirir, **anlamı değiştirmez** |
| **K4** | `ignoreErrors`'a satır eklemek için **gerekçe yorumu zorunlu** | Aksi halde araç zamanla anlamını yitirir |
| **K5** | PHPStan seviyesi **kademeli yükselir** | Faz 2 → 6 · Faz 5 → 8 · Faz 9 → 8+ |
| **K6** | Yeni araç için gereken bayrak **`composer.json`'a** yazılır | "Bende çalışıyor" hatalarını önler |

### 4.4 Test

| # | Kural | Gerekçe |
|---|---|---|
| **T1** | Her Feature testi **`RefreshDatabase`** kullanır | Testler birbirinin verisini görmemeli |
| **T2** | **Assertion'sız test yasak** | `failOnRisky` yakalar; yeşil yanar ama hiçbir şey doğrulamaz |
| **T3** | Testte **çıktı üretilmez** (`dd()`, `echo`) | `beStrictAboutOutputDuringTests` yakalar |
| **T4** | Testler **üretim kipinde** koşar (`APP_DEBUG=false`) | Sızıntı testleri yazılabilsin |
| **T5** | Test **metin değil davranış** doğrular | Metin frontend'in işi; backend testi kod/durum/alan bilir |

### 4.5 Çalışma anı sıkılaştırma

| # | Kural | Gerekçe |
|---|---|---|
| **S1** | Katı model kipi **geliştirmede açık, üretimde kapalı** | Hata laptop'ta patlar, müşteri isteğini düşürmez |
| **S2** | İlişkili veri **`with()` ile** çekilir | N+1: 100 kayıt = 101 sorgu |
| **S3** | Modellerde **`$guarded = []` yasak** | Sadece `$fillable` beyaz listesi |
| **S4** | Tarihler **`CarbonImmutable`** | Mutasyon, LCV son tarihi hesabını sessizce bozar |

### 4.6 API hata sözleşmesi (K20 · K21)

| # | Kural | Gerekçe |
|---|---|---|
| **H1** | Backend **kullanıcıya gösterilecek metin döndürmez** | Sunum kararı frontend'in; dil orada zaten var |
| **H2** | Hata zarfı: `{ error: { code, fields?, params? } }` | Tek biçim, tek ayrıştırma noktası |
| **H3** | `error.debug` **yalnızca `APP_DEBUG=true`** iken üretilir | Üretimde kod hiç çalışmaz — unutulamaz |
| **H4** | Hata kodları **`ErrorCode` enum'unda**, sihirli string yok | Yazım hatası anında yakalanır |
| **H5** | Kod adı yayınlandıktan sonra **sözleşmedir** | Yeniden adlandırmak frontend çevirisini kırar |
| **H6** | Auth hatalarında **`fields` dönmez** | Kullanıcı sayımı (enumeration) açığı |
| **H7** | Sahiplik yoksa **404**, 403 değil | 403 kaynağın varlığını doğrular |
| **H8** | Yığın izi, SQL, sağlayıcı hatası **yanıta girmez** | Yalnızca log |
| **H9** | `params` **beyaz listeyle** verilir | İç sayaçlar (`remaining`) sadece kaynağın sahibine |

### 4.7 Belgeleme

| # | Kural | Gerekçe |
|---|---|---|
| **B1** | Kodda **kısa yorum**, detay `docs/rehber/` altında | Kod okunur kalır, öğrenme içeriği aranabilir olur |
| **B2** | Kılavuz yolu **kod yolunu birebir** yansıtır | `app/Enums/X.php` → `docs/rehber/app/Enums/X.md` |
| **B3** | Her faz sonunda bu klasöre **özet** yazılır | `docs/rehber/fazlar/FAZ-N.md` |

---

## 5. Faz boyunca alınan kararlar

| # | Karar | Durum |
|---|---|---|
| **K9'** | Üretimde PostgreSQL | ✅ Sürüm **18** |
| **K19** | Geliştirme ve testte de PostgreSQL | ✅ Uygulandı |
| **K20** | **Hata sözleşmesi** — kod döner, metin dönmez | ✅ Tasarlandı, Faz 1'de uygulanacak |
| **K21** | **Backend tek dil konuşur** (`en`) | ✅ Uygulandı |
| **K22** | PHPStan seviyesi kademeli yükselir (5 → 8) | ✅ Takvim belirlendi |
| **K23** | `CarbonImmutable` varsayılan tarih sınıfı | ✅ Uygulandı |

### Geçersiz kılınanlar

| Ne | Yerine |
|---|---|
| `APP_LOCALE=tr` | `en` (K21) |
| `lang/tr/validation.php` | Silindi — API metin döndürmüyor |
| Faz 8 `SetLocaleFromHeader` middleware | İptal (K21) |
| Testte SQLite `:memory:` | PostgreSQL `davetkart_test` (K19) |

---

## 6. Ortaya çıkan araç zinciri

```
composer lint        →  pint            →  biçim düzeltir
composer analyse     →  phpstan         →  tip/mantık hatası raporlar
php artisan test     →  phpunit         →  davranış doğrular
composer check       →  üçü birden      →  faz bitiş kapısı
```

**Üçünün iş bölümü:**

| Araç | Sorusu | Ne zaman çalışır |
|---|---|---|
| Pint | "Bu kod çirkin mi yazılmış?" | Yazarken |
| PHPStan | "Bu kod hiçbir durumda patlar mı?" | Yazarken |
| PHPUnit | "Bu senaryoda doğru davranıyor mu?" | Yazdıktan sonra |

Hiçbiri diğerinin yerine geçmez. PHPStan test edilmeyen kod yollarını da görür;
test ise gerçek veriyle karşılaşır.

---

## 7. Günlük çalışma ritmi

```
1. Komut         → php artisan make:*
2. Kod           → kısa yorumlarla
3. Kılavuz       → docs/rehber/<mimari-yol>/<dosya>.md
4. Doğrulama     → composer check
5. DUR           → onay bekle
```

Commit öncesi:

```powershell
composer lint     # biçimlendir
composer check    # üç kapıdan geçir
git commit
```

---

## 8. Faz 1'e devir

**Hazır olanlar:** PostgreSQL bağlantısı · kalite araç zinciri · test ortamı ·
katı model kipi · hata sözleşmesi tasarımı.

**Faz 1'de yazılacaklar:**

| Dosya | İşi |
|---|---|
| `app/Enums/ErrorCode.php` | Hata kodu kataloğu (K20) |
| `app/Http/Middleware/ForceJsonResponse.php` | API her zaman JSON döner |
| `bootstrap/app.php` | Middleware kaydı + **exception handler** (hata zarfı) |
| `routes/api.php` | `GET /api/ping` |
| `tests/Feature/HealthTest.php` | İlk test |
| `app/Console/Commands/ExportErrorCodes.php` | `php artisan errors:export` |

**Faz 1 bitiş ölçütü:** Tarayıcıda `http://localhost:8000/api/ping` → JSON.
Bilinmeyen rotada HTML değil `{ "error": { "code": "RESOURCE_NOT_FOUND" } }`.

---

## 9. Terim özeti

| Terim | Anlamı |
|---|---|
| **12-Factor App** | Bulut uygulamaları için 12 maddelik metodoloji |
| **dev/prod parity** | Ortamların benzer olması ilkesi (X. faktör) |
| **Statik analiz** | Kodu çalıştırmadan hata arama |
| **N+1 sorgu** | 1 ana + her kayıt için 1 ek sorgu |
| **Eager loading** | İlişkili veriyi `with()` ile önceden çekme |
| **Mass assignment** | Diziden toplu alan doldurma |
| **Immutable** | Değişmez nesne — metotlar yeni örnek döndürür |
| **User enumeration** | Hata farkından kayıtlı hesapları tespit etme açığı |
| **Idempotans** | Aynı işlemin tekrarının tek etki üretmesi |
| **Walking skeleton** | Uçtan uca çalışan en küçük dilim |

---

## 10. Bağlantılar

| İlgili | Nerede |
|---|---|
| Yol haritası | `docs/07-GELISTIRME-YOL-HARITASI.md` |
| Hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` |
| Kod standartları | `CLAUDE.md` |
| Proje devir dosyası | `claude/PHP-LARAVEL-SETUP.md` |
| Frontend'e düşen iş | `claude/Notlar/03-FRONTEND-YAPILACAKLAR.md` |
