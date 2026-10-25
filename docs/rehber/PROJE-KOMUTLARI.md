# DavetKart'a özel terminal komutları — kullanım kılavuzu

> **Kapsam:** Yalnızca bizim yazdığımız `php artisan` komutları. Laravel'in kendi
> komutları (`migrate`, `tinker`, `make:*` …) için: [`kavramlar/komutlar.md`](kavramlar/komutlar.md).
> **Güncel liste her zaman terminalde:** `php artisan list` · bir komutun ayrıntısı: `php artisan help <komut>`

---

## 0. Önce bunları bil

| Konu | Açıklama |
|---|---|
| **Nerede çalıştırılır?** | Backend klasöründe: `cd D:\Projects\davetkart\davetkart-backend-php-laravel`. Canlıda: sunucuya SSH ile bağlanıp proje klasöründe |
| **Hangi veritabanı etkilenir?** | Komutu nerede çalıştırırsan oranınki. Yerelde denediğin kampanya canlıda **açılmaz** |
| **Canlıda yazım** | Laravel üretim ortamında bazı komutlar onay sorar; bizimkiler sormaz. Yazmadan önce bir kez daha oku |
| **`--dry-run`** | Olan komutlarda: hiçbir şeyi değiştirmez, yalnızca ne olacağını söyler. Canlıda **ilk çalıştırma her zaman `--dry-run` ile** |
| **Tarihler** | `YYYY-MM-DD`; İstanbul saatine göre okunur (`2027-01-07` = 7 Ocak) |
| **Tutarlar** | TL olarak yazılır: `150` ya da `150.50` |
| **Kimlikler** | `01jb2k…` gibi 26 karakterlik ULID; ilgili `list` komutundan kopyalanır |

### Komutlar kodda nerede?

```
app\Console\Commands\       ← komutun adı, seçenekleri ve ekran çıktısı ($signature, handle())
app\Actions\…\              ← asıl iş (kurallar, veritabanı)
routes\console.php          ← kendiliğinden çalışanların takvimi
docs\rehber\app\Console\Commands\  ← her komutun ayrıntılı açıklaması
```

Laravel bu klasördeki komutları **kendiliğinden** tanır. Yeni komut: `php artisan make:command Ad`.
Kural: komut ince kalır, iş Action'da yapılır.

---

## 1. Fiyat kampanyaları (Faz 13)

> İşletme kılavuzu (fiyat, ilk yayın indirimi, ödeme yöntemleri):
> [`FIYAT-VE-INDIRIM-YONETIMI.md`](FIYAT-VE-INDIRIM-YONETIMI.md)

### 1.1 `pricing:campaign:start` — kampanya aç

```powershell
php artisan pricing:campaign:start <yüzde> [--tier=] [--from=] [--until=] [--label=]
```

| Argüman / seçenek | Zorunlu | Anlamı |
|---|---|---|
| `yüzde` | ✅ | 1–90. 100 olamaz: bedava yayın hediye kodunun işi |
| `--tier` | — | `standart` · `gold` · `elit`. Boş = bütün paketler |
| `--from` | — | Başlangıç günü (o günün başı). Boş = hemen. En erken bugün |
| `--until` | — | Son gün (o günün sonu). Boş = sen durdurana kadar |
| `--label` | — | Kendi notun (en fazla 120 karakter). Müşteri görmez |

```powershell
# Hemen başla, 7 Ocak'ın sonuna kadar, bütün paketlerde %20
php artisan pricing:campaign:start 20 --until=2027-01-07 --label="Yilbasi"

# 1 Kasım'da başla, yalnızca Gold, durdurulana kadar %30
php artisan pricing:campaign:start 30 --tier=gold --from=2026-11-01
```

- Bitiş tarihi gelince **kendiliğinden** biter; deploy ya da başka bir komut gerekmez.
- **İlk yayın indirimiyle (%60) toplanmaz**, büyük olan uygulanır. Komut bunu sana hatırlatır.
- Birden çok kampanya aynı anda açık olabilir; bir pakete uyanların **en büyüğü** uygulanır.

### 1.2 `pricing:campaign:list` — kampanyaları gör

```powershell
php artisan pricing:campaign:list             # hepsi, en yeni üstte (en fazla 50)
php artisan pricing:campaign:list --active    # yalnızca şu an geçerli olanlar
php artisan pricing:campaign:list --limit=10
```

Sütunlar: kimlik, yüzde, plan, başlangıç, bitiş, **ödenen sipariş** (bu kampanyadan indirim
alıp ödemesi tamamlanmış sipariş sayısı), durum (`GECERLI` · `baslamadi` · `bitti` ·
`durduruldu`), not. Tablonun altında ilk yayın indiriminin o anki değeri yazar.

