# FAZ 15 — Yeni şablon kimlikleri (konsept + "Yedi Davetiye")

> **Tarih:** 4 Kasım 2026 · **Dal:** `faz15-desing/claude-davetiye`
> **Durum:** ⚠️ kod ✅ · `composer check` **koşmadı** (aşağıda neden) · elle doğrulama bekliyor
> **Önceki:** [`FAZ-14.md`](FAZ-14.md) · **Frontend eşi:** `davetkart-frontent` → dal `design/claude-davetiye`
> (rehber: `docs/rehber/src/components/templates/yedi-davetiye.md`)

---

## 1. Ne oldu

Frontend 25 yeni şablon kimliği ekledi: 18 konsept şablonu (Kitap · Harita · Sergi; düğün, nişan,
doğum günü) ve 7 yeni düğün şablonu (`dugun-kitap-sayfa`, `dugun-gul-3d`, `dugun-popup`,
`dugun-murekkep`, `dugun-fener`, `dugun-holografik`, `dugun-altin-toz`).

Backend'de **kod değişikliği gerekmedi**:

- Şablon listesi backend'de tutulmuyor. `InvitationRequest` `invitation.imageTheme` için yalnızca
  `string|max:48` ister (3.2 §4: geçerli değer listesi frontend'in malı). En uzun yeni kimlik
  `dogum-gunu-harita-kutlama-hatti` = 31 karakter.
- Seeder yok; izin listesi yok.
- Plan: `config/davetkart.php` → `preset_tiers` yalnızca **videolu** 13 temayı Gold'a bağlar (K102,
  İsmail'in kararı, 1 Ekim 2026). Yeni 25 şablonun hiçbirinde video yok → kurala göre hepsi
  **Standart'ta** yayınlanır. Liste değiştirilmedi.

## 2. Ne eklendi

| Dosya | Ne |
|---|---|
| `tests/Feature/TemplateCatalogueTest.php` | 25 kimlik için: kaydedilir ve aynen geri okunur (sahip ucu + veritabanı), misafir ucunda görünür, `TierResolver` Standart ister; hiçbiri `preset_tiers`'te değil. Kılavuz: [`../tests/Feature/TemplateCatalogueTest.md`](../tests/Feature/TemplateCatalogueTest.md) |
| `docs/rehber/fazlar/FAZ-15.md` | bu dosya |

## 3. 🔴 Doğrulama durumu

Bu dalın testi **koşmadı**. Bulut çalışma alanında PHP 8.3 vardı; proje ^8.5 ister ve Laravel 13 /
Symfony 8 kodu 8.3'te ayrıştırılamıyor (`composer install` → `package:discover` sözdizimi hatası).
Ayrıca `phpstan/phpstan` yalnızca zip dağıtımıyla geliyor ve ortamın ağı GitHub zip indirmelerini
reddetti. Yapılan: `php -l` (sözdizimi temiz) ve testin her varsayımının koddan okunması
(`InvitationFactory` varsayılanları, `InvitationPayloadResource`/`PublicInvitationResource` alan
adları, rota adları, `TierResolver`).

**Kapanış için:** PHP 8.5 olan makinede `composer check` (ya da en az
`php artisan test --filter=TemplateCatalogueTest`).

## 4. Karar bekleyenler

- **Yeni şablonlar premium mu?** 3B şablonlar (`dugun-gul-3d`, `dugun-fener`) ve shader'lı
  `dugun-murekkep` "premium" hissi veriyor ama mevcut kural yalnızca videoya bakıyor. Gold'a
  bağlanacaklarsa: `config/davetkart.php` → `preset_tiers`, `PaywallTest::the_premium_themes_are_the_thirteen_video_themes`,
  bu fazın `no_new_preset_is_listed_as_premium` testi ve frontend `src/data.ts`'teki `minimumTier`
  birlikte değişmeli.
