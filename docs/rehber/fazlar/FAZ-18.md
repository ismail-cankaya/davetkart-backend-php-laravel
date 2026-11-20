# FAZ 18 — Denetim düzeltmeleri (`AUD-2026-11-12`)

> **Başlangıç:** 16 Kasım 2026 · **Entegrasyon dalı:** `faz-18-audit-fixes` (`d810410` üstü; frontend'de `main`'deki `47aedc8` birleştirildi)
> **Durum:** kod tamam (6/6, 20 Kasım 2026) · **`v1.1.0` etiketi bekliyor: `composer audit` kırmızı (`SEC-07`)**. Her bulgu ayrı bir `fix/…` dalında kapanır, `faz-18-audit-fixes`'e birleşir; faz sonunda `main` + `v1.1.0`.
> **Kaynak:** docs deposu `audits/2026-11-12-full-system-audit/REPORT.md` §1 ve §4 · takip tablosu `phases/faz-18/README.md`
> **Kararlar:** docs deposu `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-18.md`
> **Önceki:** [`FAZ-15.md`](FAZ-15.md). Faz 16–17'nin kaydı yok (`ARC-02`, bu fazın kapsamı dışında).

12–15 Kasım 2026 genel sistem denetimi altı yüksek (🟠) bulgu çıkardı. Hepsi yayından önce kapanır.

