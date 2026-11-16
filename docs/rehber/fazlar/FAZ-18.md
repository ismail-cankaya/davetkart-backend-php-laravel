# FAZ 18 — Denetim düzeltmeleri (`AUD-2026-11-12`)

> **Başlangıç:** 16 Kasım 2026 · **Entegrasyon dalı:** `faz-18-audit-fixes` (`d810410` üstü; frontend'de `main`'deki `47aedc8` birleştirildi)
> **Durum:** sürüyor (2/6). Her bulgu ayrı bir `fix/…` dalında kapanır, `faz-18-audit-fixes`'e birleşir; faz sonunda `main` + `v1.1.0`.
> **Kaynak:** docs deposu `audits/2026-11-12-full-system-audit/REPORT.md` §1 ve §4 · takip tablosu `phases/faz-18/README.md`
> **Kararlar:** docs deposu `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-18.md`
> **Önceki:** [`FAZ-15.md`](FAZ-15.md). Faz 16–17'nin kaydı yok (`ARC-02`, bu fazın kapsamı dışında).

12–15 Kasım 2026 genel sistem denetimi altı yüksek (🟠) bulgu çıkardı. Hepsi yayından önce kapanır.

| Sıra | Bulgu | Konu | Dal | K | Durum |
|---|---|---|---|---|---|
| 18.1 | `OPS-01` | Yayına çıkacak commit ve dağıtım sırası; plan kuralları sözleşmesi (+ `TST-01`) | `fix/2026-11-16-ops-01-release-alignment` | K146–K150 | kod ✅ · `composer check` ✅ 1074 test · frontend `npm run check` ✅ · sunucu adımları koşmadı. Commit ve birleştirme durumu: docs deposu `phases/faz-18/README.md` |
| 18.2 | `OPS-02` | Veritabanı yedeği ve geri yükleme provası | `fix/2026-11-16-ops-02-db-backup` | K151–K157 | kod ✅ · yerel kuru koşu ve prova ✅ · `composer check` ✅ 1074 test · S3 yüklemesi, sunucu kurulumu ve üretim provası koşmadı (İsmail'in elle işleri, §18.2) |
| 18.3 | `SEC-01` | İlk yayın indirimi tek açık siparişe | — | — | bekliyor |
| 18.4 | `SEC-02` | Yayınlanmış hakkın iadesi | — | — | bekliyor |
| 18.5 | `SEC-03` | Shopier `callback` kipi üretimde reddedilir | — | — | bekliyor |
| 18.6 | `SEC-04` | Frontend güvenlik başlıkları + Faz 18 kapanışı | — | — | bekliyor |

---

## 18.1 — `OPS-01`: yayına çıkacak commit belirsiz; iki depo ayrı yükseltilirse premium şablonlar korumasız

### Sorun

Backend `main` (`03d3940`) 13 premium şablonu Gold'a bağlıyordu; denetlenen dal (`d810410`) dört commit
ilerideydi ve 32 şablonu bağlıyordu. Frontend'in denetlenen dalı `main`'den 71 commit ilerideydi ve aynı
32 şablonu "Gold+" diye gösteriyordu. İki depoda etiket yoktu. Frontend'in yeni şablonları backend'in
dört commit'inden önce yayına çıksaydı arayüz 32 şablonu premium gösterir, sunucu 19'unu tanımaz ve
Standart planla (249 ₺) yayınlatırdı; fark şablon başına 150 ₺. Ayrıca kalite kapısı bu dalda kırmızıydı
(`TST-01`, Pint): "doğrulanmış commit" hiçbir dalda yoktu. Kök neden tek bir hata değil, **bağın
yokluğuydu**: "hangi şablon hangi planda" kuralı iki depoda elle duruyordu ve ikisini birbirine
bağlayan ne bir dosya ne bir sıra kuralı vardı.

### Kararlar

| K | Karar (tek cümle) |
|---|---|
| **K146** | Plan kurallarının tek kaynağı `config/davetkart.php`; `php artisan plans:export` → `contracts/plan-rules.json` (fiyat ve zaman damgası yok, anahtarlar sıralı), `--check` `composer check` zincirinde. |
| **K147** | Frontend `npm run verify:plans`: `minimumTier`, fiyat kartı ve `getRequiredTier`, sözleşmenin **kopyasıyla** karşılaştırılır (hata kataloğuyla aynı taşıma). |
| **K148** | Dağıtım sırası: önce backend, sunucuda `plans:export --check` yeşilken frontend; tersine sıra yasak. |
| **K149** | Sürüm etiketle izlenir (`vMAJOR.MINOR.PATCH`, iki depoda aynı); deploy betikleri etiketi günlüğe yazar, etiketsiz ya da farklı etiket uyarı verir, durdurmaz. |
| **K150** | Kalite kapısı kırmızı olan dal yayın adayı değildir; `TST-01` bu dalda kapandı. |

### Değişen dosyalar

**Backend** (`fix/2026-11-16-ops-01-release-alignment`)

| Adım | Dosya | Ne | Kılavuz |
|---|---|---|---|
| 18.1.1 | `tests/Feature/TemplateCatalogueTest.php` | Pint: `FAZ17_PRESETS` sabiti metotlardan önceye taşındı (davranış aynı) | — |
| 18.1.2 | `app/Console/Commands/ExportPlanRules.php` | `plans:export` komutu (yeni) | [`ExportPlanRules.md`](../app/Console/Commands/ExportPlanRules.md) |
| 18.1.2 | `contracts/plan-rules.json` | Üretilen sözleşme (yeni): 3 plan, 6 modül, 32 şablon | aynı |
| 18.1.2 | `tests/Feature/PlanRulesContractTest.php` | 18 test (yeni) | [`PlanRulesContractTest.md`](../tests/Feature/PlanRulesContractTest.md) |
| 18.1.2 | `composer.json` | `check` zincirine `plans:export --check` | [`ExportPlanRules.md`](../app/Console/Commands/ExportPlanRules.md) §3.5 |
| 18.1.2 | `README.md` · `docs/rehber/PROJE-KOMUTLARI.md` · `docs/rehber/kavramlar/komutlar.md` | Komut listelerine yeni komut | — |
| 18.1.3 | `deploy/deploy.sh` · `deploy/deploy-frontend.sh` | Etiket günlüğü ve uyarı | [`deploy.md`](../deploy/deploy.md) Adım 1c · [`deploy-frontend.md`](../deploy/deploy-frontend.md) §1b |
| 18.1.4 | `docs/rehber/fazlar/FAZ-18.md` · `README.md` | Bu kayıt | — |

**Frontend** (`fix/2026-11-16-ops-01-release-alignment`)

| Adım | Dosya | Ne |
|---|---|---|
| 18.1.0 | `package.json` · `package-lock.json` | `main`'deki `47aedc8` birleştirmesinin çakışması çözüldü (birleştirme commit'i `f35aa2c`, `faz-18-audit-fixes`) |
| 18.1.5 | `src/contracts/plan-rules.json` | Sözleşmenin kopyası |
| 18.1.5 | `src/contracts/planRules.ts` | Tipli okuyucu + `MODULE_FIELDS` (kolon adı → davetiye alanı) |
| 18.1.5 | `scripts/verify-plan-rules.ts` | Denetim betiği |
| 18.1.5 | `docs/rehber/scripts/verify-plan-rules.md` | Kılavuz |
| 18.1.5 | `package.json` | `verify:plans` ve `verify` zinciri (düzeltme dalındaki tek dosya) |

