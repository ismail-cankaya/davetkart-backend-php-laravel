# FAZ 18 — Denetim düzeltmeleri (`AUD-2026-11-12`)

> **Başlangıç:** 16 Kasım 2026 · **Entegrasyon dalı:** `faz-18-audit-fixes` (`d810410` üstü; frontend'de `main`'deki `47aedc8` birleştirildi)
> **Durum:** sürüyor (1/6). Her bulgu ayrı bir `fix/…` dalında kapanır, `faz-18-audit-fixes`'e birleşir; faz sonunda `main` + `v1.1.0`.
> **Kaynak:** docs deposu `audits/2026-11-12-full-system-audit/REPORT.md` §1 ve §4 · takip tablosu `phases/faz-18/README.md`
> **Kararlar:** docs deposu `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-18.md`
> **Önceki:** [`FAZ-15.md`](FAZ-15.md). Faz 16–17'nin kaydı yok (`ARC-02`, bu fazın kapsamı dışında).

12–15 Kasım 2026 genel sistem denetimi altı yüksek (🟠) bulgu çıkardı. Hepsi yayından önce kapanır.

| Sıra | Bulgu | Konu | Dal | K | Durum |
|---|---|---|---|---|---|
| 18.1 | `OPS-01` | Yayına çıkacak commit ve dağıtım sırası; plan kuralları sözleşmesi (+ `TST-01`) | `fix/2026-11-16-ops-01-release-alignment` | K146–K150 | kod ✅ · `composer check` ✅ 1074 test · frontend `npm run check` ✅ · sunucu adımları koşmadı. Commit ve birleştirme durumu: docs deposu `phases/faz-18/README.md` |
| 18.2 | `OPS-02` | Veritabanı yedeği ve geri yükleme provası | — | — | bekliyor |
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
