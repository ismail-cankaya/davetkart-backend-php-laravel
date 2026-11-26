# Faz 21 — Ertelenmiş düşük bulgular ve açık kararlar

> **Başlangıç:** 27 Kasım 2026 · **Entegrasyon dalı:** `faz-21-backlog` (`faz-20-critical-fixes` @ `70edfcf`'den)
> **Kaynak:** docs deposu `audits/2026-11-12-full-system-audit/` (düşük 🟢 ve öneri 💡 bulgular) + `audits/2026-11-26-post-faz-19-scan/REPORT.md` §4b
> **Takip tablosu:** docs deposu `phases/faz-21/README.md` §1 · **Kararlar:** `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-21.md`
> **Önceki:** [`FAZ-20.md`](FAZ-20.md) · Tek oturum, onaysız; her madde ayrı commit, commit'leri ajan attı (İsmail'in isteği).

## Fazın amacı

`AUD-2026-11-12` denetiminin ertelenen düşük ve öneri bulgularını ve ikinci taramanın (`AUD-2026-11-26`) açık karar
sorularını kapatmak. Karar isteyen maddelerde İsmail'in "Cevabın" sütunu boş kaldıysa plandaki varsayılan uygulandı
ve K kaydına "varsayılan (İsmail cevaplamadı)" yazıldı.

## 21.0 — Hazırlık (27 Kasım)

| Adım | Görülen |
|---|---|
| Depo durumu | Üç depoda `git status --short` boş |
| Dallar | backend/frontend `faz-21-backlog` (`faz-20-critical-fixes` ucundan), docs `docs/2026-11-27-faz-21-backlog` (plan dalı `docs/2026-11-26-faz-21-plan` ucundan) |
| Ortam | `composer sync`: kurulacak paket yok, "Nothing to migrate"; `migrate:status --pending` "No pending migrations"; `composer install --dry-run` "Nothing to install, update or remove". Frontend'de Vite (`npm run dev`) açıktı, `npm ci` yerine `npm install` koşuldu: "found 0 vulnerabilities" |

### Taban kapıları

| Kapı | Sonuç (görülen çıktı) |
|---|---|
| `composer check` | pint passed · phpstan 0 hata · `errors:export --check`, `plans:export --check`, `env:template --check` temiz · `k18: 32 dosya, 0 ihlal` · phpunit **1306/1306**, 4765 assertion, 121,9 sn · çıkış 0 |
| `composer audit` | "No security vulnerability advisories found." |
| `npm run check` | çıkış 0 · `verify:errors` 31 kod · `verify:i18n` 781 parça, 65 dosya |
| `npm run e2e` | **6/6** (1,9 dk) |