⚠️ 18.1.5'in ilk dört dosyası düzeltme dalına değil, 18.1.0 birleştirme commit'ine (`f35aa2c`) girdi:
birleştirme commit'i atılırken çalışma ağacında izlenmeyen dosya olarak bekliyorlardı ve birlikte
eklendiler. İçerik aynı; yalnızca "hangi commit" farklı. O commit tek başına da yeşildir (betik
zincire `package.json` ile, düzeltme dalında bağlanır).

**Docs** (`docs/2026-11-16-faz-18-audit-fixes`): `operations/deployment/YAYIN-SIRASI.md` (çalıştırma kitabı),
`decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-18.md`, `decisions/DECISION-INDEX.md`,
`phases/faz-18/FAZ-18-OPS-01-COMMIT-KOMUTLARI.md` ve dizin satırları.

### Eklenen testler ve öldürdükleri mutantlar

| Test / denetim | Mutant | Sonuç |
|---|---|---|
| `PlanRulesContractTest::the_plan_rules_contract_is_up_to_date` + `plans:export --check` | `config/davetkart.php`'den `'dugun-gokyuzu' => 'gold',` satırı silindi | ✅ öldü: `--check` çıkış 1 ("guncel degil"), test kırmızı. Geri alındı; `git diff --stat -- config/davetkart.php` boş |
| `npm run verify:plans` (a) + (c) | Frontend kopyasından `"dugun-gokyuzu": "gold",` silindi | ✅ öldü: çıkış 1, 2 fark ("arayüzde gold+, sözleşmede YOK"). Kopya backend'den yeniden alındı (özet aynı) |
| `npm run verify:plans` (b) | `data.ts`'te Gold kartının "Fotoğraf galerisi" satırı `included: true` | ✅ öldü: çıkış 1 ("sözleşme (moduleTiers.show_gallery = elit) false diyor"). Geri alındı; `git diff --stat -- src/data.ts` boş |