| Sıra | Bulgu | Konu | Dal | K | Durum |
|---|---|---|---|---|---|
| 18.1 | `OPS-01` | Yayına çıkacak commit ve dağıtım sırası; plan kuralları sözleşmesi (+ `TST-01`) | `fix/2026-11-16-ops-01-release-alignment` | K146–K150 | kod ✅ · `composer check` ✅ 1074 test · frontend `npm run check` ✅ · sunucu adımları koşmadı. Commit ve birleştirme durumu: docs deposu `phases/faz-18/README.md` |
| 18.2 | `OPS-02` | Veritabanı yedeği ve geri yükleme provası | `fix/2026-11-16-ops-02-db-backup` | K151–K157 | kod ✅ · yerel kuru koşu ve prova ✅ · `composer check` ✅ 1074 test · S3 yüklemesi, sunucu kurulumu ve üretim provası koşmadı (İsmail'in elle işleri, §18.2) |
| 18.3 | `SEC-01` | İlk yayın indirimi tek açık siparişe | `fix/2026-11-16-sec-01-first-publish-once` | K158–K163 | kod ✅ · `composer check` ✅ 1126 test · frontend `npm run check` ✅ · 13 mutanttan 13'ü öldü (biri kod sadeleştirilerek) · yerel göç, tarayıcı denemesi ve gerçek yarış testi koşmadı (§18.3) |
| 18.4 | `SEC-02` | Yayınlanmış hakkın iadesi | `fix/2026-11-17-sec-02-refund-after-publish` | K164–K169 | kod ✅ · `composer check` ✅ 1159 test · frontend `npm run check` ✅ · 21 mutanttan 21'i öldü · yerel göç, tarayıcı denemesi ve HTTP düzeyinde gerçek yarış testi koşmadı (§18.4) |
| 18.5 | `SEC-03` | Shopier `callback` kipi üretimde reddedilir | `fix/2026-11-20-sec-03-shopier-callback-mode` | K170–K172 | kod ✅ · `composer check` ✅ 1166 test · 6 mutanttan 6'sı öldü · frontend değişmedi · sunucudaki `SHOPIER_CONFIRMATION` değeri ve tarayıcı denemesi koşmadı (§18.5) |
| 18.6 | `SEC-04` | Frontend güvenlik başlıkları + Faz 18 kapanışı | `fix/2026-11-20-sec-04-security-headers` | K173–K178 | kod ✅ · `composer check` ✅ 1166 test · frontend `npm run check` ✅ (frontend değişmedi) · yerel Nginx + tarayıcı denemesi ✅ · 9 mutanttan 9'u öldü · sunucuda kurulum ve duman testi koşmadı (§18.6) |

### Faz 18 kapanış özeti (20 Kasım 2026)

| Bulgu | Durum | K | Kalan elle iş (İsmail) |
|---|---|---|---|
| `OPS-01` | kod ✅ · git'te `faz-18-audit-fixes`'e birleşmiş | K146–K150 | Sunucuda etiket ve sözleşme denetimi ilk kez `v1.1.0` yayınında (docs `YAYIN-SIRASI.md` §4) |
| `OPS-02` | kod ✅ · birleşmiş | K151–K157 | Yedek bucket'ı ve yalnızca yazabilen kimlik (AWS), sunucuya kurulum, ilk üretim provası ve süresi (`YEDEK-VE-GERI-YUKLEME.md`) |
| `SEC-01` | kod ✅ · birleşmiş | K158–K163 | Yerelde `php artisan migrate` (kısmi UNIQUE index), tarayıcıda ödeme sayfası |
| `SEC-02` | kod ✅ · birleşmiş | K164–K169 | Yerelde `php artisan migrate` (`first_published_at` + geriye dönük doldurma), `refunds:list` |
| `SEC-03` | kod ✅ · birleşmiş | K170–K172 | Sunucudaki `SHOPIER_CONFIRMATION` değerini gözle doğrulamak (`osb` olmalı) |
| `SEC-04` | kod ✅ · commit bekliyor | K173–K178 | `sudo bash deploy/nginx/install.sh` + duman testi; bir haftalık CSP gözlemi ve zorlama kararı |

"Birleşmiş": 20 Kasım'da `git log faz-18-audit-fixes`'te `Merge … fix into faz-18-audit-fixes`
commit'leri görüldü (backend `96664dd`, frontend `fefa647`). Docs takip tablosundaki `birleşti`
işaretini İsmail koyar.

**Faz kapısı (son ağaç, 20 Kasım):** backend `composer check` ✅ (1166 test) · frontend `npm ci` +
`npm run check` ✅ · backend `composer audit` ❌ (`SEC-07`: 6 pakette 9 açık; denetim günü 8). Bu yüzden
**`v1.1.0` etiketi atılmadı ve etiket komutları verilmedi** (prompt 18.6 §11.5). `main`'e birleştirme
komutları verildi (docs `phases/faz-18/FAZ-18-SEC-04-COMMIT-KOMUTLARI.md`); `SEC-07` kapanana kadar
sunucuda `deploy.sh` çalıştırılmaz, çünkü sunucu `main`'i çeker (Y8.2a).

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

---

## 18.3 — `SEC-01`: "ilk yayın" indirimi aynı anda açılan her siparişe uygulanıyordu

### Sorun

"İlk yayın" indirimi (%60) bir kullanıcıya bir kez verilmek üzere tasarlandı, ama kural yalnızca
**ödenmiş** siparişe bakıyordu ("`paid_at` damgalı sipariş yoksa ilk yayındır", K131) ve bekleyen
siparişi saymıyordu. Yeni bir kullanıcı ödeme sayfasını iki sekmede açınca iki `pending` sipariş
de indirimli oluyordu (249,00 → 99,60 TL); ödeme bildirimi tutarı siparişteki donmuş tutarla
karşılaştırıyor, indirimin hâlâ geçerli olup olmadığına bakmıyordu. Sınır yoktu: on sekme, on
indirimli hak (denetim sondası P13). Kök neden bir **TOCTOU** hatası (kontrol anı ≠ kullanım anı):
"indirim boşta mı?" gözlemi bir kayda bağlanmadan ikinci istek aynı soruyu sorup aynı cevabı
alıyordu. Hediye kodunda aynı sorun doğru çözülmüştü (K111: kod satırı kilitlenir, kullanım aynı
transaction'da yazılır); ilk yayın indiriminde o desen yoktu. Zemini de `ARC-09` hazırlıyordu:
her checkout çağrısı, öncekilerden habersiz yeni bir satır açıyordu.

### Kararlar

| K | Karar (tek cümle) |
|---|---|
| **K158** | Bir kullanıcının aynı anda en fazla bir `pending` + `first_publish` siparişi olur; üç katman: kullanıcı satırı kilidi, kısmi UNIQUE index, ödeme anında son kapı (`Log::critical`; geçiş reddedilmez). |
| **K159** | Uygunluk tek yerde (`FirstPublishEligibility`): `paid_at` damgalı sipariş yok **ve** başka bir hedefin ödeme bekleyen indirimli siparişi yok; önizleme ve checkout aynı cevabı kullanır (K131'i daraltır). |
| **K160** | Aynı niyet (hedef, plan, otomatik fiyat tutarı, hediye kodu, sağlayıcı) için ödeme bekleyen sipariş varsa checkout yeni satır açmaz, onu olduğu gibi döndürür. |
| **K161** | Aynı hedefte farklı niyet eski siparişi `expired` kapatır ve indirim yenisine geçer; penceresi dolmuş `pending` satırlar checkout başında kapatılır (geç ödeme K89 ile yine kabul edilir). |
| **K162** | Index'in göçü veri değiştirmez: çakışan satır varsa anlaşılır mesajla durur, çözüm sorgusu kılavuzda. |
| **K163** | Fiyat uçlarının yanıt biçimi değişmez; istek isteğe bağlı hedef alır (`GET /pricing?invitation=`, önizlemede `invitationId`), yoksa davetiyesiz paket. |

### Değişen dosyalar

**Backend** (`fix/2026-11-16-sec-01-first-publish-once`)

| Adım | Dosya | Ne | Kılavuz |
|---|---|---|---|
| 18.3.1 | `database/migrations/2026_11_16_100000_add_one_pending_first_publish_index_to_orders.php` | Kısmi UNIQUE index; çakışmada durur (yeni) | [göç kılavuzu](../database/migrations/2026_11_16_100000_add_one_pending_first_publish_index_to_orders.md) |
| 18.3.1 | `database/factories/OrderFactory.php` | `firstPublish()` durumu | [`OrderFactory.md`](../database/factories/OrderFactory.md) §Faz 18.3 |
| 18.3.1 | `tests/Feature/OrderFirstPublishIndexTest.php` | 10 test (yeni) | göç kılavuzu §7 |
| 18.3.2 | `app/Services/Pricing/FirstPublishEligibility.php` | Uygunluk kuralı, tek sorgu (yeni) | [`FirstPublishEligibility.md`](../app/Services/Pricing/FirstPublishEligibility.md) |
| 18.3.2 | `app/Models/Order.php` | `scopeAwaitingPayment()` | [`Order.md`](../app/Models/Order.md) §Faz 18.3 |
| 18.3.2 | `app/Actions/Pricing/QuotePriceAction.php` | Hedef parametresi; kural komşu sınıfa | [`QuotePriceAction.md`](../app/Actions/Pricing/QuotePriceAction.md) §Faz 18.3 |
| 18.3.2 | `app/Actions/Pricing/ShowPricingAction.php` · `app/Http/Controllers/Api/V1/PricingController.php` · `app/Http/Requests/Pricing/ShowPricingRequest.php` (yeni) | `GET /pricing?invitation=` | [`ShowPricingRequest.md`](../app/Http/Requests/Pricing/ShowPricingRequest.md) + iki kısa ek |
| 18.3.2 | `app/Actions/GiftCode/PreviewGiftCodeAction.php` · `app/Http/Controllers/Api/V1/GiftCodeController.php` · `app/Http/Requests/GiftCode/PreviewGiftCodeRequest.php` | Önizlemede `invitationId` | üçünün kılavuzunda §Faz 18.3 |
| 18.3.2 | `tests/Feature/FirstPublishEligibilityTest.php` (yeni, 12) · `QuotePriceActionTest.php` (+2) · `PricingTest.php` (+3) · `GiftCodePreviewTest.php` (+2) | 19 test | `PricingTest.md`, `GiftCodePreviewTest.md` |
| 18.3.3 | `app/Actions/Payment/StartCheckoutAction.php` | Kilit, yeniden kullanma, eskiyi kapatma | [`StartCheckoutAction.md`](../app/Actions/Payment/StartCheckoutAction.md) §Faz 18.3 |
| 18.3.3 | `tests/Feature/FirstPublishCheckoutTest.php` (+14, 1 değişti) · `GiftCodeCheckoutTest.php` (1 değişti) | | [`FirstPublishCheckoutTest.md`](../tests/Feature/FirstPublishCheckoutTest.md) · `GiftCodeCheckoutTest.md` |
| 18.3.4 | `app/Actions/Payment/HandlePaymentCallbackAction.php` | Son kapı (`DB::afterCommit`) | [`HandlePaymentCallbackAction.md`](../app/Actions/Payment/HandlePaymentCallbackAction.md) §Faz 18.3 |
| 18.3.4 | `tests/Feature/FirstPublishFinalGateTest.php` | 9 test (yeni) | aynı |
| 18.3.5 | `docs/rehber/fazlar/FAZ-18.md` · `README.md` · `FAZ-13.md` | Bu kayıt; K131'e "daraltıldı → K159" notu | — |

Yeni hata kodu, yeni bağımlılık ve config anahtarı **yok**; `contracts/` değişmedi. Göç bir index
ekler, kolon eklemez.

**Frontend** (`fix/2026-11-16-sec-01-first-publish-once`), adım 18.3.6

| Dosya | Ne |
|---|---|
| `src/services/pricing.ts` | `myCatalog(invitationId?)` → `GET /pricing?invitation=` |
| `src/stores/usePricingStore.ts` · `src/hooks/usePricing.ts` | Hedefli katalog ayrı yuvada (`targeted`, `loadFor`, `refresh`); ortak katalog değişmedi |
| `src/services/payments.ts` · `src/stores/useCheckoutStore.ts` | Kod önizlemesi hedefi taşır; kapanan yöntemde iki katalog da tazelenir |
| `src/pages/CheckoutPage.tsx` · `src/components/payment/checkout/GiftCodeField.tsx` · `FreeActivationPanel.tsx` | Sayfa kendi davetiyesinin fiyatını sorar |
| `scripts/verify-pricing.ts` (+11 denetim) · `scripts/verify-endpoints.ts` (+2) | |
| `docs/rehber/…` | Yedi kılavuzda §Faz 18.3 |

**Docs** (`docs/2026-11-16-faz-18-audit-fixes`), adım 18.3.7: `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-18.md`
§18.3, `decisions/DECISION-INDEX.md` (K158–K163, sıradaki K164), `phases/faz-18/README.md`,
`phases/faz-18/FAZ-18-SEC-01-COMMIT-KOMUTLARI.md`, `CHANGELOG.md`.

### Eklenen testler ve öldürdükleri mutantlar

Her mutant elle uygulandı, hedefli testler koştu (`FirstPublish|QuotePriceActionTest|PricingTest|GiftCodePreviewTest|OrderFirstPublishIndexTest`),
dosya geri alındı. Geri alma, dosyanın SHA-256'sı önce ve sonra karşılaştırılarak doğrulandı
(13/13 aynı); `git diff --stat` mutantlardan önce ve sonra aynı çıktıyı verdi.

| # | Mutant | Sonuç | Kırmızıya dönen (ilki §6'daki hedef test) |
|---|---|---|---|
| M1 | "Açık indirimli sipariş var mı" sorgusu kaldırıldı (`FirstPublishEligibility`) | ✅ öldü (98 testten 10'u) | `FirstPublishCheckoutTest::the_first_publish_discount_is_granted_to_one_order_only` · `…the_quote_and_the_checkout_agree…` · `FirstPublishEligibilityTest` ×3 · `PricingTest` ×2 · `QuotePriceActionTest` ×2 · `GiftCodePreviewTest` ×1 |
| M2 | Yeniden kullanma kaldırıldı (`$existing = null`) | ✅ öldü (4) | `…a_second_checkout_for_the_same_target_reuses_the_pending_order` · `…same_package…` · `…the_same_gift_code…` · `…a_provider_failure_on_a_reused_order…` |
| M3 | `->lockForUpdate()` kaldırıldı | ✅ öldü (1) | `…the_user_row_is_locked_before_the_checkout_looks_at_orders` |
| M4 | Göçte `CREATE UNIQUE INDEX` → `CREATE INDEX` | ✅ öldü (1) | `OrderFirstPublishIndexTest::a_second_pending_first_publish_order_…is_rejected_by_the_database` |
| M5 | Son kapı kaldırıldı (`DB::afterCommit` satırı) | ✅ öldü (2 test, 3 veri kümesi) | `FirstPublishFinalGateTest::a_second_paid_first_publish_order_is_logged_as_critical` · `…a_late_payment_for_a_closed…` |
| M6 | Eski siparişi kapatma kaldırıldı (`supersede`) | ✅ öldü (3) | `…a_different_plan_for_the_same_target_closes_the_old_order…` · `…a_price_that_changed…` · `…dropping_the_gift_code…` |
| M7 | Penceresi dolmuş satırı kapatma kaldırıldı | ✅ öldü (1) | `…an_abandoned_checkout_keeps_the_discount_for_the_next_try` |
| M8 | Yeniden kullanmada `promotion` **türü** karşılaştırması kaldırıldı | ⚠️ **hayatta** → koşul gereksizdi | Tür değişince tutar ya da kampanya kimliği de değişiyor. Karşılaştırma tutara indirildi (liste + otomatik indirim); iki satır silindi |
| M8a | Otomatik indirim tutarı karşılaştırması kaldırıldı | ✅ öldü (1) | `…a_price_that_changed_between_two_tries_opens_a_new_order` |
| M8b | Liste fiyatı karşılaştırması kaldırıldı | ✅ öldü (1) | `…a_list_price_that_changed_between_two_tries_opens_a_new_order` (bu mutant için eklendi) |
| M8c | Plan karşılaştırması kaldırıldı | ilk koşuda hayatta → test eklendi → ✅ öldü | `…another_plan_at_the_same_price_is_not_the_same_intent` |
| M8d | Hediye kodu karşılaştırması kaldırıldı | ✅ öldü (1) | `…dropping_the_gift_code_closes_the_old_order_and_frees_the_code` |
| M9 | `if (! $reused)` → `if (true)` | ✅ öldü (1) | `…a_provider_failure_on_a_reused_order_leaves_it_pending` |
| M10 | Yeniden kullanılan siparişin `provider_ref`'i eziliyor | ✅ öldü (1) | `…a_second_checkout_for_the_same_target_reuses_the_pending_order` |
| M11 | Uygunlukta pencere koşulu kaldırıldı (`awaitingPayment` → yalnızca `pending`) | ✅ öldü (1) | `FirstPublishEligibilityTest::only_an_open_first_publish_order_of_the_same_user_holds_the_discount` ("past its window") |
| F1 | Frontend: `loadFor` başka davetiyenin kataloğunu ekranda tutuyor | ✅ öldü | `npm run verify:pricing` çıkış 1: "başka davetiyenin fiyatı ekranda kalmaz" |

M8'in dersi: hayatta kalan mutant her zaman eksik test demek değildir; bazen **fazla kod** demektir.

### Doğrulama durumu (B7)

Makine: Windows 11, PHP 8.5.12, PostgreSQL 18.4 (`davetkart_test`), Node 24, Windows PowerShell 5.1. Tarih: 16 Kasım 2026.

| Komut | Sonuç | Çıktıdan |
|---|---|---|
| §2 önkoşulları (üç depo) | ✅ | çalışma ağaçları boş · backend `1c75661 Merge OPS-02 fix into faz-18-audit-fixes` · `fix/2026-11-16-ops-02-db-backup` birleşmiş listede |
| 18.3.1 RED: `--filter=OrderFirstPublishIndexTest` (göç yokken) | ❌ beklenen | `tests=10 passed=7 failed=1 errors=2`: ikinci satır kabul edildi; göç dosyası yok |
| 18.3.1 GREEN | ✅ | `tests=10 passed=10 assertions=14` · pint `passed` · phpstan `errors: 0` |
| 18.3.2 RED: dört test dosyası | ❌ beklenen | `tests=60 passed=41 failed=7 errors=12`: `Target class [...FirstPublishEligibility] does not exist`, hedef dikkate alınmıyor, 422 yerine 200 |
| 18.3.2 GREEN | ✅ | `tests=60 passed=60 assertions=179` · pint `passed` · phpstan `errors: 0` |
| 18.3.2 sonrası ilgili testler (387) | ❌ beklenen, 1 kırmızı | `FirstPublishCheckoutTest::an_abandoned_checkout_keeps_the_discount_for_the_next_try`: `SQLSTATE[23505] Unique violation`. Index var, onu gözeten checkout 18.3.3'te. **18.3.1 ve 18.3.2 commit'leri tek başına yeşil değildir** (18.3.1 için ayrı koşu yapılmadı; aynı testin kırılması beklenir) |
| 18.3.3 RED: `--filter=FirstPublishCheckoutTest` | ❌ beklenen | `tests=19 passed=9 failed=7 errors=3` |
| 18.3.3 GREEN | ✅ | `tests=19 passed=19 assertions=103` |
| 18.3.3 sonrası tam paket | ❌ 1 kırmızı → test güncellendi | `tests=1115 passed=1114`: `GiftCodeCheckoutTest::the_same_user_cannot_use_a_code_twice` (aynı hedefte plan değiştiriyordu; K161 ile artık meşru). Test başka hedefe çevrildi; sonra iki dosya `tests=42 passed=42` |
| 18.3.3 phpstan | ❌ → ✅ | Testte `equalTo()`'ya null olabilir tarih; karşılaştırma ISO metne çevrildi → `errors: 0` |
| 18.3.4 RED: `--filter=FirstPublishFinalGateTest` | ❌ beklenen | `tests=9 passed=6 errors=3`: `critical(...) should be called at least 1 times but called 0 times`. Yeşil olan 6'sı "haber verme" (yokluk) testleri |
| 18.3.4 GREEN | ✅ | `tests=9 passed=9 assertions=31` |
| 18.3.4 phpstan | ❌ → ✅ | Testte `shouldHaveReceived()->once()` tipi bulunamadı; depodaki biçime (`shouldHaveReceived('critical', [..., Mockery::on(...)])`) çevrildi → `errors: 0` |
| Mutantlar (M1–M11, F1) | ✅ | yukarıdaki tablo; mutantsız aynı süzgeç `tests=100 passed=100 assertions=337` |
| **`composer check` (son)** | ✅ | pint `passed` · phpstan `errors: 0` · `Katalog guncel.` · `Plan kurallari sozlesmesi guncel.` · phpunit `tests: 1126, passed: 1126, assertions: 4161` · çıkış 0 |
| Frontend `npm run lint` (`tsc --noEmit`) | ✅ | çıkış 0 |
| Frontend `npm run verify:pricing` | ✅ | "Hedefli katalog (ödeme sayfası)" altında 11 ✓ · çıkış 0 |
| **Frontend `npm run check`** (lint → build → verify) | ✅ | çıkış 0 · `✓ built in 25.64s` · 12 doğrulama betiği koştu |
| Frontend satır sonları | ✅ | `git ls-files --eol`: 17 dosya `i/lf w/crlf` (depodaki diğer dosyalarla aynı); `git diff --stat` 17 dosya, tam dosya değişikliği yok |
| Yerel `davetkart` veritabanı, salt okunur | ✅ | bekleyen ilk yayın indirimli sipariş: 0 satır · çakışan kullanıcı: 0 (göç orada durmayacak) |
| Göç kılavuzu §4 sorguları | kısmen | "önce bak" `SELECT`'i koştu (0 satır). "Sonra kapat" `UPDATE`'i **çalıştırılmadı**: yalnızca `EXPLAIN` ile planlandı (sözdizimi geçerli) |
| `php artisan migrate` (yerel `davetkart`) | koşmadı | geliştirme veritabanını değiştirir; İsmail'in adımı. Göç test veritabanında her koşuda `migrate:fresh` ile uygulandı |
| İki bağlantılı gerçek yarış testi | koşmadı | kapsam dışı (`TST-02`). Kilit yalnızca SQL düzeyinde sınandı (`DB::listen`) |
| `DB::afterCommit`, test dışı gerçek transaction'da | koşmadı | yalnızca `RefreshDatabase` altında koştu |
| Shopier sürücüsüyle yeniden kullanma | koşmadı | sır yok, canlı istek atılmadı; `ShopierGateway::startCheckout()` koddan okundu (referans = sipariş kimliği, ikinci çağrı aynı referansı verir). Testler `fake` sürücüyle |
| Tarayıcıda elle deneme | koşmadı | sunucular çalıştırılmadı; adımlar aşağıda |
| Sunucu, Shopier, AWS, Sentry | koşmadı | canlı sisteme istek atılmadı |

### Elle doğrulama (İsmail)

1. Backend'de `composer check`: son satırda `"tests":1126,"passed":1126`.
2. Birleştirmeden sonra `php artisan migrate`. Durursa: göç kılavuzu §4.
3. Tarayıcı (`PAYMENT_DRIVER=fake`, yeni kullanıcı, iki davetiye): frontend
   `docs/rehber/src/pages/CheckoutPage.md` §Faz 18.3 "Elle deneme".
4. SEC-01'in kendisi, API'den: A davetiyesi için `POST /api/invitations/{A}/checkout` (`first_publish`),
   ardından `POST /api/payments/checkout` → ikinci yanıtta `promotion: null`, tutar liste fiyatı.
   Aynı isteği yinele → aynı `orderId`.
5. Mutantı kendin dene: [`StartCheckoutAction.md`](../app/Actions/Payment/StartCheckoutAction.md)
   §Faz 18.3 "Mutantlar", [`FirstPublishEligibility.md`](../app/Services/Pricing/FirstPublishEligibility.md) §7.
6. Son kapıyı gör: `php artisan test --filter=FirstPublishFinalGateTest`; log'da aranacak metin
   `First publish discount paid on more than one order`.

### Plandan sapmalar

- **Fiyat uçları isteğe bağlı hedef alıyor ve frontend değişti (K163).** Plan "yanıt biçimi
  değişmediyse frontend'e dokunma" diyordu; yanıt değişmedi ama **istek** değişti. `GET /pricing` ve
  `POST /gift-codes/preview` hedefi bilmiyordu. D2'nin kuralı ("başka bir hedef için açık indirimli
  sipariş yok") önizlemede hedef olmadan söylenemez: hedefsiz katı cevap, ödeme sayfasına geri dönen
  kullanıcıya kendi indirimini kaybolmuş gösterir (K131 ve D3'ün korumak istediği kişi); hedefsiz
  gevşek cevap, başka davetiyenin sayfasında 99,60 gösterip 249,00 keser. İkisi de "gösterilen =
  tahsil edilen"i bozar. Parametre isteğe bağlı ve geriye uyumlu; yalnızca biçimi doğrulanır.
- **Frontend'de ortak katalog hedefe göre anahtarlanmadı.** İlk taslak öyleydi; ortak kataloğu aynı
  anda okuyan bileşenler (plan duvarı, pazarlama metinleri) "yükleniyor"da kalabilirdi. Hedefli
  katalog ayrı yuvaya alındı (`targeted`).
- **D2'deki "`paid` `first_publish` yok" ifadesi K131'in mevcut hâliyle uygulandı:** "`paid_at`
  damgalı **hiçbir** sipariş yok". Daha sıkı olan bu; gevşetmek kampanyayla alınmış bir ilk
  siparişten sonra ikinci siparişe ilk yayın indirimi verirdi ve dört mevcut test (M36'yı öldürenler)
  buna dayanıyor.
- **D3'ün "aynı niyet" tanımına tutar ve sağlayıcı eklendi.** Plan hedef + plan + hediye kodu
  diyordu. Arada kampanya açılır ya da fiyat değişirse eski siparişi geri vermek gösterilenden farklı
  bir tahsilat olurdu; sürücü değiştiyse eski oturum yeniden açılamaz.
- **Penceresi dolmuş satırlar checkout başında kapatılıyor (planda yoktu).** Index zamanı bilemez;
  `orders:expire` uğrayana kadar o satır yeni indirimli siparişi bloklardı.
- **Son kapı `paid_at`'e bakar ve commit'ten sonra sorar.** Plan "başka `paid` sipariş" diyordu;
  iade edilmiş sipariş de sayılır (K131 ile aynı ölçü). `DB::afterCommit`, eşzamanlı iki bildirimde
  en az bir kayıt düşmesini kilitsiz sağlar.
- **Yönetici bildirim mekanizması yok;** kanal yalnızca `Log::critical` (plan bunu öngörmüştü).
- **İki mevcut test değişti:** `an_abandoned_checkout_keeps_the_discount_for_the_next_try` (iki
  indirimli siparişi doğruluyordu: `SEC-01`'in kendisi) ve `the_same_user_cannot_use_a_code_twice`
  (aynı hedefte plan değiştiriyordu; K161 ile meşru). İkisinin de sözü korunuyor.
- **18.3.1 ve 18.3.2 commit'leri bir testi kırmızı bırakır** (yukarıdaki tablo). Adım sırası
  plandaki gibi tutuldu (index önce); dal 18.3.3'ten itibaren yeşil.
- **`FirstPublishEligibility` `app/Services/Pricing/` altında** (`TierResolver` ile aynı aile:
  kendi veritabanımıza bakan sunucu cevabı). Bir Action değil; yan etkisi ve `handle()`'ı yok.

### Gördüm, dokunmadım

| Bulgu | Ne |
|---|---|
| — (yeni) | **Kapatılmış siparişe geç ödeme.** Bir siparişin sağlayıcıdaki ödeme sayfası, sipariş `expired` olduktan sonra da açık kalabilir; ödeme gelirse K89 kabul eder. Kararlı bir kullanıcı hâlâ birden çok indirimli siparişi ödeyebilir (K161 kapatmayı anında yaptığı için 30 dakika beklemesi de gerekmez). Engellenmiyor, **yakalanıyor**: ikinci ödemeden itibaren `critical`. Ne yapılacağı (fark, iade, K89'u kapatılan siparişler için daraltmak) karar bekliyor |
| — (yeni) | **Geç ödenen siparişin hediye kodu kullanımı yeniden aktif olur.** Kullanımın durumu siparişten türetilir (Faz 11, D4): `expired` iade eder, `expired → paid` geri alır. O arada kod başka bir siparişte kullanıldıysa tek kullanımlık kod iki siparişte ödenmiş olur. Faz 11'den beri var; son kapı yalnızca ilk yayın indirimine bakar |
| `TST-02` | Kalan 16 `lockForUpdate()` hâlâ testsiz; iki bağlantılı gerçek yarış testi yok. Bu fazda yalnızca yeni kilit SQL düzeyinde sınandı |
| `ARC-09` | `Idempotency-Key` başlığı yok; kodsuz checkout hâlâ yalnızca genel 60/dk tavanına tabi |
| `SEC-09` · `SEC-15` | Değişmez **kullanıcı başına**. E-posta doğrulaması olmadan yeni hesap açan biri her hesapta bir ilk yayın indirimi alır (denetim `REPORT.md` "E-posta sahipliği" satırı) |
| — | `ExpireStaleOrders` ile `StartCheckoutAction::expireStaleOrders()` aynı koşulu (bekliyor + penceresi dolmuş) iki yerde yazıyor. Komuta dokunulmadı; ortak bir `Order` kapsamına alınabilir |
| — | Ödeme bildirimi kullanıcı satırını kilitlemiyor: checkout'un "hiç ödememiş" gözlemi, aynı anda işlenen bir bildirim karşısında milisaniyeler için bayat kalabilir |
| — | Yeniden kullanılan sipariş de 201 döner (katı REST'te 200); yanıt biçimi kararı gereği (K163) dokunulmadı |
| — | Frontend plan duvarı (`PaywallModal`) ortak kataloğu okur: kullanıcının o davetiye için bekleyen indirimli siparişi varken duvar liste fiyatını, ödeme sayfası indirimli fiyatı gösterir |
| — | Başka hedefin sayfasında indirimin neden görünmediğini söyleyen bir metin yok (D6: UX sorusu) |
| — | Frontend deposunda 4 Kasım'dan kalma `.git/packed-refs.lock` (0 bayt) dal açarken uyarı verdi; silinmedi |
| `ARC-01` | `DECISION-INDEX.md` K128–K145'i hâlâ içermiyor; K131'in "daraltıldı" notu bu yüzden `FAZ-13.md`'deki satırına işlendi |

---

## 18.4 — `SEC-02`: kullanılıp silinen davetiyenin hakkı iade edilebiliyordu; yönetici göremiyordu

### Sorun

Kullanıcı Standart alır, davetiyeyi yayınlar, bağlantıyı dağıtır, misafirler LCV bırakır; üç günlük
pencere (K82) kapanmadan davetiyeyi siler. Sipariş serbest kalır (`invitation_id = NULL`,
`consumed_at = NULL`). İade uygunluğu (K139) yalnızca "hak bir davetiyeye bağlı mı, yanmış mı" diye
bakıyordu; ikisi de değil, talep 201 ile açılıyordu (denetim sondası P47). Yönetici `refunds:list`'te
plan, tutar ve e-postayı görüyordu ama hakkın bir davetiyeyi yayınladığını gösteren hiçbir şey yoktu
(P49) ve onaylıyordu. İki kural ayrı ayrı doğruydu; hata **birleşim yerindeydi**: K82 "kullanıldı"
bilgisini siliyor, K139 o bilgiye güveniyordu. Kök neden, bir durumu (kullanıldı) bir **bağın
yokluğundan** türetmek. Faz 14 aynı dersi `consumed_at` için almıştı (K137) ama o damga yalnızca
pencere kapandıktan sonra basılıyordu. Ayrıca onay, talep anındaki cevabı yeniden sormuyordu
(TOCTOU) ve talep açıkken eşzamanlı bir yayın hakkı bağlayabiliyordu (§"Doğrulama", iki bağlantılı
deneme).

### Kararlar

| K | Karar (tek cümle) |
|---|---|
| **K164** | `orders.first_published_at` (tz'siz `timestamp`, nullable, `CHECK (… OR paid_at IS NOT NULL)`): bir hak yayındaki bir davetiyeye ilk kez hizmet ettiğinde yalnızca boşsa basılır; serbest bırakma ve başka hiçbir yol onu silmez; toplu atamaya kapalı, tek yazar `StampFirstPublishAction`. |
| **K165** | Damga iki yerde basılır: `PublishInvitationAction` (yayın anı, aynı transaction) ve `DeleteInvitationAction` (hak davetiyeden ayrılmadan önce); ikincisi, yayından sonra ödenen yükseltme siparişinin yan kapısını kapatır. Damga zamanı yayın anı ile ödeme anının sonrakidir. |
| **K166** | Yeni iade engeli `used` (`RefundBlocker::Used`): hak serbest ama bir davetiyeyi yayınlamış; `in_use` her zaman önce sorulur. Hata kodu (`REFUND_NOT_ALLOWED`, 409) ve `contracts/error-codes.json` değişmez; frontend tipi ve çevirisi eklenir. K139'u daraltır. |
| **K167** | Onay anında yeniden sınama: `ResolveRefundRequestAction`, sipariş satırı kilitliyken `RefundEligibility::usageBlockerFor()`'u sorar; hak kullanılmışsa onaylamaz (talep açık kalır, ret ile kapanır). `refunds:approve` aynı soruyu "parayı gönderdiniz mi?" sorusundan **önce** sorar. Zaten `refunded` sipariş istisna. Durum makinesi değişmez. |
| **K168** | `refunds:list` "Yayın" sütunu: damga tarihi (`davetkart.default_timezone`) · `davetiyeye bağlı` · `hiç yayınlanmadı`. |
| **K169** | Geriye dönük doldurma (göç): (1) yayınlanmış davetiyeye bağlı ödenmiş sipariş → `GREATEST(published_at, paid_at)`; (2) yanmış hak → `consumed_at`; (3) serbest kalmış hak → aynı kullanıcının çöpteki yayınlanmış davetiyesi + sipariş `updated_at` ≈ davetiye `deleted_at` (±2 sn). İz yoksa damga yok; sınırlar kılavuzda. |

**Daraltılan kararlar:** K139 → **K166** (iade edilebilir hak artık "bir davetiyeyi yayınlamamış" da
olmalı; satırı `FAZ-14.md` §4'te, durum notu oraya işlendi). K82 → **K164** (hak serbest kalır ve
yeniden **kullanılır**, iade edilmez; satırı docs deposu `DECISION-INDEX.md`'de). Geçersiz kılınan
karar yok. K137 ve K141 değişmedi; K167 K141'in sorusundan önce bir denetim ekler.

### Değişen dosyalar

**Backend** (`fix/2026-11-17-sec-02-refund-after-publish`)

| Adım | Dosya | Ne | Kılavuz |
|---|---|---|---|
| 18.4.1 | `database/migrations/2026_11_17_100000_add_first_published_at_to_orders_table.php` | Kolon + kısıt + üç doldurma kuralı (yeni) | [göç kılavuzu](../database/migrations/2026_11_17_100000_add_first_published_at_to_orders_table.md) |
| 18.4.1 | `app/Models/Order.php` | Cast, `hasPublished()`, `scopeNeverPublished()`; `#[Fillable]` boş kaldı | [`Order.md`](../app/Models/Order.md) §Faz 18.4 |
| 18.4.1 | `database/factories/OrderFactory.php` | `publishedOnce(?$at)` | [`OrderFactory.md`](../database/factories/OrderFactory.md) §Faz 18.4 |
| 18.4.1 | `tests/Feature/OrderFirstPublishedAtTest.php` | 11 test (yeni) | göç kılavuzu §7 |
| 18.4.2 | `app/Actions/Payment/StampFirstPublishAction.php` | Damgayı basan tek yer (yeni) | [`StampFirstPublishAction.md`](../app/Actions/Payment/StampFirstPublishAction.md) |
| 18.4.2 | `app/Actions/Invitation/PublishInvitationAction.php` | 6. katman: damga | [`PublishInvitationAction.md`](../app/Actions/Invitation/PublishInvitationAction.md) §Faz 18.4 |
| 18.4.2 | `app/Actions/Invitation/DeleteInvitationAction.php` | Hak ayrılmadan önce damga (K165) | [`DeleteInvitationAction.md`](../app/Actions/Invitation/DeleteInvitationAction.md) §Faz 18.4 |
| 18.4.2 | `tests/Feature/OrderFirstPublishStampTest.php` | 10 test (yeni) | `StampFirstPublishAction.md` §9 |
| 18.4.3 | `app/Enums/RefundBlocker.php` | `Used = 'used'` | [`RefundEligibility.md`](../app/Services/Refund/RefundEligibility.md) §7 |
| 18.4.3 | `app/Services/Refund/RefundEligibility.php` | `usageBlockerFor()`; `blockerFor()` onu kullanır | aynı, §6–§9 |
| 18.4.3 | `tests/Feature/RefundRequestTest.php` | +4 test | [`RefundRequestTest.md`](../tests/Feature/RefundRequestTest.md) |
| 18.4.3 | `docs/rehber/app/Enums/ErrorCode.md` · `docs/rehber/app/Actions/Refund/RequestRefundAction.md` | `reason` değerlerine `used` | — |
| 18.4.4 | `app/Actions/Refund/ResolveRefundRequestAction.php` | Onayda yeniden sınama (K167) | [`ResolveRefundRequestAction.md`](../app/Actions/Refund/ResolveRefundRequestAction.md) §5–§8 |
| 18.4.4 | `app/Console/Commands/ApproveRefundRequest.php` | Para sorusundan önce ön denetim | `RefundCommands.md` §2.1 (18.4.5'in commit'inde, aşağıda) |
| 18.4.4 | `tests/Feature/RefundApprovalRecheckTest.php` | 6 test (yeni) | `ResolveRefundRequestAction.md` §8 |
| 18.4.5 | `app/Console/Commands/ListRefundRequests.php` | "Yayın" sütunu | [`RefundCommands.md`](../app/Console/Commands/RefundCommands.md) §4–§5 |
| 18.4.5 | `tests/Feature/RefundCommandsTest.php` | +2 test | [`RefundCommandsTest.md`](../tests/Feature/RefundCommandsTest.md) |
| 18.4.5 | `docs/rehber/PROJE-KOMUTLARI.md` | §3.3 ve kısa yol tablosu: önce "Yayın" sütununa bak | — |
| 18.4.6 | `docs/rehber/fazlar/FAZ-18.md` · `README.md` · `FAZ-14.md` | Bu kayıt; K139'a "daraltıldı → K166" notu | — |

Yeni hata kodu, yeni bağımlılık ve config anahtarı **yok**; `contracts/` değişmedi (`errors:export
--check` yeşil). Yeni Composer/npm paketi yok.

**Frontend** (`fix/2026-11-17-sec-02-refund-after-publish`), adım 18.4.7

| Dosya | Ne |
|---|---|
| `src/types.ts` | `RefundBlocker` birliğine `'used'` |
| `src/locales/tr/errors.json` · `src/locales/en/errors.json` | `refundReasons.used` (409 toast'ının devamı) |
| `src/components/account/RightRow.tsx` | `available` + `blocker === 'used'` satırına açıklama |
| `src/pages/PricingPage.tsx` | SSS "İade alabilir miyim?": yayın yapmış hak iade edilmez |
| `docs/rehber/src/pages/account/README.md` §7 · `docs/rehber/src/pages/PricingPage.md` | Kılavuz |

**Docs** (`docs/2026-11-16-faz-18-audit-fixes`), adım 18.4.8: `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-18.md`
§18.4, `decisions/DECISION-INDEX.md` (K164–K169, sıradaki K170, K82 notu), `phases/faz-18/README.md`,
`phases/faz-18/FAZ-18-SEC-02-COMMIT-KOMUTLARI.md`, `CHANGELOG.md`.

### Eklenen testler ve öldürdükleri mutantlar

Yeni test: 33 (`OrderFirstPublishedAtTest` 11, `OrderFirstPublishStampTest` 10, `RefundApprovalRecheckTest` 6,
`RefundRequestTest` +4, `RefundCommandsTest` +2). Mevcut hiçbir test değişmedi.

Mutantlar bir betikle uygulandı: dosya yedeklenir, tek bir metin değişikliği yapılır, ilgili test
süzgeci koşar, dosya yedekten geri konur ve SHA'sı önceki hâliyle karşılaştırılır. `git diff`'in
özeti mutantlardan önce ve sonra aynı (doğrulama tablosu).

| # | Mutant | Sonuç | Kırmızıya dönen |
|---|---|---|---|
| M1 | `RefundEligibility`'den `hasPublished() => Used` kolu silindi (§6'daki hedef mutant) | ✅ öldü | `RefundRequestTest::a_right_that_published_an_invitation_cannot_be_refunded_after_release` (409 yerine 201) |
| M2 | Serbest bırakma damgayı sıfırlıyor (§6'daki ikinci hedef) | ✅ öldü (4) | `releasing_a_right_keeps_its_first_published_at` · `a_second_publish_does_not_move_the_stamp` · `an_upgrade_paid_after_the_publish_…` · `a_pending_order_is_released_without_a_stamp` |
| M3 | Yayın damga basmıyor | ✅ öldü (5) | `the_first_publish_stamps_the_order` · `a_package_claimed_by_the_publish_is_stamped` · `releasing_…` · `a_second_publish_…` · `every_paid_order_…` |
| M4 | Silme damga basmıyor (yan kapı, K165) | ✅ öldü (2) | `an_upgrade_paid_after_the_publish_is_stamped_when_the_right_is_released` · `an_upgrade_is_stamped_when_the_right_burns` |
| M5 | `neverPublished()` süzgeci silindi (damga her yayında yeniden basılır) | ✅ öldü | `a_second_publish_does_not_move_the_stamp` |
| M6 | `grantingPublishRight()` süzgeci silindi (ödenmemiş sipariş de damgalanır) | ✅ öldü (2) | `every_paid_order_of_the_invitation_is_stamped_and_unpaid_ones_are_not` · `a_pending_order_is_released_without_a_stamp` |
| M7 | Yükseltmede ödeme anı yerine yayın anı | ✅ öldü | `an_upgrade_paid_after_the_publish_is_stamped_when_the_right_is_released` |
| M8 | Onayda yeniden sınama silindi (D3) | ✅ öldü (2) | `approval_is_refused_when_the_right_was_published_while_the_request_was_open` · `approval_is_refused_for_a_released_right_that_had_published` |
| M9 | Onay komutunun ön denetimi kapatıldı (`if (false)`) | ✅ öldü | `the_approve_command_stops_before_asking_about_the_money` (beklenmeyen soru) |
| M10 | Zaten `refunded` sipariş istisnası silindi | ✅ öldü | `an_already_refunded_order_is_closed_even_if_the_right_was_used` |
| M11 | Listede tarih kolu silindi | ✅ öldü | `the_list_shows_whether_the_right_has_published` |
| M12 | Listede "davetiyeye bağlı" kolu silindi | ✅ öldü | `the_list_marks_a_right_that_is_attached_to_an_invitation` |
| M13 | Göç: serbest kalmış hakkın izi doldurulmuyor | ✅ öldü | `the_backfill_stamps_a_released_order_traced_to_a_trashed_published_invitation` |
| M14 | Göç: izde zaman çakışması aranmıyor | ✅ öldü | `the_backfill_leaves_an_unused_order_of_the_same_user_alone` |
| M15 | Göç: `GREATEST` yerine yayın anı | ✅ öldü | `the_backfill_uses_the_payment_time_for_an_order_paid_after_the_publish` |
| M16 | Göç: `CHECK (true)` | ✅ öldü | `the_database_refuses_a_stamp_without_payment` |
| M17 | Göç: yanmış hak doldurulmuyor | ✅ öldü | `the_backfill_stamps_a_consumed_order_with_its_consumption_time` |
| M18 | `used` kolu `in_use`'tan önce | ✅ öldü | `a_right_in_a_published_invitation_reports_in_use_before_used` |
| M19 | Göç: silmeden sonra ödenen sipariş de izleniyor | ✅ öldü | `the_backfill_leaves_an_unused_order_of_the_same_user_alone` |
| M20 | Göç: hiç yayınlanmamış çöp davetiye de iz sayılıyor | ✅ öldü | aynı |
| M21 | `#[Fillable(['first_published_at'])]` | ✅ öldü | `the_stamp_cannot_be_mass_assigned` |

### Doğrulama durumu (B7)

Makine: Windows 11, PHP 8.5 (Herd), PostgreSQL 18.4 (`davetkart_test`), Node, Windows PowerShell 5.1.
Kod 17 Kasım, son koşular 19 Kasım 2026.

| Komut | Sonuç | Çıktıdan |
|---|---|---|
| §2 önkoşulları | ⚠️ kısmen | backend ve frontend ağaçları boş, `faz-18-audit-fixes`'te; `git branch --merged faz-18-audit-fixes` içinde `fix/2026-11-16-sec-01-first-publish-once` var. **Docs ağacı boş değildi:** ` M prompts/PROMPT-FAZ-18-3-SEC-01.md` (tek boş satır eklenmiş; benim değil). Dokunulmadı, commit komutlarına girmedi (§Plandan sapmalar) |
| Başlangıç: `--filter=RefundRequestTest` | ✅ | `tests=14 passed=14` |
| 18.4.1 RED: `--filter=OrderFirstPublishedAtTest` | ❌ beklenen | `tests=11 passed=1 failed=1 errors=9`: `Undefined column … first_published_at`, `Call to undefined method …Order::hasPublished()`, göç dosyası yok |
| 18.4.1 GREEN | ✅ | `tests=11 passed=11 assertions=21` |
| 18.4.2 RED: `--filter=OrderFirstPublishStampTest` | ❌ beklenen | ilk koşu `Fatal error: Access level to …::delete() must be public` (yardımcı metodun adı `TestCase::delete()` ile çakıştı → `publishAs`/`deleteAs`); sonra `tests=10 passed=2 failed=8`: `Failed asserting that null is not null` |
| 18.4.2 GREEN | ✅ | `tests=10 passed=10 assertions=50` |
| Pint + PHPStan (18.4.2 sonu) | ❌ → ✅ | Pint iki dosyada `global_namespace_import`/`ordered_imports` → düzeltildi; PHPStan 8 hata (testte `object::up()`, null olabilen `equalTo()`) → yardımcılar ayrıldı, `assertNotNull` eklendi → `pint passed` · `phpstan errors: 0` |
| Komşu testler | ✅ | `OrderFirstPublish` 31/31 · `OrderConsumptionTest` 5/5 · `PaywallTest` 84/84 |
| 18.4.3 RED: `--filter=RefundRequestTest` | ❌ beklenen | `tests=18 passed=17 failed=1`: `Expected response status code [409] but received 201` (`SEC-02`'nin kendisi) |
| 18.4.3 GREEN | ✅ | `tests=18 passed=18 assertions=67` · `--filter=Entitlements` 17/17 |
| 18.4.4 RED: `--filter=RefundApprovalRecheckTest` | ❌ beklenen | `tests=6 passed=3 failed=2 errors=1`: onay reddedilmedi; komut "Parayı … gönderdiniz mi?" sorusunu sordu (`askQuestion(), but no expectations`) |
| 18.4.4 GREEN | ✅ | `tests=6 passed=6 assertions=31` · `RefundCommandsTest` 10/10 |
| 18.4.5 RED: `--filter=RefundCommandsTest` | ❌ beklenen | `tests=12 passed=10 failed=2`: `Output does not contain "Yayın"`, `… "davetiyeye bağlı"` |
| 18.4.5 GREEN | ✅ | `tests=12 passed=12 assertions=63` · `pint passed` · `phpstan errors: 0` |
| Mutantlar M1–M21 | ✅ 21/21 öldü | yukarıdaki tablo. İki kez koştu: kod yazılırken (M1–M20, M21 ayrı) ve **son ağaçta** (yorum kısaltmalarından ve faz kaydından sonra, hepsi birlikte). Son koşuda her dosyanın SHA'sı geri konduktan sonra aynı; `git diff --stat` önce ve sonra `26 files changed, 919 insertions(+), 20 deletions(-)`; `git diff \| sha1sum` önce ve sonra `6e3ec021209d`, izlenmeyen dosyaların özeti `1dcb610eab27` |
| İki bağlantılı yarış denemesi (geçici şema `sec02_probe`, `davetkart_test`) | ✅ yarış **var** | A: sipariş satırını `FOR UPDATE` kilitler, talebi yazar, 3 sn bekler. B: `NOT EXISTS (açık talep)` süzgeçli `FOR UPDATE` ile bekler. Çıktı: `B: baglanacak siparis id=1` · `B commit aninda acik talep sayisi=1`. Kilit açılınca satır değişmediği için alt sorgu yeniden sınanmıyor. Şema silindi (`sema kaldi mi=0`) |
| Yerel `davetkart` veritabanı, salt okunur sayım | ✅ | 24 sipariş · kolon henüz yok · kural 1: 5 · kural 2: 0 · kural 3: 1 · açık iade talebi: 0 |
| **`composer check`** (son ağaç, 20 Kasım) | ✅ | `pint passed` · `phpstan errors: 0` · `Katalog guncel.` · `Plan kurallari sozlesmesi guncel.` · `tests: 1159, passed: 1159, assertions: 4298` · çıkış 0. İlk koşu (yorumlar kısaltılırken) aynı sayıları verdi |
| Frontend `npm run check` (lint → build → verify) | ✅ | `tsc --noEmit` hatasız · `✓ built in 12.38s` · 12 doğrulama betiği (`verify:errors`: `[tr] 28 kod denetleniyor…`) · çıkış 0 |
| Frontend satır sonları | ✅ | `git ls-files --eol`: 7 dosya `i/lf w/crlf`; `git diff --stat` yalnızca eklenen satırlar |
| `php artisan migrate` (yerel `davetkart`) | koşmadı | geliştirme veritabanını değiştirir; İsmail'in adımı. Göç test veritabanında her koşuda uygulandı |
| HTTP düzeyinde gerçek yarış (iki eşzamanlı istek) | koşmadı | yalnızca SQL düzeyinde denendi (yukarıda); K167'nin testi yarışın **sonucunu** satıra yazarak kurar (`TST-02`) |
| Tarayıcıda elle deneme | koşmadı | sunucular çalıştırılmadı; adımlar aşağıda |
| Sunucu, Shopier, AWS, Sentry | koşmadı | canlı sisteme istek atılmadı |

### Elle doğrulama (İsmail)

1. Backend'de `composer check`: son satırda `"tests":1159,"passed":1159`.
2. Birleştirmeden sonra `php artisan migrate`. Ardından damgalananlara bak (yerelde 6 satır beklenir):
   `php artisan tinker` → `App\Models\Order::query()->whereNotNull('first_published_at')->get(['id','tier','paid_at','first_published_at','invitation_id']);`
   Yanlış damga varsa: [göç kılavuzu](../database/migrations/2026_11_17_100000_add_first_published_at_to_orders_table.md) §4.
3. `SEC-02`'nin kendisi (backend `PAYMENT_DRIVER=fake`, yeni kullanıcı): Standart al → davetiyeyi yayınla →
   aynı gün sil → Hesap Ayarları → Planlarım. Hak "Kullanılabilir"; "İptal Et" yok; altında "Bu hakla daha
   önce bir davetiye yayınlandığı için iptal edilemez…" yazar. API'den `POST /api/orders/{id}/refund-request`
   → 409 `REFUND_NOT_ALLOWED`, `params.reason = "used"`. Yeni bir davetiye yayınla: hak ona bağlanır (K82).
4. Yönetici tarafı: kullanılmamış bir hakla talep aç → `php artisan refunds:list` → "Yayın" sütunu
   `hiç yayınlanmadı`. Kullanılmış bir hakkın açık talebi için `refunds:approve <kimlik>`: soru sorulmadan
   "Bu hak kullanılmış (used) … Parayı GÖNDERMEYİN" ve çıkış 1.
5. Mutantı kendin dene: `RefundEligibility::usageBlockerFor()`'dan `hasPublished()` kolunu sil →
   `php artisan test --filter=RefundRequestTest` kırmızı → geri al.
6. Frontend'de `npm run check`.

### Plandan sapmalar

- **Damga silme akışında da basılıyor (K165; planda yalnızca yayın vardı).** Checkout yayındaki bir
  davetiye için de sipariş açar (yükseltme). O sipariş ödendiği an canlı bir davetiyeye hizmet eder ama
  `PublishInvitationAction`'dan hiç geçmez; davetiye üç gün içinde silinince damgasız serbest kalır ve
  iade edilirdi: aynı açık, yan kapıdan. Ödeme bildiriminde basmak davetiye satırını kilitlemeyi
  gerektirirdi; silme akışı onu ters sırada kilitlediği için para yolunda kilitlenme riski olurdu.
  Silme akışı davetiyeyi zaten kilitli tutuyor.
- **Kolon `timestampTz` değil `timestamp`.** Şemadaki bütün zaman kolonları tz'siz ve pgsql bağlantısında
  saat dilimi ayarı yok. Tek bir tz'li kolon, UTC olmayan oturumda (bu makine) göçteki
  `GREATEST(published_at, paid_at)` karşılaştırmasını kaydırırdı. Ayrıntı: göç kılavuzu §3.
- **D2: yeni engel `used`, `in_use` değil.** Serbest kalan hak Planlarım'da "Kullanılabilir" rozetiyle
  durur; `in_use`'un "hak bir davetiyede kullanılıyor" cümlesi aynı satırda çelişirdi. Hata kodu
  değişmediği için `contracts/` aynı; frontend tip + çeviri + açıklama satırı.
- **D3: onay tam uygunluğu değil yalnızca "kullanıldı mı"yı yeniden sorar** ve komut bunu para
  sorusundan önce de sorar (planda yalnızca Action vardı). Tam uygunluk açık talebi (her zaman dolu) ve
  14 günlük süreyi (yöneticinin gecikmesi kullanıcıyı cezalandırmamalı) de sorardı. Ön denetim, K141'in
  sırası (önce para, sonra komut) yüzünden: yalnızca Action reddetseydi para çoktan gitmiş olurdu.
- **D3'ün önkoşulu doğrulandı:** "talep açıkken yayın" sırayla imkânsız (donmuş hak 402) ama eşzamanlı iki
  istekle mümkün (iki bağlantılı deneme). Düzeltme durum makinesine dokunmadığı için §8 dur koşulu değil.
- **D4: sütunun üçüncü değeri `davetiyeye bağlı`.** Bağlı ama damgasız hak (yayındaki davetiyeye sonradan
  ödenen yükseltme) "hiç yayınlanmadı" görünmemeli.
- **D5: kural 1 `paid_at`'e bakar, durumu sormaz** (`consumed_at` doldurmasıyla aynı): iade edilmiş ama
  yayın yapmış eski siparişler de damgalanır. Zararsız (iade zaten yapıldı), tarihsel olarak doğru.
- **Frontend'de SSS cümlesi değişti** (plan yalnızca "Planlarım'daki engel metni" diyordu). "İade alabilir
  miyim?" cevabı 3 günlük serbest bırakmayı iade cümlesinin hemen arkasına koyuyordu; düzeltmeden sonra
  yanlış bir vaat olurdu. Yeni cümle Kullanım Şartları'ndaki "yayınlandığı anda hizmet ifa edilmiş sayılır"
  maddesiyle aynı şeyi söylüyor.
- **Dosya sahipliği:** `RefundCommands.md` hem 18.4.4'ü (§2.1 ön denetim) hem 18.4.5'i (§4–§5 sütun)
  anlatıyor; tek bir commit'e, 18.4.5'e girer. `ApproveRefundRequest.php` 18.4.4'te kalır.
- **Docs deposunun ağacı kirliydi** (§2 "boş değilse DUR"). Değişiklik tek boş satır, geçmiş bir prompt
  dosyasında ve benim değil; dokunulmadı, commit komutlarına alınmadı ve komut dosyasında uyarı olarak
  yazıldı. Durmak yerine devam ettim; maliyeti en kötü ihtimalle o satırın yanlış bir commit'e girmesi.
- **Ara commit'ler tek tek koşulmadı** (`git stash`/`checkout` yasak). Bağımlılık sırası analizle
  kontrol edildi: her adımın testi yalnızca kendisinden önceki adımların koduna dayanıyor.
- **Final incelemesi yazarın kendisi tarafından** yapıldı (oturum kuralı gereği ayrı bir inceleme ajanı
  açılmadı). İncelemede mantık hatası bulunmadı; dört yorum bloğu K18'in 1–3 satır sınırına indirildi.

### Gördüm, dokunmadım

| Bulgu | Ne |
|---|---|
| `TST-02` · — (yeni) | **Donmuş hak eşzamanlı yayında bağlanabiliyor.** `ClaimReleasedOrderAction`'ın `withoutOpenRefundRequest()` süzgeci bir alt sorgu; "iptal et" kilidi bıraktığında sipariş satırı değişmemiş olduğu için PostgreSQL süzgeci yeniden sınamıyor (iki bağlantılı deneme). K167 bunun **parasal** sonucunu onayda yakalıyor; kök düzeltme (kilit sonrası açık talebi yeniden okumak ya da talep açarken sipariş satırını güncellemek) ayrı iş |
| — | **Para gönderildi ama onay reddedildi.** Yönetici "Yayın" sütununa bakmadan parayı gönderirse sipariş `paid` kalır ve talep onaylanamaz; Shopier iade bildirimi gelirse sipariş `refunded` olur ve talep kapanır. Bilerek "yine de onayla" bayrağı eklenmedi |
| — | Frontend `DeleteAccountCard`: "Kullanılmamış N hakkınız … 14 gün içindeyse … iade isteyebilirsiniz" sayısı `available` kovasından; `used` engelli serbest haklar da bu sayıda (iade edilemedikleri hâlde) |
| — | Frontend `TermsPage` "İade talepleri İletişim sayfası üzerinden iletilir" diyor; Faz 14'ten beri talep Planlarım'dan açılıyor. Hukuki metin, İsmail'in kararı |
| — | Göçün 3. kuralı sezgisel; çöp kutusu boşaldıysa ya da sipariş sonradan güncellendiyse eski serbest haklar damgasız kalır (canlıda müşteri verisi yok) |

### Açık kalanlar (karar İsmail'in)

1. Para gönderilip onay reddedilirse ne yapılacağı (bugün: Shopier iade bildirimi beklenir ya da elle SQL).
2. Göçten sonra yerelde damgalanan 6 siparişin doğru olup olmadığı (yalnızca deneme verisi).
3. Kullanım Şartları'ndaki "İletişim sayfası üzerinden" ifadesi.

---

## 18.5 — `SEC-03`: Shopier `callback` kipinde `status` ve tutar imzasızdı

### Sorun

`SHOPIER_CONFIRMATION=callback` seçilirse siparişi `paid` yapan şey tarayıcının getirdiği formdu.
Callback imzası yalnızca `random_nr + platform_order_id`'yi kapsıyor; `status` ve tutar imzanın
dışında. Başarısız bir ödemenin imzalı callback'inde (Shopier'in böyle bir callback üretip
üretmediği **doğrulanmadı**) `status=failed` → `success` yapılınca imza yine geçerliydi; bildirim
tutar taşımadığı için `amountMatches()` de `true` dönüyordu. Varsayılan kip `osb` ve orada callback
hiçbir siparişi değiştirmiyor (K117); risk, güvensiz kipin tek bir `.env` satırı olması ve
`docs/10`'un onu geçerli bir seçenek diye anlatmasıydı. Kod okuması denetimdeki satırlarla aynıydı
(`ShopierGateway.php` 156–192 · `ShopierSignature.php` 49–62 · `config/payment.php:44` ·
`HandlePaymentCallbackAction.php` 219–227). Yayın kaydına göre sunucuda `osb` (şablondan, Y8.3b);
`callback` planlanmamış, bu yüzden "yayına çıkınca ödemeler durur" dur koşulu oluşmadı.

### Kararlar

| K | Karar (tek cümle) |
|---|---|
| **K170** | Üretimde (`App::isProduction()`) `callback` kipi bir yapılandırma hatasıdır: `ShopierGateway` kurulmaz, sır eksikliğiyle aynı sonuç (K123: `PaymentProviderException::unavailable` → 503 `PROVIDER_UNAVAILABLE`, sipariş yazılmaz) ve her kurulma denemesinde `CRITICAL Shopier callback confirmation mode refused in production`. Kip yalnızca yerel/test için kalır; tamamen kaldırılması K94'ün adım 7'si (OSB API ödemelerinde geliyor mu) doğrulandıktan sonra ayrıca karara bağlanır. K117'yi daraltır. |
| **K171** | Üretimde tutar taşımayan **`paid`** bildirimi `amountMatches()`'ten geçmez (geçiş yok, mevcut `critical` satırı, `notified_minor: null`). `failed`/`refunded` ve üretim dışı etkilenmez. K126'yı genişletir. |
| **K172** | Callback ucu sürücüyü `try`'ın içinde kurar; sürücü hangi nedenle kurulamazsa kurulmasın (K123, K170) kullanıcı 303 ile `/odeme/hata`'ya gider (`?order=` yok), sipariş değişmez, `warning Shopier callback rejected`. OSB ucu 503 kalır (karşıda makine var). K122'yi genişletir. |

**Daraltılan/genişletilen kararlar:** K117 → **K170** (üretimde `callback` yok; "`status` imzalı
gösterilirse `callback`'e geçilir" kuralı üretim için geçersiz: doğru düzeltme imza doğrulamasına
`status`'ü eklemek ve yeni bir K'dir). K122 → **K172**. K126 → **K171**. Geçersiz kılınan karar yok.
Durum notları `FAZ-12.md` §5'e işlendi; K94'e "kaldırma adım 7'den sonra" notu eklendi.

**D2 (kaldırmak mı?):** `phases/faz-12/FAZ-12-RAPOR.md`'ye göre §A'nın sekiz satırı boş, OSB'nin API
ödemelerinde gelip gelmediği doğrulanmadı (K94 ⚠️ geçici). Bu yüzden kip **kaldırılmadı**; kaldırma
önerisi K olarak yazılmadı (koşulu oluşmadı). Açık kalanlar madde 1.

### Değişen dosyalar

**Backend** (`fix/2026-11-20-sec-03-shopier-callback-mode`)

| Adım | Dosya | Ne | Kılavuz |
|---|---|---|---|
| 18.5.1 | `app/Services/Payment/ShopierGateway.php` | Kurucuda üretim + `callback` → `critical` + 503 | [`ShopierGateway.md`](../app/Services/Payment/ShopierGateway.md) §3, §11 |
| 18.5.1 | `app/Services/Payment/Shopier/ShopierConfirmation.php` | `Callback` vakasının yorumu: yalnızca yerel/test | aynı, §11 |
| 18.5.1 | `config/payment.php` | `confirmation` yorumu: üretimde 503 | [`config/payment.md`](../config/payment.md) §1, §3 |
| 18.5.1 | `tests/Feature/PaymentDriverResolutionTest.php` | +3 test | [`PaymentDriverResolutionTest.md`](../tests/Feature/PaymentDriverResolutionTest.md) |
| 18.5.2 | `app/Http/Controllers/Api/V1/ShopierCallbackController.php` | Sürücü `try`'ın içinde (`new`), `PaymentProviderException` → 303 hata sayfası | [`ShopierCallbackController.md`](../app/Http/Controllers/Api/V1/ShopierCallbackController.md) §8 |
| 18.5.2 | `tests/Feature/ShopierCallbackTest.php` | +2 test; bilinçli risk testinin yorumu "üretim dışında" | [`ShopierCallbackTest.md`](../tests/Feature/ShopierCallbackTest.md) |
| 18.5.3 | `app/Actions/Payment/HandlePaymentCallbackAction.php` | `amountMatches()`: üretimde tutarsız `paid` uymaz | [`HandlePaymentCallbackAction.md`](../app/Actions/Payment/HandlePaymentCallbackAction.md) §Faz 18.5 |
| 18.5.3 | `tests/Feature/PaymentNotificationAmountTest.php` | +2 test | [`PaymentNotificationAmountTest.md`](../tests/Feature/PaymentNotificationAmountTest.md) |
| 18.5.4 | `docs/10-URETIM-ENV-SABLONU.md` | `SHOPIER_CONFIRMATION`: üretimde yalnızca `osb` (D4) | — |
| 18.5.4 | `docs/rehber/fazlar/FAZ-18.md` · `README.md` · `FAZ-12.md` | Bu kayıt; K94, K117, K122, K126 satırlarına notlar | — |

Yeni hata kodu, config anahtarı, göç ve bağımlılık **yok**; `contracts/` değişmedi. Frontend
değişmedi: hata sayfası (`/odeme/hata`) zaten var.

**Docs** (`docs/2026-11-16-faz-18-audit-fixes`), adım 18.5.5: `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-18.md`
§18.5, `decisions/DECISION-INDEX.md` (K170–K172, sıradaki K173, K94/K117/K122/K126 notları),
`phases/faz-18/README.md`, `phases/faz-18/FAZ-18-SEC-03-COMMIT-KOMUTLARI.md`, `CHANGELOG.md`.

### Eklenen testler ve öldürdükleri mutantlar

Yeni test: 7 (`PaymentDriverResolutionTest` +3, `ShopierCallbackTest` +2, `PaymentNotificationAmountTest` +2).
Mevcut testlerden yalnızca iki docblock değişti (`callback_mode_trusts_the_unsigned_status_by_design`,
`a_notification_without_an_amount_is_not_compared`: "üretim dışında"); gövdeler aynı.

| Test | Kanıtladığı |
|---|---|
| `the_callback_confirmation_mode_is_refused_in_production` | Üretim + `callback` → checkout 503 `PROVIDER_UNAVAILABLE`, `orders` boş, `critical` log |
| `the_callback_mode_still_works_outside_production` | Regresyon: test ortamında `callback` + checkout 201 form |
| `osb_mode_works_in_production` | Regresyon: üretimde `osb` + checkout 201 form |
| `a_forged_success_in_callback_mode_does_not_pay_in_production` | Üretim + `callback` + imzalı `failed` gövdesinde `status=success` → 303 `/odeme/hata` (`?order=` yok), sipariş `pending`, `paid_at` boş |
| `without_secrets_the_callback_goes_to_the_failure_page` | Sır yok → 503 JSON değil 303 hata sayfası, sipariş `pending` |
| `a_paid_notification_without_an_amount_is_refused_in_production` | Üretimde tutarsız `paid` → `pending`, ödeme kimliği yazılmadı, `critical` (`notified_minor: null`) |
| `a_refund_notification_without_an_amount_still_applies_in_production` | Kural dar: tutarsız iade bildirimi üretimde de işlenir |

Mutantlar bir betikle uygulandı: dosya yedeklenir, tek bir metin değişikliği yapılır (uygulanmadıysa
betik durur), ilgili test süzgeci koşar, dosya yedekten geri konur ve SHA'sı karşılaştırılır.

| # | Mutant | Sonuç | Kırmızıya dönen |
|---|---|---|---|
| M1 | Kurucudaki üretim + `callback` bloğu silindi (prompt §6'daki hedef: "ortam kontrolünü kaldır") | ✅ öldü (2) | `the_callback_confirmation_mode_is_refused_in_production` · `a_forged_success_in_callback_mode_does_not_pay_in_production` |
| M2 | Koşuldan `&& App::isProduction()` silindi (kip her ortamda reddedilir) | ✅ öldü (12) | `the_callback_mode_still_works_outside_production` · `callback` kipini kullanan `ShopierCallbackTest`/`ShopierGatewayTest`/`ShopierOsbTest` testleri |
| M3 | Callback ucunun `catch`'inden `PaymentProviderException` çıkarıldı | ✅ öldü (2) | `a_forged_success_…_in_production` · `without_secrets_the_callback_goes_to_the_failure_page` (303 yerine 503) |
| M4 | `amountMatches()`: tutarsız bildirimde `return true;` (D3'ün hedefi) | ✅ öldü | `a_paid_notification_without_an_amount_is_refused_in_production` |
| M5 | Durum koşulu silindi (`return ! App::isProduction();`) | ✅ öldü | `a_refund_notification_without_an_amount_still_applies_in_production` |
| M6 | Ortam koşulu silindi (`return $notification->status !== OrderStatus::Paid;`) | ✅ öldü (9) | `a_notification_without_an_amount_is_not_compared` · `PaywallTest`'in dört Fake webhook testi · `callback` kipinin dört ödeme testi |

### Doğrulama durumu (B7)

Makine: Windows 11, PHP 8.5.12 (Herd), PostgreSQL 18.4 (`davetkart_test`), Windows PowerShell 5.1.
Kod ve koşular 20 Kasım 2026.

| Komut | Sonuç | Çıktıdan |
|---|---|---|
| Önkoşullar (prompt §2) | ⚠️ kısmen | backend ağacı boş, `faz-18-audit-fixes`'te; `git branch --merged faz-18-audit-fixes` içinde `fix/2026-11-17-sec-02-refund-after-publish` var. **Docs ağacı boş değildi:** ` M prompts/PROMPT-FAZ-18-3-SEC-01.md` (18.4'teki aynı tek boş satır; benim değil). Dokunulmadı, commit komutlarına girmedi |
| Başlangıç: Shopier test süzgeci (5 sınıf) | ✅ | `tests=58 passed=58` |
| 18.5.1 RED: `--filter=PaymentDriverResolutionTest` | ❌ beklenen | `tests=10 passed=9 failed=1`: `Expected response status code [503] but received 201` |
| 18.5.1 GREEN (5 sınıf) | ✅ | `tests=61 passed=61 assertions=175` |
| PHPStan (18.5.1 sonu) | ❌ → ✅ | İlk koşu 30 hata, çoğu değişmemiş dosyalarda ("docblock'u olan metotta tip yok"): ilk denemeyi `PHPSTAN_TURBO=0` olmadan yaptım, `%TEMP%\phpstan` önbelleği bozuldu ([`phpstan.md`](../phpstan.md) "turbo" bölümü). Scratchpad'de ayrı önbellekle 1 gerçek hata (`LegacyMockInterface::with()`); önbellek kılavuzdaki gibi silindi; `->once()` da aynı nedenle çıkarıldı → `phpstan errors: 0` |
| 18.5.2 RED: `--filter=ShopierCallbackTest` | ❌ beklenen | `tests=15 passed=13 failed=2`: `Expected response status code [303] but received 503` (`PaymentProviderException` kurucudan) |
| 18.5.2 GREEN | ✅ | `tests=63 passed=63`; PHPStan önce *"Dead catch"* (kapsayıcıyla kurulum), sonra *"@throws … not thrown"* (yardımcı metot) → `new ShopierGateway` → `errors: 0` |
| 18.5.3 RED: `--filter=PaymentNotificationAmountTest` | ❌ beklenen | `tests=7 passed=6 failed=1`: `Pending` beklenirken `Paid` |
| 18.5.3 GREEN (5 sınıf + Paywall/Refund/FirstPublishFinalGate) | ✅ | `tests=137 passed=137 assertions=467` · `pint passed` · `phpstan errors: 0` |
| **`composer check`** (iki kez: kod bittikten sonra ve son ağaçta, faz kaydından sonra) | ✅ | `pint passed` · `phpstan errors: 0` · `Katalog guncel.` · `Plan kurallari sozlesmesi guncel.` · `tests: 1166, passed: 1166, assertions: 4322` · çıkış 0 (iki koşuda aynı) |
| Mutantlar M1–M6 | ✅ 6/6 öldü | yukarıdaki tablo; her dosyanın SHA'sı geri konduktan sonra aynı; `git diff --stat` önce ve sonra `17 files changed, 438 insertions(+), 28 deletions(-)`, `git diff \| sha1sum` önce ve sonra `89513f990673` (faz kaydı yazılmadan önce) |
| Frontend `npm run check` | koşmadı | frontend'e dokunulmadı |
| Sunucudaki `.env` (`SHOPIER_CONFIRMATION`) | koşmadı | canlı sisteme bağlanılmadı; `osb` olduğu yayın kaydından (Y8.3b, şablondan) okundu, gözlenmedi |
| Tarayıcıda elle deneme | koşmadı | sunucular çalıştırılmadı; adımlar aşağıda |
| Shopier, sunucu, AWS, Sentry | koşmadı | canlı sisteme istek atılmadı |

### Elle doğrulama (İsmail)

1. Backend'de `composer check`: son satırda `"tests":1166,"passed":1166`.
2. **Yayından önce** sunucuda (salt okunur): `grep -n '^SHOPIER_CONFIRMATION' <backend dizini>/.env`.
   `osb` ya da satır yok (varsayılan `osb`) olmalı. `callback` görürsen bu dal yayına çıktığı an
   kartla ödeme 503 olur: önce satırı `osb` yap, sonra `php artisan config:cache`.
3. Yerelde kuralın kendisi (`php artisan tinker`):
   ```php
   config(['payment.providers.shopier.api_key' => 'k', 'payment.providers.shopier.api_secret' => 's',
           'payment.providers.shopier.confirmation' => 'callback']);
   new App\Services\Payment\ShopierGateway;            // kurulur
   app()->detectEnvironment(fn () => 'production');
   new App\Services\Payment\ShopierGateway;            // PaymentProviderException
   ```
   `storage/logs/laravel.log`'da `CRITICAL: Shopier callback confirmation mode refused in production`.
4. Mutantı kendin dene: `ShopierGateway::__construct`'taki üretim bloğunu sil →
   `php artisan test --filter=PaymentDriverResolutionTest` kırmızı → geri al.
5. Kart açıldığında `FAZ-12-ELLE-DOGRULAMA.md` adım 6 (çevrilmiş durum saldırısı) aynen geçerli;
   adım 7'nin sonucu K94'e ve `callback` kipinin kaldırılması kararına girer.

### Plandan sapmalar

- **D3 daraltıldı: yalnızca `paid`.** Plan "tutar yoksa üretimde eşleşme sayılmaz" diyordu. Tutar,
  **hak açan** kararın kanıtı; `failed`'ı reddetmek bir şey korumaz, `refunded`'ı reddetmek ters
  yönde hata olurdu (para geri gitmiş, hak açık). Ayrıca D3 yerel/test akışını bozmasın diye
  yalnızca üretimde. Bugün üretimde tutarsız `paid` üreten yol yok (K170 sonrası); K171 ikinci kilit.
- **K172 planda ayrı bir karar değildi (D1'in "callback ucu güvenli yönlendirsin" maddesi).** Sürücü
  metot parametresi olduğu için kurucu istisnası `try`'a hiç girmiyor, insan 503 JSON görüyordu.
  Düzeltme sır eksikliği durumunu da kapsıyor: o durumda da artık 503 JSON yerine hata sayfası
  (davranış değişikliği, testli).
- **`new ShopierGateway` (bağımlılık enjeksiyonu değil).** Kapsayıcıyla kurmak PHPStan'de
  "dead catch", `@throws`'lu yardımcı metot "throws.unusedType" verdi. Kurucunun bağımlılığı yok;
  kapsayıcıda özel bağlaması yok. Gerekçe controller kılavuzu §8.
- **"Kurulumda bir kez `Log::critical`"**: her kurulma denemesinde bir satır (checkout, callback,
  OSB). Test sayıyı değil mesajı doğruluyor: `shouldHaveReceived(...)->once()` PHPStan'de tip
  hatası veriyor; projenin diğer log testleri de sayı denetlemiyor.
- **Docs deposunun ağacı kirliydi** (prompt §2 "boş değilse DUR"): 18.4'te görülen aynı tek boş
  satır (`prompts/PROMPT-FAZ-18-3-SEC-01.md`). 18.4'te de aynı şekilde devam edilmişti; dokunulmadı.
- **PHPStan önbelleği:** `%TEMP%\phpstan` silindi (yalnızca PHPStan'in geçici önbelleği, kılavuzdaki
  çözüm). Bozulmasının nedeni benim ilk koşum (turbo kapatılmadan).
- **Ara commit'ler tek tek koşulmadı** (`git stash`/`checkout` yasak). Bağımlılık sırası: 18.5.2'nin
  `a_forged_success_…_in_production` testi 18.5.1'in kurucu kuralına dayanır; 18.5.3 bağımsız.

### Gördüm, dokunmadım

| Bulgu | Ne |
|---|---|
| `SEC-07` (kapsam dışı) | `docs/10` ve `FAZ-12-ELLE-DOGRULAMA.md` panel adreslerini `api.davetkart.com` üzerinde gösteriyor; yayın kaydı (Y8.3a) üretimin aynı kökenli `davetkart.com` olduğunu ve şablonun eskidiğini yazıyor. Callback adresi `url(CALLBACK_PATH)` ile `APP_URL`'den üretiliyor |
| — | `docs/rehber/tests/Feature/PaymentDriverResolutionTest.md` "Sipariş yazılmaz" bölümü sürücünün `StartCheckoutAction` kurucusunda alındığını söylüyor; K136'dan beri `PaymentGatewayResolver` transaction içinde kuruyor. Sonuç (sipariş yazılmaz) aynı, gerekçe eskimiş |
| — | `PAYMENT_PROVIDER=fake` üretimde engellenmiyor. K171 sonrası Fake'in webhook'u üretimde `paid` üretemez; checkout ise Fake'te başarı sayfasına yönlendirip siparişi `pending` bırakır. Ayrı bir koruma (üretimde Fake → 503) değerlendirilebilir |
| — | docs deposu `operations/deployment/YAYIN-DEVIR.md` Y8 satırı `PAYMENT_PROVIDER=fake` diyor; `YAYIN-PLANI.md` Y8.3b gerçekleşeni `shopier` + `PAYMENT_METHODS=gift_code` olarak yazıyor |
| — | OSB ucu üretimde `callback` kipindeyken 503 döner; Shopier'in tekrar deneme politikası bilinmiyor (§A-c) |

### Açık kalanlar (karar İsmail'in)

1. `callback` kipinin tamamen kaldırılması: K94 adım 7 OSB'yi doğrularsa öneri yeni bir K olarak gelir.
2. Adım 7'de OSB **gelmezse** üretimde ödeme onaylanamaz: çözüm `callback` değil, sunucudan sunucuya
   bir sorgu ya da `status`'ü imzalayan bir formül (yeni K).
3. Sunucudaki `SHOPIER_CONFIRMATION` değerinin yayından önce gözle doğrulanması (elle doğrulama 2).

---

## 18.6 — `SEC-04`: SPA sayfalarında güvenlik başlığı yoktu; token `localStorage`'da

### Sorun

Güvenlik başlıklarını Faz 9'dan beri Laravel'in `SecurityHeaders` ara katmanı basıyordu (K85). Ara
katman yalnızca Laravel'in ürettiği yanıtları görür, yani `/api/*`'ı. `index.html`, JS, CSS ve videolar
Nginx'ten doğrudan çıkıyordu ve hiçbir başlık taşımıyordu: CSP yok, `X-Frame-Options` /
`frame-ancestors` yok, `nosniff` yok. HSTS'yi tarayıcı ancak ilk `/api` yanıtından öğreniyordu. Site
görünmez bir çerçeveye konup tıklatılabiliyordu (clickjacking). Oturum token'ı `localStorage`'da
durduğu için yarın çıkacak tek bir XSS onu okuyup dışarı gönderebilirdi ve araya giren ikinci bir
kilit yoktu. Kod okuması denetimdeki satırlarla aynıydı (`deploy/nginx/davetkart.conf` 53–108: yalnızca
`Cache-Control`; `SecurityHeaders.php` 40–77; frontend `src/services/auth.ts` 136/148/156). Nginx
yapılandırması dağıtım betiklerinde kurulmuyordu (kılavuz §5, elle `cp`).

### Kararlar

| K | Karar (tek cümle) |
|---|---|
| **K173** | SPA başlıkları Nginx'te, tek kaynak `deploy/nginx/snippets/davetkart-security-headers.conf`. API dışındaki dört `location` onu `include` eder, `server` seviyesi etmez. `/api/*` yalnızca Laravel'den. Kurulum `deploy/nginx/install.sh`. K85'i daraltır. |
| **K174** | Hemen zorlananlar: HSTS `max-age=31536000; includeSubDomains` (preload yok), `nosniff`, `strict-origin-when-cross-origin`, `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'; base-uri 'self'; object-src 'none'`; hepsi `always`. |
| **K175** | Tam CSP `-Report-Only` kipinde ve envantere dayalı. Toplayıcı uç yok. Zorlamaya geçiş bir haftalık gözlemden sonra, ayrı değişiklikle ve ayrı K ile. |
| **K176** | `Permissions-Policy`: `accelerometer`, `gyroscope`, `fullscreen` `(self)`; `magnetometer`, `camera`, `microphone`, `geolocation`, `payment`, `usb` kapalı. |
| **K177** | Token `localStorage`'da kalır (açık karar). httpOnly çerez `SEC-18`'in işi, yayından sonra; o zamana kadar CSP ikinci kilit. |
| **K178** | Duman testi `deploy/smoke/check-security-headers.sh`: her `location` türünden bir yol + API'de tek kaynak. `install.sh` sonrası ve her dağıtımdan sonra çalışır (docs `YAYIN-SIRASI.md` §6); `deploy.sh` çağırmaz. |

**Daraltılan karar:** K85 → **K173** (K85 yalnızca `/api/*` için; durum notu [`FAZ-9.md`](FAZ-9.md)'deki
satırında). Geçersiz kılınan karar yok.

### Değişen dosyalar

**Backend** (`fix/2026-11-20-sec-04-security-headers`)

| Adım | Dosya | Ne | Kılavuz |
|---|---|---|---|
| 18.6.1 | `deploy/nginx/snippets/davetkart-security-headers.conf` (yeni) | 7 başlık; iki CSP (zorlanan küçük + rapor kipinde tam) | [`davetkart-security-headers.md`](../deploy/nginx/snippets/davetkart-security-headers.md) |
| 18.6.1 | `deploy/nginx/davetkart.conf` | 4 SPA `location`'ına `include`; API konumlarına yorum; başlık satırı | [`davetkart.md`](../deploy/nginx/davetkart.md) "HSTS", "Güvenlik başlıkları", §5, §6 |
| 18.6.2 | `deploy/nginx/install.sh` (yeni) | Snippet + site → `nginx -t` → reload; kırmızıysa eskileri geri koyar | [`install.md`](../deploy/nginx/install.md) |
| 18.6.3 | `deploy/smoke/check-security-headers.sh` (yeni) | 6 yol, sayım (tam bir kez), çıkış 0/1/2 | [`check-security-headers.md`](../deploy/smoke/check-security-headers.md) |
| 18.6.4 | `docs/rehber/fazlar/FAZ-18.md` · `README.md` · `FAZ-9.md` · `docs/rehber/app/Http/Middleware/SecurityHeaders.md` | Bu kayıt + faz kapanışı; K85 durum notu; ara katman kılavuzuna "yalnızca API" notu | — |

PHP kodu, test, göç, config, hata kodu, bağımlılık **yok**; `contracts/` değişmedi. 18.6.1'in
kılavuzu `install.sh`'a (18.6.2) ve duman testine (18.6.3) ileri bağlantı veriyor: üçü aynı dalda
birleşir.

**Frontend:** değişmedi, commit yok. Yerel deneme `vite preview` ile yapıldı ama yapılandırma
scratchpad'deydi ve başlıkları snippet'ten okudu (tek kaynak). `preview.headers` kalıcı **yapılmadı**:
kalıcı olsaydı ya ikinci bir kopya olurdu ya da frontend kardeş depodan okurdu (K147'nin "kopya, kardeş
depodan okuma yok" desenine aykırı, CI'da kırılır).

**Docs** (`docs/2026-11-16-faz-18-audit-fixes`), adım 18.6.6: `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-18.md`
§18.6, `decisions/DECISION-INDEX.md` (K173–K178, K85 notu, sıradaki K179), `operations/deployment/YAYIN-SIRASI.md`
(§4.4 Nginx kurulumu, §6 duman testi), `phases/faz-18/README.md`, `phases/faz-18/FAZ-18-SEC-04-COMMIT-KOMUTLARI.md`,
`phases/README.md`, `CHANGELOG.md`.

### Yerel deneme düzeneği

| Ne | Nasıl |
|---|---|
| Nginx | Herd'in `nginx.exe`'si (1.25.2, ssl + http2). Depodaki `davetkart.conf` + snippet'in **kopyası**; yalnızca ortama özgü satırlar değişti: 80/443 → 18080/18443, `[::]` satırları, sertifika (kendinden imzalı `davetkart.com`), `options-ssl-nginx.conf`, dhparam, iki `root`, `fastcgi_pass` → `127.0.0.1:19000` (`diff` çıktısı oturumda görüldü). İstekler `curl --resolve davetkart.com:18443:127.0.0.1` |
| API | Herd'in `php-cgi.exe -b 127.0.0.1:19000`'ı; gerçek `public/index.php`, gerçek `SecurityHeaders` |
| Tarayıcı | `vite preview` (frontend `npm run build` çıktısı), başlıklar snippet'ten okunarak; `/api` → `php -S 127.0.0.1:8000` (scratch veritabanı `davetkart_sec04`, sonunda silindi). Playwright (chromium-1228) 12 adım: ana sayfa, fiyatlar, giriş (arayüzden), panel, editör sihirbazı + konum seçici (OSM `<iframe>`), dört misafir sayfası (`dugun-gul-3d` WebGL, `dugun-harita-kir-yolu` çalışma anında Google Fonts `<link>`'i, `nisan-manzara`, `dogum-gunu-mumlar` JSX `<style>`), ödeme (`/odeme?tier=gold&invitation=…`), hesap, mobilde 3D şablon. İhlaller `securitypolicyviolation` olayından ve konsoldan toplandı |

### Tarayıcı denemesi: önce / sonra

| Politika | Kip | Adım | İhlal | Sayfa hatası | Ne oldu |
|---|---|---|---|---|---|
| Denetimin örnek CSP'si (`REPORT.md` §4) | rapor | 12 | **2** (`frame-src https://www.openstreetmap.org`) | 0 | Harita açık kaldı, konsola ihlal yazıldı |
| Denetimin örnek CSP'si | **zorla** | 12 | **1** (aynı) | 0 | 🔴 Konum seçicinin haritası **boş gri kutu** (ekran görüntüsüyle görüldü) |
| Bu dal (snippet) | rapor + küçük CSP zorla | 12 | **0** | 0 | Konsolda CSP ya da `Permissions-Policy` satırı yok (`Unrecognized feature` dahil) |
| Bu dal, tam CSP | **zorla** | 12 | **0** | 0 | Harita, Google Fonts, 3D canvas, ödeme özeti (API'den fiyat) çizildi (ekran görüntüleriyle görüldü) |
| Bu dal, tam CSP, `'unsafe-inline'` **çıkarılmış** | zorla | 12 | **1** (`style-src-elem inline`, `dogum-gunu-mumlar`) | 0 | `'unsafe-inline'`'ın gerçekten gerektiğinin kanıtı (11 şablon JSX `<style>` çiziyor) |

Envanterden düşen: `https://images.unsplash.com` (`NisanManzara.tsx:18`) ölü kod, çünkü `bgImage`
içe aktarılan yerel bir dosya ve hiç boş değil. Politikaya girmedi. Görülmeyenler: gerçek medya alan
adı (yerelde medya `localhost:8000/storage`'dan), Shopier'e form gönderimi ve dönüşü (yerelde kart
kapalı, Shopier'e istek atılmadı), `blob:` yükleme önizlemeleri, iOS Safari. Gözlem haftası (K175)
bunlar için.

### Eklenen testler ve öldürdükleri mutantlar

PHPUnit testi yok (PHP kodu değişmedi). Sınama araçları: duman testi betiğinin kendisi (depoda) ve
kurulum betiğinin senaryo düzeneği (scratchpad'de; aynı yöntem [`install.md`](../deploy/nginx/install.md)
§4'te elle tekrarlanabilir). Kurulum betiğinin beş senaryosu yeşil: güncelleme + `nginx -t` yeşil
(dosyalar yeni, `nginx -t` sonra reload), güncelleme + kırmızı (iki dosya eski hâline döndü, reload
yok, çıkış 1), ilk kez kurulan snippet + kırmızı (snippet silindi), `sites-enabled` bağlantısı yok
(hiçbir dosyaya dokunulmadı, `nginx` çağrılmadı), gerçek `/etc/nginx` + root değil (çıkış 1).

Mutantlar bir betikle uygulandı: dosya yedeklenir, tek bir değişiklik yapılır (uygulanmadıysa betik
durur), Nginx yeniden yüklenir (Nginx mutantları) ve ilgili sınama koşar, dosya yedekten geri konur
ve SHA'sı karşılaştırılır.

| # | Mutant | Sonuç | Kırmızıya dönen |
|---|---|---|---|
| N1 | `= /index.html`'den `include` silindi | ✅ öldü | duman testi `/` ve SPA yolu `EKSIK` (7 başlık 0 kez) |
| N2 | `location /`'tan `include` silindi | ✅ öldü | `/favicon.svg` `EKSIK` |
| N3 | `^~ /assets/`'tan `include` silindi | ✅ öldü | `/assets/index-….js` `EKSIK` |
| N4 | Nokta yolları konumundan `include` silindi | ✅ öldü | `/.env` (404) `EKSIK` |
| N5 | **Miras tuzağı:** dört `include` silinip tek `include` `server` seviyesine kondu (prompt §6) | ✅ öldü | `/`, SPA yolu, `/assets/` `EKSIK` (kendi `add_header`'ları var, miras düştü); `/favicon.svg` ve `/.env` OK (kendi `add_header`'ları yok, miras aldı); `/api/ping` `EKSIK`: HSTS, `nosniff`, `X-Frame-Options`, `Referrer-Policy`, CSP **2 kez**, `-Report-Only` ve `Permissions-Policy` sızdı |
| N6 | Snippet'ten `X-Content-Type-Options` silindi | ✅ öldü | beş SPA yolu `EKSIK` |
| N7 | Snippet'ten `-Report-Only` satırı silindi | ✅ öldü | beş SPA yolu: `content-security-policy: "default-src" yok` |
| I1 | `install.sh`: kırmızıda `restore` çağrısı silindi | ✅ öldü | senaryo 2 ve 3: dosyalar eski hâline dönmedi (4 denetim) |
| I2 | `install.sh`: `nginx -t` yerine `true` | ✅ öldü | 8 denetim (reload sınamadan yapıldı, geri alma hiç çalışmadı) |
| — | Duman testi `vite preview`'a karşı, başlıklar kaldırılmış (`SEC04_MODE=none`, prompt §6) | ✅ kırmızı | beş SPA yolu `EKSIK`, çıkış 1 (başlıklarla aynı komut çıkış 0) |

Geri alma: `git diff | sha1sum` mutantlardan önce ve sonra `bdc34fd17d67`, üç yeni dosyanın (snippet,
`install.sh`, duman testi) birleşik SHA'sı önce ve sonra `25921fb5275b`; `git diff --stat` önce ve
sonra `2 files changed, 64 insertions(+), 9 deletions(-)` (o anki ağaç; faz kaydı yazılmadan önce).
Geri alındıktan sonra duman testi `SONUC: tamam`, kurulum senaryoları `basarisiz: 0`.

### Doğrulama durumu (B7)

Makine: Windows 11, PHP 8.5.12 (Herd), PostgreSQL 18.4, Node 24.18, Herd Nginx 1.25.2, Windows
PowerShell 5.1. Koşular 20 Kasım 2026.

| Komut | Sonuç | Çıktıdan |
|---|---|---|
| Önkoşullar (prompt §2) | ✅ | Üç ağaç boş; backend ve frontend `faz-18-audit-fixes`'te; `git branch --merged faz-18-audit-fixes` içinde `fix/2026-11-20-sec-03-shopier-callback-mode`; docs `docs/2026-11-16-faz-18-audit-fixes`'te |
| `nginx -t` (sertifikası sahte kopya, Herd 1.25.2) | ✅ | `syntax is ok` · `test is successful`; yalnızca 1.25'in `listen … http2 directive is deprecated` uyarısı (sunucudaki 1.24'te yok, `davetkart.md` §3) |
| Başlıklar, yerel Nginx (`curl`) | ✅ | `/`, `/dashboard`, `/assets/index-CgNoz2oc.js`, `/favicon.svg`, `/.env` (404), `/assets/yok.js` (404): yedi başlık; `/api/ping`: Laravel'in altı başlığı tam bir kez (`no-referrer`, `default-src 'none'`) |
| Duman testi → yerel Nginx | ✅ | altı yol `OK`, `SONUC: tamam`, çıkış 0; kullanım hatası çıkış 2; bağlantı yok `HATA: … alinamadi` çıkış 2 |
| Duman testi → `vite preview` (snippet başlıkları) | ✅ | `--skip-api` ile beş yol `OK`, çıkış 0; başlıksız (`none`) çıkış 1 |
| Playwright, beş kip × 12 adım | ✅ | yukarıdaki önce/sonra tablosu; her kipte `basarisizAdim=0`, `sayfaHatasi=0` |
| Mutantlar N1–N7, I1–I2 | ✅ 9/9 öldü | yukarıdaki tablo; geri alma SHA ile doğrulandı |
| Kurulum betiği senaryoları (sahte `nginx`/`systemctl`) | ✅ | 17 denetim, `basarisiz: 0` |
| **`composer check`** (son ağaç, faz kaydından önce) | ✅ | `pint passed` · `phpstan errors: 0` · `Katalog guncel.` · `Plan kurallari sozlesmesi guncel.` · `tests: 1166, passed: 1166, assertions: 4322` · çıkış 0 |
| **`composer audit`** | ❌ (`SEC-07`, kapsam dışı) | çıkış 1 · `Found 9 security vulnerability advisories affecting 6 packages`: `laravel/framework` v13.35.0 (1), `symfony/filesystem` v8.1.9 (1), `symfony/http-foundation` v8.1.9 (4), `symfony/http-kernel` v8.1.1 (1), `symfony/mime` v8.1.0 (1), `symfony/routing` v8.1.0 (1). Denetim günü 8'di |
| Frontend `npm ci` | ✅ | çıkış 0 · `found 0 vulnerabilities` (`allow-scripts` uyarısı: `esbuild` kurulum betikleri) |
| Frontend `npm run check` (lint + build + verify) | ✅ | çıkış 0 · son satırlar `✓ Plan kuralları backend sözleşmesiyle aynı …` · `✓ Ekrana çizilen doğru …` |
| Sunucuda `nginx -t`, `install.sh`, duman testi | koşmadı | canlı sisteme bağlanılmadı; komutlar aşağıda ve docs `YAYIN-SIRASI.md` §4.4, §6 |
| Shopier form gönderimi (`form-action`) | koşmadı | yerelde kart kapalı; Shopier'e istek atılmadı (gözlem haftası) |
| iOS Safari, gerçek `media.davetkart.com` | koşmadı | yerel ortamda yok |

### Elle doğrulama (İsmail)

1. Backend'de `composer check` (son satır `"result":"passed"`).
2. **Yayında, backend dağıtımından sonra** (sunucuda, sudo yetkili kullanıcıyla):
   `cd /var/www/davetkart/backend && sudo bash deploy/nginx/install.sh`. Beklenen son satırlar:
   `nginx: configuration file /etc/nginx/nginx.conf test is successful` → `==> [nginx] reload (kesintisiz)`.
3. Duman testi (kendi bilgisayarından): `bash deploy/smoke/check-security-headers.sh https://davetkart.com`
   → altı `OK`, `SONUC: tamam`.
4. Tarayıcıda: DevTools → Network → `davetkart.com` belgesi → Response Headers'ta yedi başlık. Console'da
   `report-only Content Security Policy` satırı **olmamalı**; varsa not et (gözlem haftası).
5. Clickjacking'i gör: masaüstüne `test.html` yaz: `<iframe src="https://davetkart.com/" width=800 height=600></iframe>`;
   tarayıcıda aç → çerçeve boş, konsolda `X-Frame-Options` / `frame-ancestors` iletisi.
6. Bir hafta: ödeme (kart açıksa Shopier'e gidiş ve dönüş), galeriye fotoğraf yükleme, telefonda 3D
   şablon. Console'daki `report-only` satırları zorlamaya geçiş kararının girdisi (K175).
7. Mutantı kendin dene (yerelde): `davetkart.conf`'ta `location ^~ /assets/` içindeki `include` satırını
   sil → `nginx -t` yine yeşil → duman testi `/assets/…` için `EKSIK` → geri al. "Nginx bunu
   okuyabiliyor" ile "başlıklar çıkıyor" ayrı sorulardır.

### Plandan sapmalar

- **D1: snippet `server` seviyesine konmadı.** Plan "`davetkart.com` sunucu bloğuna **ve** kendi
  `add_header`'ı olan her `location`'a" diyordu. `server` seviyesi `/api/` ve `/index.php` konumlarına
  miras kalır (ikisinin de `add_header`'ı yok). API yanıtı her başlığı Laravel'inkiyle birlikte iki kez
  taşır, `Referrer-Policy` çelişir (mutant N5 bunu gösterdi). Sonuç planın niyetiyle aynı: her SPA
  yanıtı başlık taşıyor, tek kaynak snippet. Bedeli şu: yeni bir SPA `location`'ı eklenirse `include`
  elle yazılmalı. Bunu duman testi yakalar (N2–N4). K173 ⚠️.
- **D2: `X-Frame-Options: DENY` / `frame-ancestors 'none'`.** Envanterde aynı kökenden çerçeve yok,
  bu yüzden plandaki `'self'` seçeneği kullanılmadı.
- **D3: Unsplash politikaya girmedi** (ölü kod). `'unsafe-inline'` planda vardı, ölçülerek doğrulandı.
- **D6: duman testi API'yi de sınıyor** (planda yalnızca SPA başlıkları vardı). Gerekçe: K85/K173
  sınırını ve yinelenmeyi yakalayan tek yer burası.
- **18.6.2 bir betik oldu** (plan "dağıtım betiği ya da kılavuz" diyordu). Elle `cp` → `nginx -t`
  sırası, test kırmızıyken diskte bozuk dosya bırakıyordu ([`install.md`](../deploy/nginx/install.md) §1).
- **Faz 18 kapanışında etiket komutları verilmedi:** `composer audit` kırmızı (`SEC-07`, prompt §11.5).
- 🔴 **Yerel geliştirme veritabanına yanlışlıkla yazıldı, geri alındı.** İlk denemede backend'i
  `php artisan serve` ile scratch veritabanına yönlendirmek istedim. `artisan serve` alt süreçlere
  ortam değişkenlerini süzerek geçirdiği için `DB_DATABASE` gitmedi. Deneme kullanıcısı (id 6), token'ı
  ve üç taslak davetiye `davetkart` veritabanına yazıldı. Bağlı bütün tablolar FK'lerden sayıldı (kuyruk,
  oturum, sipariş, medya, LCV, zaman çizelgesi: 0). Yalnızca o 5 satır kimlikleriyle, tek transaction'da
  silindi. Sonra `users` 5 satır, en büyük id 5. Kalan tek iz `users` dizisi: bir sonraki kullanıcının
  id'si 7 olacak. Sonraki koşular `php -S` ile yapıldı: önce olmayan bir veritabanıyla (500 `database
  … does not exist`), sonra geliştirme veritabanında yayında olan bir davetiyenin 404 dönmesiyle
  hedef doğrulandı, ondan sonra yazıldı.

### Gördüm, dokunmadım

| Bulgu | Ne |
|---|---|
| `SEC-07` | `composer audit` 6 pakette 9 açık (yukarıda). Etiketin önkoşulu |
| `SEC-12` | Google Fonts (`index.css:1` + beş `fontlar.ts`) CSP'de iki Google alan adını zorunlu kılıyor; kendi alan adından sunulursa çıkar |
| `SEC-18` | Token `localStorage`'da (K177). Ayrıca `src/services/rsvpReceipts.ts` misafirin LCV makbuzunu da `localStorage`'da tutuyor; httpOnly çereze geçişte o da değerlendirilmeli |
| `FE-50` | Editör sihirbazı her ziyarette bir taslak bıraktı (deneme veritabanında 5 koşu → 5 taslak). Denetimdeki bulguyla aynı |
| — | `NisanManzara.tsx:18` Unsplash yedeği ölü kod (`bgImage || '…'`, `bgImage` hiç boş değil); silinebilir |
| — | `nisan-manzara` kimlikli davetiye `TemplateRenderer`'ın `nisan-manzara` eşlemesine rağmen başka bir görünümle çizildi (ekran görüntüsü); şablon kataloğunda (`data.ts`) bu kimlik yok. Ayrıca incelenmedi |
| — | Vite derlemesi `index-*.js` 1,63 MB uyarısı veriyor (denetimdeki performans bulgusu) |

### Açık kalanlar (karar İsmail'in)

1. Bir haftalık gözlemden sonra tam CSP'nin zorlanması (yeni K; kılavuz §5'teki adımlar).
2. Rapor toplayıcı uç (`report-to` / `report-uri`): ihlalleri konsol yerine bir yere toplamak.
3. HSTS `preload` (geri alınamaz; ayrı karar).
4. Token'ın httpOnly çereze taşınması (`SEC-18`).
5. `deploy-frontend.sh`'ın sonunda duman testinin uyarı olarak çağrılması (K178'de "hayır"; yeniden
   değerlendirilebilir).