### 1.3 `pricing:campaign:stop` — kampanyayı durdur

```powershell
php artisan pricing:campaign:stop 01jb2k...
```

Satır **silinmez**, durdurulur: ondan indirim almış siparişler ona bağlı kalır. İki kez
çalıştırmak zararsızdır (ilk durdurma anı değişmez).

---

## 2. Hediye kodları (Faz 11)

> Kodun kendisi veritabanında **saklanmaz** (yalnızca özeti ve son 4 karakteri). Ürettiğin
> kodu kaybedersen geri okuyamazsın; yalnızca kapatıp yenisini üretebilirsin.

### 2.1 `gift-codes:create` — elle belirlediğin bir kod

```powershell
php artisan gift-codes:create <kod> --benefit=<free|percent|fixed> [--tier=] [--percent=] [--amount=] [--max-uses=1] [--expires=] [--label=]
```

| Seçenek | Anlamı |
|---|---|
| `kod` | 4–32 karakter, A-Z ve 0-9. Boşluk ve tire atılır; büyük/küçük harf fark etmez |
| `--benefit` | `free` (paketi bedava açar) · `percent` (yüzde) · `fixed` (sabit TL) |
| `--tier` | `free` için **zorunlu**. `percent`/`fixed` için boş = bütün paketler |
| `--percent` | Yalnızca `percent` ile: 1–100 |
| `--amount` | Yalnızca `fixed` ile: TL (`150` ya da `150.50`) |
| `--max-uses` | Toplam kaç kez kullanılabilir (varsayılan 1). Kişi başı her zaman 1 |
| `--expires` | Son gün (o günün sonu). Boş = süresiz |
| `--label` | Not (ör. "Instagram cekilisi") |

```powershell
php artisan gift-codes:create DUGUN2026 --benefit=free --tier=gold --max-uses=10
php artisan gift-codes:create INSTA20 --benefit=percent --percent=20 --max-uses=500 --expires=2026-12-31
php artisan gift-codes:create SABIT150 --benefit=fixed --amount=150 --max-uses=50
```

Faz 13'ten beri kod, otomatik indirimden (kampanya / ilk yayın) **sonra kalan** fiyata
uygulanır. Örnek: 399 → %60 → 159,60 → %50'lik kod → 79,80 ₺.

### 2.2 `gift-codes:generate` — tek kullanımlık kodları toplu üret

```powershell
php artisan gift-codes:generate --count=<1-1000> --benefit=<free|percent|fixed> [--tier=] [--percent=] [--amount=] [--expires=] [--label=]
```

```powershell
php artisan gift-codes:generate --count=50 --benefit=free --tier=standart --label="Fuar"
```

- Kodlar `DK-7KQ2-M9XA-P4TR` biçiminde, **her biri tek kullanımlık**.
- Kodlar **yalnızca** bir CSV dosyasına yazılır: `storage\app\private\gift-codes\<batch>.csv`
  (komut tam yolu basar). Dosyayı indirip güvenli bir yere koy, sonra sunucudan sil.
  Veritabanından geri okunamaz.
- Çıktıdaki **Batch** kimliği, `gift-codes:list --batch=` ile o üretimin kodlarını gösterir.

### 2.3 `gift-codes:list` — kodları gör

```powershell
php artisan gift-codes:list                  # en yeni 50 kod
php artisan gift-codes:list --active         # şu an kullanılabilenler
php artisan gift-codes:list --batch=01jb...  # bir toplu üretimin kodları
php artisan gift-codes:list --limit=200
```

Sütunlar: kimlik, ipucu (`…P4TR`) ve not, fayda (`ucretsiz` · `%20` · `150,50 TL`), plan,
**aktif/max** kullanım, bitiş, durum (`aktif` · `kapali` · `suresi doldu` · `tukendi`).

"Aktif kullanım" ödenmiş ve ödenmekte olan siparişleri sayar. Başarısız ya da süresi dolmuş
ödemedeki kullanım hakkı **iade edilmiş** sayılır.

### 2.4 `gift-codes:disable` — kodu kapat

```powershell
php artisan gift-codes:disable 01jb2k...
```

Kod silinmez, kapatılır. Daha önce yapılmış kullanımlar geçerli kalır.

---

## 3. İşletme

### 3.1 `contact:list` — iletişim formu mesajları

Yönetim paneli yok; mesajlar bu komutla okunur.

