# `deploy/backup/restore-drill.ps1`

> **Ne işe yarar:** Geri yükleme **provası**. Bir dökümü kendi bilgisayarında boş bir prova
> veritabanına açar, satır sayılarını `manifest.json` ile karşılaştırır, süreyi yazar. Tablolar
> farklıysa sıfır dışı kodla çıkar. İkinci kipi (`-OnlyTable`) tek bir tabloyu yan veritabanına açar.
> Faz 18.2 · `OPS-02` · K157. Yedeği üreten betik: [`pg-dump.md`](pg-dump.md).

```powershell
# Windows'ta, depo kökünde
$env:PGPASSWORD = Read-Host 'Yerel postgres parolasi'
powershell -ExecutionPolicy Bypass -File deploy\backup\restore-drill.ps1 -Dump C:\Yedek\davetkart-20261117T020003Z.dump
```

## 1. Neden prova?

**Denenmemiş yedek bir umuttur.** Yedek betiği dosyanın *başlığını* doğrular; dosyanın gerçekten
bir veritabanına dönüşüp dönüşmediğini yalnızca geri yükleme gösterir. Prova üç soruyu cevaplar:

1. Döküm **açılıyor mu**? (`pg_restore` hatasız bitiyor mu)
2. İçinde **beklenen veri** var mı? (her tablonun satır sayısı manifestle aynı mı)
3. Geri dönmek **ne kadar sürüyor**? (RTO'nun ölçülen parçası; plana yazılır)

Üçüncüsü çoğu zaman unutulur. "Yedeğimiz var" ile "40 dakikada geri döneriz" farklı cümlelerdir;
ikincisini ancak ölçen söyleyebilir.

## 2. Neden PowerShell?

Betiği sen, Windows'ta çalıştıracaksın. Gereken iki şey, JSON okumak ve süre ölçmek, Windows
PowerShell 5.1'de **hazır** (`ConvertFrom-Json`, `Stopwatch`, `Get-FileHash`). Git Bash'te JSON
için `jq` kurmak gerekirdi. Tek dış bağımlılık zaten kurulu olan PostgreSQL araçları
(`pg_restore`, `psql`, `createdb`, `dropdb`); betik onları `PATH`'te, yoksa
`C:\Program Files\PostgreSQL\<sürüm>\bin` altında arar.

Betikteki metinler bilerek ASCII: PowerShell 5.1, BOM'suz dosyadaki Türkçe karakterleri bozar.

## 3. Adımlar

| # | Adım | Neden |
|---|---|---|
| 0 | Hedef veritabanı adını denetle | Adında `_restore_` geçmeyen veritabanı **asla** silinmez |
| 1 | Dosyaları bul (`-Dump`) ya da S3'ten indir (`-S3Uri`) | — |
| 2 | Manifesti oku; dosya boyutu ve SHA-256 manifestle aynı mı | İndirirken bozulan ya da yanlış eşleşen dosyayı geri yüklemeden yakalar |
| 3 | Sürüm uyumu | Yerel `pg_restore` ve yerel sunucu, dökümü alan **ana sürümden** eski olamaz |
| 4 | `dropdb --if-exists` + `createdb` | Her prova **boş** veritabanından başlar; önceki provanın kalıntısı sonucu kirletmez |
| 5 | `pg_restore --no-owner --no-acl` (süre ölçülür) | Aşağıda |
| 6 | Her tablo için `count(*)` ↔ manifest | `ok` / `FARK` / `EKSIK` (manifestte var, geri gelmedi) / `FAZLA` |
| 7 | Özet + çıkış kodu | 0 = hepsi aynı; 1 = `pg_restore` hatası ya da en az bir fark |

**`--no-owner --no-acl`:** Döküm, nesnelerin sahibini (`davetkart` rolü) ve izinlerini de taşır.
Senin bilgisayarında o rol yok; bu iki bayrak "sahiplik ve izin komutlarını atla, her şey bağlanan
kullanıcının olsun" der. Prova verinin geri gelip gelmediğini sınar, izin düzenini değil.

**Sayılar neden tam eşit olmalı?** Manifestteki sayılar dökümle **aynı anlık görüntüden** alınır
([`pg-dump.md`](pg-dump.md) §3.2). "Yaklaşık aynı" diye bir sonuç yok: tek satır fark gerçek bir
sorundur.

**Prova veritabanı sonda bırakılır.** İçine bakmak isteyebilirsin. Betik silme komutunu yazar;
bir sonraki prova zaten baştan oluşturur. İçinde gerçek müşteri verisi olacağını unutma: işin
bitince sil.

## 4. Dosyaları nereden alırım?

Sunucudaki kimlik yedekleri **okuyamaz** (K153). İndirme senin kimliğinle yapılır:

- **Konsoldan (önerilen, ek kurulum yok):** S3 → yedek bucket'ı → `daily/YYYY/MM/DD/` → iki dosyayı
  (`.dump` ve `.manifest.json`) seç → **Download (İndir)**. İkisini aynı klasöre koy; betik manifesti
  dökümün yanında arar.
- **AWS CLI ile:** `-S3Uri s3://<bucket>/daily/2026/11/17/davetkart-20261117T020003Z.dump`
  (`-AwsProfile <ad>` isteğe bağlı). Bilgisayarında yönetici anahtarı saklamayı gerektirir; kısa
  ömürlü oturum (SSO) kullanmıyorsan konsol yolu daha güvenli. ⚠️ Bu kol 16 Kasım 2026'da
  **denenmedi** (bu makinede `aws` yok).

## 5. Senaryo A: tek tabloyu kurtarmak

Hatalı bir göç ya da elle çalıştırılan bir `DELETE` siparişleri bozdu. Bütün veritabanını düne
döndürmek istemezsin: aradaki ödemeler ve LCV'ler de giderdi.

```powershell
powershell -ExecutionPolicy Bypass -File deploy\backup\restore-drill.ps1 `
    -Dump C:\Yedek\davetkart-20261117T020003Z.dump -OnlyTable orders
```

Bu, `orders` tablosunu **yan veritabanına** (`davetkart_restore_side`) açar ve satır sayısını
manifestle karşılaştırır. Canlı veritabanına dokunmaz. Gerisi **elle** ve dikkatle:

```sql
-- yan veritabanında: dünkü hâl
SELECT id, status, amount_minor, paid_at FROM orders ORDER BY created_at DESC LIMIT 20;
```

Kurtarma yolunu olay belirler; betik bu kararı senin yerine vermez:

| Durum | Yol |
|---|---|
| Birkaç satır silinmiş | Yan veritabanından o satırları `INSERT` olarak çıkar, gözden geçir, canlıda bir **transaction** içinde uygula |
| Bir kolon topluca bozulmuş | Yan tabloyu canlıya geçici adla kopyala (`orders_restore`), `UPDATE … FROM` ile yalnızca bozulan kolonu geri yaz |
| Tablo tamamen gitmiş | Tabloyu dökümden geri yükle, sonra dökümden **sonra** gelen kayıtları (Shopier paneli, günlükler) elle tamamla |

🔴 Canlıda her zaman önce `BEGIN`, sonra etkilenen satır sayısını kontrol et, sonra `COMMIT`.
Kurtarmadan **hemen önce** bozuk hâlin de bir dökümünü al (`sudo /usr/local/sbin/davetkart-pg-dump`):
kurtarma yanlış giderse dönecek bir nokta olsun.

`pg_restore -t` yalnızca tablonun tanımını ve verisini getirir; indeksler, yabancı anahtarlar ve
tetikleyiciler **gelmez**. Yan veritabanında veriye bakmak için bu yeterlidir.

## 6. Kendin dene (yerel dökümle, S3 olmadan)

```bash
# Git Bash: yerel geliştirme veritabanının dökümünü al (pg-dump.md §6)
bash deploy/backup/pg-dump.sh --local /c/Yedek/deneme
```

```powershell
# PowerShell: aynı dökümü provaya ver
$env:PGPASSWORD = Read-Host 'Yerel postgres parolasi'
$dump = (Get-ChildItem C:\Yedek\deneme -Filter *.dump | Sort-Object Name | Select-Object -Last 1).FullName
powershell -ExecutionPolicy Bypass -File deploy\backup\restore-drill.ps1 -Dump $dump
$LASTEXITCODE     # 0
```

**Kırmızıyı gör:** manifestin bir kopyasında bir sayıyı değiştir (ör. `"orders": 24` → `25`),
dökümün kopyasıyla aynı klasöre koy, provayı yeniden çalıştır: `orders … FARK`, çıkış kodu 1.

## 7. Sık hatalar

| Belirti | Sebep |
|---|---|
| `yerel PostgreSQL'e baglanilamadi` | `$env:PGPASSWORD` boş ya da yanlış; servis çalışmıyor |
| `prova veritabaninin adinda '_restore_' gecmeli` | `-Database` ile gerçek bir veritabanı adı verilmiş. Koruma bilerek katı |
| `SHA-256 farkli` | Dosya yarım indi ya da manifest başka bir günün dökümüne ait |
| `yerel PostgreSQL ana surumu dokumunkinden eski` | Sunucu 18, bilgisayarın 17: PostgreSQL 18 araçlarını kur |
| `unsupported version … in file header` | Aynı sebep: `pg_restore` dökümden eski |
| `role "davetkart" does not exist` | `--no-owner` kaldırılmış; betikteki bayraklara dokunma |
| Betik hiç çalışmıyor ("running scripts is disabled") | `powershell -ExecutionPolicy Bypass -File …` biçiminde çağır |
| `FARK` ama veri doğru görünüyor | Manifest ile döküm farklı koşulardan. Dosya adlarındaki zaman damgası aynı olmalı |

## 8. Bu betik neyi sınamaz

- **Canlıya geri dönüşü.** Prova yerelde yapılır; sunucuda yeni bir veritabanına geri yükleyip
  uygulamayı ona bağlamak ayrı bir adımdır (çalıştırma kitabı §7).
- **Verinin doğruluğunu.** Sayılar aynıysa satırlar geri gelmiştir; içeriklerinin doğru olduğunu
  (ör. tutarlar) göstermez. Prova sonunda birkaç satıra gözle bak.
- **Medyayı.** Fotoğraflar ve videolar veritabanında değil S3'te.
- **Toplam RTO'yu.** Ölçülen süre geri yüklemenin kendisi. Fark etme, karar verme, indirme ve
  uygulamayı yeniden bağlama süresi buna eklenir.

## 9. Sözlük

- **Prova (drill):** Felaket olmadan, felaket anında yapılacak işi baştan sona denemek.
- **Yan veritabanı:** Canlıya dokunmadan dökümü açıp içine bakmak için kurulan geçici veritabanı.
- **SHA-256:** Dosyanın parmak izi; tek bayt değişirse tamamen değişir.
- **Çıkış kodu:** Programın "başardım" (0) ya da "başaramadım" (0 dışı) demesinin yolu.