`PlanRulesContractTest`'in diğer 17 testi `--check`'in altı kural türünü, dosyanın yokluğunu, biçimi,
determinizmi ve bozuk config'i kilitler ([`PlanRulesContractTest.md`](../tests/Feature/PlanRulesContractTest.md)).

### Doğrulama durumu (B7)

Makine: Windows 11, PHP 8.5.12, PostgreSQL (`davetkart_test`), Node 24.18.0, npm 11.16.0. Tarih: 16 Kasım 2026.

| Komut | Sonuç | Çıktıdan |
|---|---|---|
| `vendor/bin/pint --test` (başlangıç) | ❌ beklenen | `tests\Feature\TemplateCatalogueTest.php` · `ordered_class_elements` (`TST-01` hâlâ geçerliydi) |
| `composer check` (18.1.1 sonrası) | ✅ | pint `passed` · phpstan `errors: 0` · `Katalog guncel.` · phpunit `tests: 1056, passed: 1056, assertions: 3921` |
| `php artisan test --filter=PlanRulesContractTest` (komut yazılmadan) | ❌ beklenen (RED) | 18 test, 0 geçti: `The command "plans:export" does not exist.` |
| `php artisan test --filter=PlanRulesContractTest` | ✅ | `tests: 18, passed: 18, assertions: 68` |
| `php artisan plans:export --check` | ✅ | `Plan kurallari sozlesmesi guncel.` (çıkış 0) |
| `vendor/bin/pint --test` · `phpstan analyse` (18.1.2 sonrası) | ✅ | `passed` · `errors: 0` |
| `bash -n deploy/deploy.sh` · `deploy-frontend.sh` | ✅ | sözdizimi temiz (Git Bash) |
| `shellcheck` | koşmadı | kurulu değil |
| Etiket bloğu, sahte `git` ile (7 durum) | ✅ | etiketsiz → uyarı, durmaz · aynı etiket → uyarı yok · farklı etiket → uyarı |
| Deploy betikleri **sunucuda** | koşmadı | canlı sisteme istek atılmadı; ilk koşu `v1.1.0` yayınında |
| `composer check` (son, 18.1.2–18.1.3 sonrası) | ✅ | pint `passed` · phpstan `errors: 0` · `Katalog guncel.` · `Plan kurallari sozlesmesi guncel.` · phpunit `tests: 1074, passed: 1074, assertions: 3989` |
| Frontend 18.1.0: `npm install` · `npm ci` · `npm run check` | ✅ | çıkış 0 · `added 155 packages … found 0 vulnerabilities` · `npm run check` çıkış 0 |
| Frontend: `node scripts/run-check.mjs scripts/verify-plan-rules.ts` | ✅ | `32 premium şablon iki tarafta aynı (toplam 373 şablon)` · `3 kartın bütün satırları…` · `501 durumda…` (çıkış 0) |
| Frontend: `tsc --noEmit` (yeni dosyalarla) | ✅ | çıkış 0 |
| Frontend (düzeltme dalı, `f35aa2c` + `package.json`): `npm ci` | ✅ | `added 155 packages … found 0 vulnerabilities` (çıkış 0) |
| Frontend: `npm run verify:plans` | ✅ | `✓ Plan kuralları backend sözleşmesiyle aynı: premium şablonlar, fiyat kartı, gereken plan hesabı.` (çıkış 0) |
| Frontend: `npm run check` (lint → build → verify) | ✅ | çıkış 0 · `✓ built in 21.82s` · zincirde `verify:plans` koştu (`verify:pricing` ile `verify:render` arasında) |
| Frontend: iki mutant, `npm run verify:plans` ile | ✅ öldü | ikisinde de çıkış 1; geri alındıktan sonra `git diff --stat` yalnızca `package.json` (2 ekleme, 1 silme) |
| Sözleşme kopyası ↔ backend dosyası (`Compare-Object`) | ✅ | `AYNI` |

