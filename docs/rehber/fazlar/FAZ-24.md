# Faz 24 — v2.0 altyapı: davetiye dili ve değiştirilen şablon metinleri

> **Tarih:** 28 Kasım 2026 · **Dal:** `faz-24-v2.0-editor` (`faz-22-release-prep` @ `5d250b9`'dan) · **Kararlar:** K300–K305,
> K314, K316 (docs `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-24.md`) · **Plan ve takip:** docs `phases/faz-24/`

## Ne kuruldu

| Parça | Dosya | Not |
|---|---|---|
| Kolonlar | `2026_11_28_100000_add_text_customization_to_invitations_table.php` | `locale` (`tr`), `text_overrides` (jsonb, null), `has_custom_texts` (bool) |
| Sözleşme | `contracts/text-slots.json`, `app/Support/TextSlots.php` | Frontend üretir, burada aynen kopya; okuyucu + `text_overrides` kuralı |
| Model | `Invitation::textOverrides()` mutator | `has_custom_texts`'i `fill()` anında yazar; `#[Fillable]`'da yok |
| Doğrulama | `InvitationRequest` | `invitation.locale` (`in:` sözleşme dilleri), `invitation.textOverrides` (`text_overrides`) |
| Paket | `config/davetkart.php` `module_tiers.has_custom_texts = elit`, `contracts/plan-rules.json` | `TierResolver` ve iki Action değişmedi |
| Yanıt | `InvitationPayloadResource`, `PublicInvitationResource` | `locale`, `textOverrides` (boşsa `{}`) |

## Faz 22 ucundan devralınan kırmızılar (24.0)

`ErrorCodeUsageTest` 28 kod bekliyordu (22.1 sonrası 27), `contracts/error-codes.json` `count: 28` diyordu,
`k18:check` dört dosyada 10 uzun yorum buldu. Üçü de düzeltildi; uzun yorumların tam metni kılavuzlarına
"K18 taşıması" başlığıyla taşındı (bilgi kaybı yok).

## Kapılar

`composer check`: **1385/1385** ✅ (PHP 8.5.13, PostgreSQL 16). `composer audit`: koşmadı.

## Elle doğrulama

1. `php artisan migrate` → `invitations`'ta üç yeni kolon; eski satırlar `tr` / `null` / `false`.
2. Elit hakkı olmayan bir davetiyeye `PUT /api/invitations/{id}` ile `{"invitation":{"textOverrides":{"tr":{"rsvp.submit":"Geliyorum"}}}}`
   → 200; `POST …/publish` → 402 `PAYWALL_TIER_INSUFFICIENT`, `requiredTier: elit`.
3. Aynı istekte `"rsvp.submit"` yerine 400 karakter → 422, `fields["invitation.textOverrides"][0].rule = text_overrides`.
4. Misafir ucu `GET /api/public/invitations/{id}` → `locale: "tr"`, `textOverrides`.