```powershell
php artisan contact:list                     # en yeni 20 mesaj, ilk 80 karakter
php artisan contact:list --full              # mesajların tamamı
php artisan contact:list --since=2026-10-01  # bu tarihten sonrakiler
php artisan contact:list --limit=100
```

Mesajlar ziyaretçiden geldiği için terminali bozabilecek kontrol karakterleri görünür
hâle getirilerek basılır.

### 3.2 `users:normalize-emails` — e-postaları tek biçime getir

```powershell
php artisan users:normalize-emails --dry-run   # önce: ne değişecek, çakışma var mı
php artisan users:normalize-emails             # sonra: yaz
```

Kayıtlı e-postaları kanonik biçime getirir (büyük/küçük harf, Türkçe `İ` gibi). İki hesap
aynı adrese düşüyorsa (çakışma) o satırları **yazmaz**, raporlar; karar senindir.
Bir kez, yayından önce ya da toplu veri aktarımından sonra çalıştırılır.

---

## 4. Kendiliğinden çalışanlar (zamanlayıcı)

Bunları elle çalıştırman **gerekmez**. Sunucudaki zamanlayıcı (`schedule:run`, her dakika)
`routes\console.php`'deki takvime göre çalıştırır. Takvimi görmek için:

```powershell
php artisan schedule:list
```

| Komut | Ne zaman? (UTC) | Ne yapar? |
|---|---|---|
| `orders:expire` | Her saat | Ödeme penceresi (30 dk) dolmuş bekleyen siparişleri `expired` yapar. Geç gelen ödeme yine işlenir |
| `media:prune-orphans` | Her gün 03:15 | Hiçbir LCV yanıtına bağlanmamış misafir yüklemelerini (24 saatten eski) siler |
| `sanctum:prune-expired --hours=24` | Her gün | Süresi dolmuş giriş token'larını siler (Laravel'in komutu) |
| `data:purge` | Her gün 03:45 | Saklama süresi dolan kişisel veriyi siler (KVKK): çöp kutusundaki davetiye 30 gün, misafir verisi etkinlikten 6 ay, iletişim mesajı 12 ay |

Hepsinin `--dry-run` seçeneği var (`sanctum:prune-expired` hariç). Elle denemek istersen:

```powershell
php artisan orders:expire --dry-run
php artisan media:prune-orphans --dry-run
php artisan data:purge --dry-run
```

🔴 Canlıya alındığında `data:purge`'ün **ilk** çalıştırması elle ve `--dry-run` ile yapılır
(K84): neyin silineceğini gör, sonra zamanlayıcıya bırak.

Her iş başladığında ve bittiğinde Sentry'ye bildirim gönderir; zamanında çalışmazsa Sentry uyarır.

---

## 5. Geliştirici

### 5.1 `errors:export` — hata kodu kataloğu

```powershell
php artisan errors:export           # contracts/error-codes.json dosyasını yeniden yazar
php artisan errors:export --check   # yazmaz; dosya güncel değilse hata verir (composer check bunu kullanır)
```

`app\Enums\ErrorCode.php`'ye yeni bir kod eklediğinde çalıştırılır. Çıkan dosya frontend'e
**elle kopyalanır**: `davetkart-frontent\src\contracts\error-codes.json`.

### 5.2 `composer` betikleri (artisan değil ama her gün kullanılanlar)

```powershell
composer check     # pint --test + phpstan + errors:export --check + testler (commit öncesi)
composer analyse   # yalnızca phpstan
composer lint      # pint: kod biçimini DÜZELTİR
composer test      # yalnızca testler
```

`check` ve `analyse` PHPStan'in Windows'ta hatalı çalışan turbo eklentisini kapatır
(`PHPSTAN_TURBO=0`); ayrıntı [`phpstan.md`](phpstan.md).

---

## 6. Hızlı başvuru

| Ne istiyorsun? | Komut |
|---|---|
| %20 yılbaşı kampanyası | `pricing:campaign:start 20 --until=2027-01-07 --label="Yilbasi"` |
| Kampanyayı bitir | `pricing:campaign:list --active` → `pricing:campaign:stop <kimlik>` |
| Birine bedava Gold | `gift-codes:create HEDIYE01 --benefit=free --tier=gold` |
| 100 kişilik çekiliş kodu | `gift-codes:generate --count=100 --benefit=percent --percent=50` |
| Bir kodu iptal et | `gift-codes:list` → `gift-codes:disable <kimlik>` |
| Gelen mesajları oku | `contact:list --full` |
| Bir komutun bütün seçenekleri | `php artisan help <komut>` |

> Bu belgeye yeni bir komut eklenince: komutu yazan adım bu dosyayı da günceller.