### Elle doğrulama (İsmail)

1. Backend'de `composer check`: son satır `"result":"passed"`.
2. Mutantı kendin dene: [`ExportPlanRules.md`](../app/Console/Commands/ExportPlanRules.md) §5.
3. Frontend'de `npm run verify:plans`; iki mutant: frontend `docs/rehber/scripts/verify-plan-rules.md` §6.
4. İki sözleşme dosyası aynı mı: docs deposu `operations/deployment/YAYIN-SIRASI.md` §2, 4. kontrol.
5. `v1.1.0` yayınında (Faz 18 sonu): `YAYIN-SIRASI.md` §4. Günlükte `Surum: … (v1.1.0)` ve
   `[frontend] Surum: … (v1.1.0) | backend: v1.1.0` satırlarını gör; arada sunucuda
   `plans:export --check` yeşil olmalı.

### Plandan sapmalar

- **Sözleşmede zaman damgası yok.** Plan "desen `errors:export` ile birebir aynı" ve "çıktı deterministik"
  diyordu; ikisi `generatedAt`'te çelişir. Determinizm seçildi: `--check` düz bayt karşılaştırması.
- **`deploy-frontend.sh` de değişti.** Plan "gerekirse" diyordu. Frontend kendi commit'ini ayrı günlüğe
  yazıyor ve tek başına çalıştırılabiliyor; tehlikeli sıra o yoldan geldiği için backend'in etiketini de
  yazıp fark varsa uyarıyor.
- **18.1.0'daki "dur" yalnızca frontend dalı için uygulandı.** Backend ve docs adımları frontend
  birleştirme commit'ini beklemeden yürüdü; frontend'in yeni dosyaları o sırada izlenmeyen dosya olarak
  hazırlandı ve birleştirme commit'ine girdi (yukarıdaki ⚠️). Bu kayıt 18.1.4'te commit'lendikten sonra
  frontend sonucuyla güncellendi (18.1.7).
- **Geri alma `git reset` ile değil `main` üzerinden.** `deploy.sh` her çalıştığında `git pull` yaptığı
  için sunucuda `reset` kalıcı değil; `YAYIN-SIRASI.md` §5 yeni bir commit'le dönüşü anlatıyor
  (komutlar denenmedi).

### Gördüm, dokunmadım

