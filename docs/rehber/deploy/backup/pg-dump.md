# `deploy/backup/pg-dump.sh`

> **Ne işe yarar:** Her gece veritabanının **mantıksal dökümünü** alır, dökümün açılabildiğini
> doğrular, yanına satır sayılarını taşıyan bir `manifest.json` yazar ve ikisini **ayrı bir S3
> bucket'ına** yükler. Sunucuda `/usr/local/sbin/davetkart-pg-dump` olarak durur, `root` çalıştırır.
> Faz 18.2 · `OPS-02` · K151–K156. Çalıştırma kitabı (bucket, IAM, kurulum, prova takvimi): docs
> deposu `operations/deployment/YEDEK-VE-GERI-YUKLEME.md`.

```bash
# yerelde deneme (S3'e gitmez, root istemez)
bash deploy/backup/pg-dump.sh --local /c/Yedek/deneme
```

## 1. Hangi felakete karşı?

Yedeğin iki ölçüsü vardır:

| Ölçü | Soru | Bu betikle |
|---|---|---|
| **RPO** (kurtarma noktası) | En kötü durumda ne kadar veri kaybederim? | 24 saat (gecede bir döküm; K157'de bilinçli kabul) |
| **RTO** (kurtarma süresi) | Ne kadar sürede geri dönerim? | Provada **ölçülür** ve plana yazılır (`restore-drill.ps1`) |

İki yedek katmanı iki ayrı felaketi çözer; biri diğerinin yerine geçmez:

| Katman | Çözdüğü felaket | Çözemediği |
|---|---|---|
| Lightsail disk görüntüsü (Y4.4, 7 gün) | "Sunucu yandı": bütün makine dünkü hâline döner | Tek tabloyu geri getiremez; görüntüler **aynı AWS hesabında** |
| `pg_dump` dökümü (bu betik, 30 gün) | "Veri bozuldu": hatalı göç, elle `DELETE`; **tek tablo** geri gelir | Medya dosyaları (onlar S3'te, sürümlemeyle korunur) |

Disk görüntüsüne dönmek, bozulmadan sonra gelen ödemeleri ve LCV'leri de siler. Döküm ise yan bir
veritabanına açılır, yalnızca bozulan satırlar oradan alınır.

## 2. Akış

```
ayar dosyası (root:600) ─► kilit (flock) ─► psql oturumu: BEGIN REPEATABLE READ
                                              │  pg_export_snapshot()  ──► anlık görüntü kimliği
                                              │  her tablo için count(*)        (aynı an)
                                              ▼
                                   pg_dump -Fc --snapshot=<kimlik>             (aynı an)
                                              ▼
              pg_restore --list  ─►  boyut > 0  ─►  zorunlu tabloların verisi listede mi?
                                              ▼
                                   manifest.json (sayılar, SHA-256, sürümler)
                                              ▼
        aws s3 cp --sse AES256:  önce .dump, sonra .manifest.json  ─►  kalp atışı (isteğe bağlı)
```

Herhangi bir adım hata verirse betik **sıfır dışı** kodla çıkar, geçici dizini siler ve (tanımlıysa)
başarısızlık adresine haber verir.

## 3. Kararlar ve gerekçeleri

### 3.1 Neden `pg_dump -Fc`? (K151)

`-Fc` "custom" biçimdir: sıkıştırılmış tek dosya ve içinde bir **içindekiler listesi** (TOC) var.
Düz SQL dökümünden farkı, `pg_restore`'un bu listeden seçerek geri yükleyebilmesidir:
`pg_restore -t orders` yalnızca `orders` tablosunu açar. `gzip` gerekmez; biçim zaten sıkıştırır.

Saat **02:00 UTC**: sunucu saati UTC (Y5.4), Lightsail görüntüsü 01:00'de, `media:prune-orphans`
03:15'te. `/etc/cron.d` sistem saatini kullanır; bu yüzden cron dosyasında da UTC yazar.

### 3.2 Neden bir "anlık görüntü oturumu"? (K155)

Manifestteki satır sayıları provada **tam eşitlik** ile karşılaştırılır. Sayıları dökümden bir
saniye önce ayrı bir bağlantıyla alsaydık, araya giren tek bir LCV provayı yanlış yere kırmızıya
çevirirdi. Yanlış alarm veren bir denetim bir süre sonra okunmaz.

PostgreSQL her işleme verinin **tutarlı bir fotoğrafını** gösterir (MVCC). `REPEATABLE READ`
işleminde bu fotoğraf işlem boyunca değişmez ve `pg_export_snapshot()` ile **başka bir bağlantıya
ödünç verilebilir**:

```sql
BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;
SELECT pg_export_snapshot();          -- örn. 0000001A-0000002F-1
SELECT count(*) FROM public."orders"; -- bu fotoğraftaki sayı
```

```bash
pg_dump -Fc --snapshot=0000001A-0000002F-1 ...   # AYNI fotoğrafı döker
```

Sayılar ve döküm aynı anı görür; arada kaç kayıt gelirse gelsin eşittirler. Paralel `pg_dump -j`
de işçilerini aynı mekanizmayla eşitler.

Oturum döküm bitene kadar **açık kalmalıdır** (fotoğrafı ödünç veren işlem kapanırsa kimlik
geçersiz olur). Bash'te bunu `coproc` sağlar: `psql` arka planda çalışır, betik ona bir borudan
sorgu yazar, diğerinden cevabı okur (`snap_ask`).

### 3.3 Neden `postgres` kullanıcısı ve peer kimlik doğrulama? (K154)

`runuser -u postgres -- pg_dump ...`: root, komutu `postgres` **sistem** kullanıcısı olarak
çalıştırır. PostgreSQL yerel soketten gelen bağlantıda "bağlanan sistem kullanıcısının adı,
istediği rolün adıyla aynı mı?" diye bakar (`pg_hba.conf`: `local … peer`, Y6.4d). Parola yok.

| Seçenek | Artı | Eksi |
|---|---|---|
| **`postgres` + peer** (seçilen) | Saklanacak, döndürülecek, sızacak bir parola **yok**; süper kullanıcı her nesneyi döker | Süper kullanıcı (yalnızca yerel soketten; TCP ile giriş yok) |
| `davetkart` rolü + `.pgpass` | En az yetkiye daha yakın | Veritabanı parolasının diskte **ikinci kopyası**; parola değişince yedek sessizce bozulur |

Parola hiçbir zaman komut satırına yazılmaz: komut satırı `ps` ile herkese görünür. `--no-password`
(`-w`) bağlantı parola isterse **sormak yerine hata verdirir**; cron'da soru soran komut asılı kalır.

### 3.4 Neden root, neden "kurulu kopya"?

AWS anahtarı yalnızca root'un okuyabildiği dosyada durur (K153), bu yüzden betiği root çalıştırır.
Ama depo `/var/www/davetkart/backend` altında ve sahibi `deploy`. Root oradaki dosyayı çalıştırsaydı
`deploy` kullanıcısı (ya da onu ele geçiren biri) dosyayı değiştirip **root yetkisi** alırdı. Bu
yüzden betik, cron ve sudoers dosyaları gibi, root'a ait bir yere **kopyalanır**:

```bash
sudo install -m 755 -o root -g root deploy/backup/pg-dump.sh /usr/local/sbin/davetkart-pg-dump
```

Bedeli: depodaki betik değişince kopya kendiliğinden güncellenmez. `deploy.sh` bunu yapamaz (root
değil). Fark var mı diye: `cmp deploy/backup/pg-dump.sh /usr/local/sbin/davetkart-pg-dump`.

### 3.5 Yalnızca yazabilen anahtar (K153)

Sunucudaki `davetkart-backup` kimliği tek bir şey yapabilir: `daily/` önekine nesne **koymak**.
Okuyamaz, listeleyemez, silemez. Sunucu ele geçirilse bile saldırgan eski yedekleri indiremez
(içlerinde misafir adları var) ve silemez. Uygulamanın anahtarı (`davetkart-app`, `.env`'de) bu
bucket'a hiç erişemez; yedek anahtarı da medya bucket'ına erişemez.

Betik bu yüzden yüklediği dosyayı geri **okuyamaz**: "S3'te gerçekten var mı?" sorusunu `aws s3 cp`
çıkış kodu ve senin kimliğinle yaptığın kontrol cevaplar.

Sınırı: `PutObject` var olan bir anahtarın **üstüne yazabilir**. Sürümleme açık olduğu için eski
içerik "eski sürüm" olarak 7 gün durur (K152); yani üstüne yazma saldırısını 7 gün içinde fark
etmen gerekir. Tam değişmezlik isteyen çözüm S3 Object Lock'tur (bugün kapsam dışı).

### 3.6 Doğrulama katmanları

"Yedek alınıyor sanılır, ilk ihtiyaçta dosyanın açılmadığı görülür" (denetim, senaryo C). Her
katman başka bir bozulmayı yakalar; tablo 16 Kasım 2026'daki mutant denemesinin sonucudur
(`FAZ-18.md` §18.2):

| Katman | Yakaladığı | Katman kaldırılınca |
|---|---|---|
| `set -e` + `pg_dump` çıkış kodu | Bağlantı, yetki, disk hatası | — |
| Zorunlu tablolar canlıda var mı | Yanlış veritabanı (`BACKUP_DB_NAME=postgres`) | — |
| `pg_restore --list` | Dosya bir döküm değil ya da başlığı bozuk | Dolu ama bozuk dosya **sessizce yüklendi** |
| Boyut > 0 | Boş dosya (`--list` de yakalar; ikinci ağ) | Boş dosyayı yine `--list` yakaladı |
| Zorunlu tabloların verisi TOC'ta mı | Geçerli ama **başka** veritabanının dökümü | Tablosuz döküm **sessizce yüklendi** |
| `--snapshot` | Sayılarla dökümün ayrı anları görmesi | Provada `orders` 4 ↔ 5: kırmızı |

`REQUIRED_TABLES` (`users invitations orders rsvps`) işin kalbindeki dört tablodur. Manifest ise
`public` şemasındaki **bütün** tabloları sayar: yeni bir tablo eklendiğinde betiğe dokunmak gerekmez.

### 3.7 Manifest

```json
{
  "schema": 1,
  "createdAt": "2026-11-17T02:00:03Z",
  "database": "davetkart",
  "serverVersion": "18.6",
  "pgDumpVersion": "18.6",
  "lastMigration": "2026_10_26_120000_add_user_agent_to_personal_access_tokens_table",
  "dump": { "file": "davetkart-20261117T020003Z.dump", "bytes": 66856, "sha256": "970c…", "tocEntries": 139 },
  "tables": { "invitations": 39, "orders": 24, "rsvps": 1, "users": 5 }
}
```

- `sha256` ve `bytes`: prova, indirilen dosyanın yolda bozulmadığını bununla denetler.
- `pgDumpVersion`: yerel `pg_restore` bundan **eski bir ana sürüm** olamaz.
- `lastMigration`: döküm hangi şemada? Geri yüklerken hangi kod sürümüyle uyumlu olduğunu söyler.
- Kişisel veri **içermez** (yalnızca sayılar); yine de dökümle aynı korumalı bucket'ta durur.

JSON elle (`printf`) yazılır çünkü sunucuda `jq` yok ve yeni bağımlılık istenmiyor. Güvenli olması
için dosyaya giren her değer önce bir desenle denetlenir (tablo adı `^[a-z_][a-z0-9_]*$`, sayı
`^[0-9]+$`). Aynı desen, tablo adının SQL'e eklenmesini de güvenli kılar.

**Yükleme sırası:** önce döküm, sonra manifest. Manifest varsa o günün yedeği **tamdır**; döküm
yüklenirken hata olursa manifest hiç gitmez.

### 3.8 Kalp atışı (K156)

Betik bitince, ayar dosyasında tanımlıysa bir adrese `curl` atar: başarıda `BACKUP_HEARTBEAT_URL`,
hatada `BACKUP_HEARTBEAT_FAIL_URL`. Asıl değer **gelmeyen** kalp atışıdır: cron hiç çalışmazsa ya
da sunucu kapalıysa betik hata bildiremez; izleme aracı "bu sabah ses gelmedi" diye uyarır
(ölü adam anahtarı). İki ayrı adres olmasının nedeni araçların farklı çalışması: Sentry Crons
`?status=ok` / `?status=error` ister, başka araçlarda hata için ayrı yol vardır; betik hiçbirini
varsaymaz.

Adres bir anahtar taşır, bu yüzden günlüğe **yazılmaz**. Kalp atışı gönderilemezse yedek başarısız
sayılmaz (uyarı yazılır). İzlemenin tamamı `OPS-03`'ün işidir.

## 4. Bash notları

| Satır | Anlamı |
|---|---|
| `set -Eeuo pipefail` | İlk hatada dur (`-e`), tanımsız değişkende dur (`-u`), boru zincirinde ilk hatayı say (`pipefail`), `ERR` tuzağı fonksiyonlarda da çalışsın (`-E`) |
| `umask 077` | Betiğin oluşturduğu her dosya yalnızca sahibine açık (`600`/`700`). Döküm kişisel veri taşır |
| `mktemp -d` + `trap on_exit EXIT` | Tahmin edilemez adlı geçici dizin; betik nasıl biterse bitsin silinir |
| `trap 'exit 143' TERM` | `kill` gelince de `EXIT` tuzağı çalışsın (temizlik + başarısızlık bildirimi) |
| `exec 9>"$LOCK_FILE"; flock -n 9` | 9 numaralı dosya tanıtıcısına kilit; ikinci koşu beklemeden hata verir. Süreç ölünce kilit kendiliğinden kalkar (PID dosyasının "bayat kilit" sorunu yok) |
| `"${BACKUP_PG_OS_USER-postgres}"` | `:-` değil `-`: değişken **boş** verilirse boş kalır (yerelde `runuser`'ı kapatmak için) |
| `coproc SNAP { … }` | Komutu arka planda başlatır; `${SNAP[1]}` ona yazılır, `${SNAP[0]}` ondan okunur |
| `read -t "$SNAP_TIMEOUT"` | Cevap gelmezse sonsuza kadar bekleme; süre dolunca hata |
| `set -a; . "$ENV_FILE"; set +a` | Dosyadaki her atama ortam değişkeni olur (`aws` anahtarı ortamdan okur) |

## 5. Ayar dosyası: `/etc/davetkart/backup.env`

Şablonu `deploy/backup/backup.env.example` (gerçek değer içermez). Betik, dosyanın sahibi `root`
ve izni `600` (ya da `400`) değilse **çalışmayı reddeder**: yanlışlıkla `644` bırakılan bir anahtar
dosyası sessiz bir sızıntıdır.

| Anahtar | Zorunlu | Ne |
|---|---|---|
| `BACKUP_S3_BUCKET` | ✅ | Yedek bucket'ının adı. Şablondaki yer tutucu bilerek geçersiz (büyük harf) |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION` | ✅ | `davetkart-backup` kimliği |
| `BACKUP_HEARTBEAT_URL`, `BACKUP_HEARTBEAT_FAIL_URL` | — | Kalp atışı (§3.8) |
| `BACKUP_DB_NAME` (`davetkart`), `BACKUP_PG_OS_USER` (`postgres`) | — | Varsayılanlar |

Dosyayı Bash okur: `&` ya da boşluk içeren değer **tek tırnak** içine alınır.

## 6. Kendin dene (yerelde, Git Bash)

Yerelde `runuser` ve root yoktur; betik bağlantıyı libpq ortam değişkenlerinden kurar.

```bash
cd /d/Projects/davetkart/davetkart-backend-php-laravel
export PATH="/c/Program Files/PostgreSQL/18/bin:$PATH"
export PGHOST=127.0.0.1 PGUSER=postgres
read -rs -p "Yerel postgres parolasi: " PGPASSWORD; export PGPASSWORD; echo
bash deploy/backup/pg-dump.sh --local /c/Yedek/deneme
cat /c/Yedek/deneme/*.manifest.json
```

Beklenen son satır: `TAMAM: … (… bayt, 21 tablo, … satir, 1 sn)`, çıkış kodu 0.

**Mutantı kendin dene:** sahte bir `pg_dump` bozuk dosya üretsin.

```bash
mkdir -p /tmp/sahte
printf '%s\n' '#!/usr/bin/env bash' \
  '[ "$1" = --version ] && exec "/c/Program Files/PostgreSQL/18/bin/pg_dump" --version' \
  'echo "PGDMP bozuk ama dolu bir dosya"' > /tmp/sahte/pg_dump

PATH="/tmp/sahte:$PATH" bash deploy/backup/pg-dump.sh --local /c/Yedek/deneme
# HATA: dokum okunamiyor: pg_restore --list basarisiz      (çıkış 1)

sed -e '/^pg_restore --list/d' -e 's/^    grep -q "TABLE DATA.*$/    :/' \
    -e 's/^TOC_ENTRIES=.*$/TOC_ENTRIES=0/' deploy/backup/pg-dump.sh > /tmp/mutant.sh
PATH="/tmp/sahte:$PATH" bash /tmp/mutant.sh --local /c/Yedek/deneme
# TAMAM: …                (çıkış 0)  ← doğrulama olmadan bozuk dosya "yedek" diye geçti
```

Asıl dosyaya dokunulmaz; mutant bir kopyadır.

## 7. Bu betik neyi yapmaz, neyi yakalamaz

- **Dökümün veri bloklarını açmaz.** `pg_restore --list` yalnızca içindekiler listesini okur.
  Ortası bozuk bir dosyayı ancak gerçek geri yükleme gösterir: **aylık prova bunun için var.**
- **Rolleri dökmez** (`pg_dumpall --globals-only` yok). Tek rol var (`davetkart`); geri yüklemede
  `--no-owner --no-acl` kullanılır, rol Y6.4'teki gibi yeniden oluşturulur.
- **Medya dosyalarını yedeklemez.** Onlar S3'te, sürümlemeyle korunur (Y3.3).
- **Dökümü istemci tarafında şifrelemez.** S3'te SSE-S3 ile durur; AWS hesabına giren okuyabilir.
- **24 saatten küçük RPO vermez.** Riskli bir göçten hemen önce elle bir kez daha çalıştır
  (`sudo /usr/local/sbin/davetkart-pg-dump`); sürekli arşiv (WAL/PITR) ayrı bir karar (K157).
- **Disk dolu mu diye bakmaz.** `pg_dump` yazamazsa hata verir, betik durur.
- **Alarm kurmaz.** Kalp atışı gönderir; alarmı kuran taraf `OPS-03`.

## 8. Sık hatalar

| Belirti | Sebep |
|---|---|
| `S3 kipi root ister` | `sudo` ile çalıştır ya da deneme için `--local <dizin>` |
| `… izinleri root:600 olmali` | `sudo chown root:root /etc/davetkart/backup.env && sudo chmod 600 …` |
| `gecersiz bucket adi (yer tutucu mu kaldi?)` | `BACKUP_S3_BUCKET` doldurulmamış |
| `gerekli arac bulunamadi: aws` | AWS CLI kurulu değil ya da cron `PATH`'inde değil |
| `Peer authentication failed for user "postgres"` | Betik root değil ya da `pg_hba.conf`'ta `local … peer` yok |
| `AccessDenied` (yüklemede) | IAM politikasındaki bucket adı ya da `daily/*` öneki yanlış |
| `baska bir yedek kosusu suruyor` | Önceki koşu bitmemiş: `pgrep -af davetkart-pg-dump` |
| `zorunlu tablo veritabaninda yok` | `BACKUP_DB_NAME` yanlış veritabanını gösteriyor |
| `veritabani oturumu cevap vermedi` | Tablo kilitli (uzun süren bir göç) ya da PostgreSQL kapalı |
| Yerelde `flock yok` uyarısı | Git Bash'te `flock` yok; yalnızca `--local` kipinde atlanır |
| Betik değişti, sunucuda eski davranıyor | Kurulu kopya güncellenmedi (§3.4): `install` komutunu yeniden çalıştır |

## 9. Sözlük

- **Mantıksal döküm:** Veritabanının içeriğini yeniden kuracak komutlar ve veri (dosya kopyası değil).
- **TOC:** `-Fc` dökümünün içindekiler listesi; `pg_restore --list` gösterir.
- **RPO / RTO:** Kabul edilen en büyük veri kaybı / geri dönüş süresi.
- **MVCC, anlık görüntü:** Her işlemin verinin tutarlı bir fotoğrafını görmesi.
- **Peer kimlik doğrulama:** Yerel sokette işletim sistemi kullanıcı adının rol adıyla eşleşmesi.
- **En az yetki:** Bir kimliğe yalnızca işini yapacak kadar izin vermek.
- **Ölü adam anahtarı:** Beklenen sinyal **gelmediğinde** çalan alarm.
- **SSE-S3:** S3'ün nesneyi kendi yönettiği anahtarla diskte şifrelemesi.