| Bulgu | Ne |
|---|---|
| `SEC-07` | `composer audit` kırmızı; etiketten önce kapanmalı (kontrol listesinde 2. madde) |
| `ARC-02` | Faz 16–17'nin faz kaydı yok |
| `ARC-08` | Dal temizliği (onlarca eski dal, iki depoda) |
| `ARC-01` | `DECISION-INDEX.md` K128–K145'i içermiyor; yalnızca ⚠️ notu eklendi |
| `FE-05` | Arayüz kuralları hâlâ elle kopyalı; `verify:plans` ayrışmayı yakalar, kopyayı kaldırmaz. Tam çözüm kuralları API'den okumak |
| — | `docs/rehber/deploy/deploy.md` §4'teki eski geri alma (`git reset --hard` + `deploy.sh`) `git pull` yüzünden yeni sürüme geri sarar; yanına ⚠️ notu kondu, iki komut satırı değiştirilmedi |
| — | `.github/workflows/ci.yml` iş adı "Pint + PHPStan + errors:export + testler" diyor; `plans:export` adı geçmiyor (iş `composer check` koştuğu için denetim CI'da da çalışır) |
| — | `deploy-frontend.sh` sözleşme kopyalarını karşılaştırıp fark varsa **durmuyor**; öneri `YAYIN-SIRASI.md` §7'de, karar bekliyor |

---

## 18.2 — `OPS-02`: veritabanı yedeği ve geri yükleme provası yok

### Sorun

Tek yedek Lightsail'in günlük disk görüntüsüydü (son 7 gün, aynı AWS hesabında). Mantıksal döküm
yoktu ve geri yükleme hiç denenmemişti; yayın planındaki Y11.5 (gece `pg_dump` → S3) ve Y11.6 (prova)
yapılmamıştı, `deploy/` içinde yedek betiği yoktu. Hatalı bir göç ya da elle çalıştırılan bir `DELETE`
siparişleri bozsaydı tek tablo geri getirilemezdi; disk görüntüsüne dönmek aradaki ödemeleri ve LCV'leri
de silerdi. Canlıda henüz müşteri verisi yok: açığı kapatmanın en ucuz anı şimdi. Kök neden kod değil,
**eksik bir katmandı**: disk görüntüsü "sunucu yandı"yı çözer, "veri bozuldu"yu çözmez; ve denenmemiş
bir yedek yalnızca bir umuttur.

### Kararlar

| K | Karar (tek cümle) |
|---|---|
| **K151** | Her gece 02:00 UTC'de `pg_dump -Fc` (tek tablo `pg_restore -t` ile geri gelir); ayrı cron dosyası, `root`; çakışan koşu betiğin içindeki `flock` ile engellenir. |
| **K152** | Hedef, medya bucket'ından **ayrı** bir S3 bucket'ı: Block Public Access açık, sürümleme açık, SSE-S3; geçerli sürüm 30 gün, eski sürüm 7 gün (etkili saklama en çok ~37 gün). |
| **K153** | Sunucudaki kimlik yalnızca **yazabilir** (`s3:PutObject`, `daily/*`); anahtar `/etc/davetkart/backup.env` (root:600, betik izni denetler); betik depodan değil root'a ait kurulu kopyadan çalışır. |
| **K154** | Veritabanına `postgres` sistem kullanıcısı ve peer kimlik doğrulamayla bağlanılır; parola hiçbir dosyada ve komut satırında yok. |
| **K155** | Döküm yüklenmeden doğrulanır (`pg_restore --list`, boyut, zorunlu tabloların verisi) ve yanına `manifest.json` yazılır; satır sayıları dökümle **aynı anlık görüntüden** alınır (`pg_export_snapshot` + `--snapshot`). |
| **K156** | İzleme asgari: ayar dosyasında tanımlıysa başarı ve başarısızlık için iki kalp atışı adresi; adres günlüğe yazılmaz; alarmın tamamı `OPS-03`. |
| **K157** | Kabul ölçütü: kayıt açılmadan önce üretim dökümüyle bir prova ve ölçülen süre plana yazılır; sonra ayda bir prova; RPO 24 saat bilinçli kabul, WAL/PITR ayrı karar. |

### Değişen dosyalar

**Backend** (`fix/2026-11-16-ops-02-db-backup`)

| Adım | Dosya | Ne | Kılavuz |
|---|---|---|---|
| 18.2.1 | `deploy/backup/pg-dump.sh` | Yedek betiği (yeni) | [`pg-dump.md`](../deploy/backup/pg-dump.md) |
| 18.2.1 | `deploy/backup/backup.env.example` | Ayar dosyası şablonu (yeni; gerçek değer yok) | aynı, §5 |
| 18.2.2 | `deploy/cron/davetkart-backup` | Cron satırı (yeni) | [`davetkart-backup.md`](../deploy/cron/davetkart-backup.md) |
| 18.2.3 | `deploy/backup/restore-drill.ps1` | Geri yükleme provası ve tek tablo kipi (yeni) | [`restore-drill.md`](../deploy/backup/restore-drill.md) |
| 18.2.4 | `docs/rehber/fazlar/FAZ-18.md` · `README.md` | Bu kayıt | — |

PHP kodu, göç, test ve bağımlılık **değişmedi**. Frontend değişmedi.

**Docs** (`docs/2026-11-16-faz-18-audit-fixes`): `operations/deployment/YEDEK-VE-GERI-YUKLEME.md`
(çalıştırma kitabı: bucket, IAM politikası, sunucu kurulumu, prova takvimi), `YAYIN-PLANI.md` ve
`YAYIN-SIRASI.md` bağlantıları, `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-18.md`,
`decisions/DECISION-INDEX.md`, `phases/faz-18/FAZ-18-OPS-02-COMMIT-KOMUTLARI.md` ve dizin satırları.

### Denemeler ve öldürdükleri mutantlar

Betiklerin PHPUnit testi yok (PHP değil). Denemeler yerelde, Git Bash + PostgreSQL 18.4 ile, bu
oturumda kurulup kaldırılan `davetkart_backup_selftest` veritabanına karşı koştu. Bozuk dökümleri
`PATH`'in başına konan **sahte bir `pg_dump`** üretti. Mutantlar asıl dosyada değil, `sed` ile
üretilen **kopyalarda** uygulandı (dosya henüz izlenmediği için `git diff --stat` bir şey
göstermezdi); asıl dosyanın SHA-256'sı koşulardan önce ve sonra aynıydı (`65a94a843db99757…`).

| # | Betik | Verilen döküm | Sonuç | Anlamı |
|---|---|---|---|---|
| A | asıl | gerçek | çıkış 0 · `TAMAM` | taban |
| B | asıl | dolu ama bozuk dosya | çıkış 1 · `dokum okunamiyor: pg_restore --list basarisiz` | `--list` yakaladı |
| C | asıl | boş dosya | çıkış 1 · aynı mesaj | `--list` boş dosyayı da yakalar |
| D | **M-list** (`--list` bloğu silindi) | dolu ama bozuk dosya | çıkış **0** · `TAMAM` | ✅ mutant öldü: doğrulama olmadan bozuk dosya sessizce geçiyor |
| E | M-list | boş dosya | çıkış 1 · `dokum dosyasi bos` | boyut denetimi ikinci ağ |
| F | **M-listcode** (`\|\| fail` → `\|\| true`) | dolu ama bozuk dosya | çıkış 1 · `dokumde 'users' tablosunun verisi yok` | TOC denetimi ikinci ağ |
| G | **M-size** (boyut denetimi silindi) | boş dosya | çıkış 1 · `--list` mesajı | ⚠️ mutant **hayatta**: `--list` varken boyut denetimi tek başına hiçbir şey yakalamıyor (yedek ağ; D5'te sayıldığı için kaldı) |
| H | asıl | başka veritabanının geçerli dökümü | çıkış 1 · `dokumde 'users' tablosunun verisi yok` | TOC denetimi yakaladı |
| I | **M-toc** (TOC denetimi silindi) | başka veritabanının geçerli dökümü | çıkış **0** · `TAMAM` | ✅ mutant öldü: tablosuz döküm "yedek" diye geçiyor |
| J | asıl | `BACKUP_DB_NAME=postgres` | çıkış 1 · `zorunlu tablo veritabaninda yok: users` | yanlış veritabanı |
| K | asıl | döküm sırasında `INSERT` | manifest `orders: 5`, prova `5 = 5`, çıkış 0 | anlık görüntü çalışıyor (canlıda o an 6 satır vardı) |
| L | **M-nosnap** (`--snapshot` silindi) | döküm sırasında `INSERT` | manifest `orders: 6`, prova `6 ≠ 7` `FARK`, çıkış 1 | ✅ mutant öldü: prova yakaladı |

Prova betiğinin olumsuz durumları (hepsi çıkış 1): hedef veritabanı `davetkart` (koruma) · dökümün
sonuna bir bayt eklendi (`boyut farkli`) · aynı boyutta tek bit değişti (`SHA-256 farkli`) ·
manifestte `orders` 24 → 25 (`FARK`) · uzantısı `.dump` olmayan dosya · geçersiz JSON manifest.

### Doğrulama durumu (B7)

Makine: Windows 11, Git Bash (bash 5.3.15), PostgreSQL 18.4 (yerel), Windows PowerShell 5.1. Tarih: 16 Kasım 2026.

| Komut | Sonuç | Çıktıdan |
|---|---|---|
| `bash -n deploy/backup/pg-dump.sh` | ✅ | sözdizimi temiz (çıkış 0) |
| `shellcheck` | koşmadı | kurulu değil |
| Satır sonu: `git ls-files --eol --others` | ✅ | dört yeni dosya `w/lf` |
| `pg-dump.sh --local` (yerel `davetkart`) | ✅ | `TAMAM: … (66856 bayt, 21 tablo, 233 satir, 1 sn)` · çıkış 0 · geçici dizin silindi |
| `DRY_RUN=1 pg-dump.sh` | ✅ | `backup-out/` altında `.dump` + `.manifest.json` |
| `pg-dump.sh` parametresiz, root değil | ✅ beklenen | `HATA: S3 kipi root ister …` · çıkış 1 |
| Mutant koşuları A–L | ✅ | yukarıdaki tablo |
| Kılavuzdaki "mutantı kendin dene" komutları | ✅ | asıl: çıkış 1 · mutant: çıkış 0 |
| `restore-drill.ps1 -Dump` (yerel döküm) | ✅ | 21 tablo `ok` · `Geri yukleme : 0.3 sn (21 tablo, 233 satir)` · çıkış 0 |
| `restore-drill.ps1 -OnlyTable orders` | ✅ | `orders 24 24 ok` · yan veritabanı `davetkart_restore_side` · çıkış 0 |
| Prova: altı olumsuz durum | ✅ | hepsi çıkış 1, mesajlar yukarıda |
| S3 kolu, **sahte** `aws`/`id`/`runuser`/`stat`/`flock`/`curl` ile (7 durum) | ✅ (sahte araçla) | başarı: `flock -n 9` → `runuser -u postgres -- psql` → `… pg_dump` → `aws s3 cp … .dump --sse AES256` → `aws s3 cp … .manifest.json` → kalp atışı `status=ok` · izin `root:644`, yer tutucu bucket, yükleme hatası, dolu kilit, bozuk döküm: çıkış 1 · kalp atışı gönderilemedi: çıkış 0 + uyarı · yedi günlükte de sahte anahtar ve adres **yok** |
| Gerçek S3 yüklemesi, bucket, IAM politikası | koşmadı | canlı sisteme istek atılmadı; bu makinede `aws` yok |
| Gerçek `flock` kilidi | koşmadı | Git Bash'te `flock` yok; yalnızca dal sahte araçla sınandı |
| Root + `runuser` + peer kimlik doğrulama | koşmadı | Linux sunucu yok; yerelde libpq ortamıyla bağlanıldı |
| Ayar dosyası izin denetimi (gerçek `stat`) | koşmadı | Windows'ta sahip/izin anlamlı değil; sahte `stat` ile sınandı |
| Cron dosyası | koşmadı | cron yok; dosya okundu |
| `restore-drill.ps1 -S3Uri` (indirme kolu) | koşmadı | `aws` yok |
| Gerçek kalp atışı isteği | koşmadı | sahte `curl` |
| `composer check` (dalın sağlığı) | ✅ | pint `passed` · phpstan `errors: 0` · `Katalog guncel.` · `Plan kurallari sozlesmesi guncel.` · phpunit `tests: 1074, passed: 1074, assertions: 3989` |

⚠️ Yerelde ölçülen 0,3 saniye **geliştirme verisinin** (66 KB) süresidir, üretimin RTO'su değil.
K157'nin istediği prova üretim dökümüyle yapılır.

### Elle doğrulama (İsmail)

Sıra ve komutlar: docs deposu `operations/deployment/YEDEK-VE-GERI-YUKLEME.md`.

1. Yerelde kuru koşu ve prova: [`pg-dump.md`](../deploy/backup/pg-dump.md) §6,
   [`restore-drill.md`](../deploy/backup/restore-drill.md) §6. Mutantı kendin dene: `pg-dump.md` §6.
2. AWS: yedek bucket'ı ve `davetkart-backup` kimliği (çalıştırma kitabı §2–§3).
3. Sunucu: AWS CLI kurulumu (**karar senin**, §4.1), ayar dosyası, betiğin kurulu kopyası, elle ilk
   koşu (`TAMAM`), sonra cron dosyası (§4).
4. Olumsuz deneme: sunucudan `aws s3 ls` ve `aws s3 cp s3://… .` **reddedilmeli** (§5).
5. Ertesi sabah: `journalctl -t davetkart-backup --since today` ve S3'te o günün iki dosyası.
6. İlk üretim provası; süreyi çalıştırma kitabı §6'daki prova kaydına yaz. **Kayıt açılmadan önce.**
7. Prova veritabanlarını sil (aşağıda).

Bu oturumdan kalan yerel veritabanları (içlerinde geliştirme verisinin kopyası var; istenince silinir):

```powershell
& 'C:\Program Files\PostgreSQL\18\bin\dropdb.exe' -h 127.0.0.1 -p 5432 -U postgres davetkart_restore_drill
& 'C:\Program Files\PostgreSQL\18\bin\dropdb.exe' -h 127.0.0.1 -p 5432 -U postgres davetkart_restore_side
```

### Plandan sapmalar

- **İki kalp atışı adresi (D6 tek değişken diyordu).** `BACKUP_HEARTBEAT_URL` başarıda,
  `BACKUP_HEARTBEAT_FAIL_URL` hatada çağrılır. Tek adrese `?status=error` eklemek Sentry'de çalışır,
  başka araçlarda hatayı **başarı** diye bildirirdi; betik hiçbir aracı varsaymıyor.
- **Manifest dört tabloyu değil `public` şemasının tamamını sayar (D5 "önemli tablolar" diyordu).**
  Dört tablo "zorunlu" olarak ayrıca denetlenir. Elle tutulan liste yeni tabloyla birlikte eskirdi.
- **Anlık görüntü eşitlemesi ve TOC denetimi eklendi (D5'te yoktu).** İlki provanın tam eşitlik
  arayabilmesi için (mutant L), ikincisi "geçerli ama yanlış döküm"ü aynı gece yakalamak için (mutant I).
- **Betik depodan değil kurulu kopyadan çalışır.** Root, `deploy` kullanıcısının yazabildiği bir
  dosyayı çalıştırırsa `deploy` fiilen root olur. Kurulum bu yüzden `deploy.sh`'ta değil, çalıştırma
  kitabında (cron ve sudoers dosyalarıyla aynı yol).
- **`backup.env.example` eklendi (adım tablosunda yoktu).** Ayar dosyasının biçimi depoda dursun diye;
  18.2.1'in commit'inde.
- **Dosya adları zaman damgalı** (`davetkart-<damga>.dump` / `.manifest.json`; D5 `manifest.json`
  diyordu): aynı gün iki koşu birbirini ezmesin, indirilen dosyalar klasörde karışmasın.
- **AWS CLI için durulmadı (§8 son madde).** Plan kaydında sunucuda `aws` yok. Betik kurulum
  yönteminden bağımsız olduğu için iş sürdü; seçenekler çalıştırma kitabı §4.1'de, karar İsmail'in.
- **§6'daki mutant birebir uygulanınca kırmızı kalıyor.** "`--list`'i kaldır, **boş** dosya ver"
  koşusunu (E) boyut denetimi yakalıyor. `--list`'in değerini gösteren koşu dolu ama bozuk dosyadır (D).

### Gördüm, dokunmadım

| Bulgu | Ne |
|---|---|
| `SEC-11` | Gizlilik metni yedek süresini söylemiyor. Yazılırken sayı **30 değil ~37 gün** olmalı: geçerli sürüm 30 günde sona erer, eski sürüm 7 gün daha durur (K152); Lightsail görüntüsü ayrıca 7 gün |
| `OPS-03` | İzleme ve alarm. Kalp atışı adresleri boş; `/api/ping` veritabanına dokunmuyor |
| `OPS-04` | `docs/10-URETIM-ENV-SABLONU.md` sunucudaki `.env`'den sapmış. Yedek ayarları bilerek oraya değil ayrı dosyaya kondu |
| — | `deploy.sh`, sunucudaki **kurulu kopyaların** (cron, sudoers, yedek betiği) depodakiyle aynı olduğunu denetlemiyor. Öneri: deploy sonunda `cmp` ile uyarı (durdurmadan) |
| — | Yedekler istemci tarafında şifrelenmiyor ve bucket'ta Object Lock yok: `PutObject` var olan anahtarın üstüne yazabilir, eski sürüm 7 gün durur (K153'ün sınırı). İleride ayrı karar |
| — | `YAYIN-PLANI.md` §1 şeması ve Y3.3 yedekler için hâlâ "ayrı önek / `backups/` 30 gün" diyor; karar Y3.1 kaydında "ayrı bucket"a dönmüştü. Geçmiş satırlar yeniden yazılmadı |
| `ARC-01` | `DECISION-INDEX.md` K128–K145'i hâlâ içermiyor |
