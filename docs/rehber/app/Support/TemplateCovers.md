# `app/Support/TemplateCovers.php` — Kılavuz

> **Faz:** 19 ortak düzeltme (B-E4, 25 Kasım 2026) · **Karar:** K235 · **Kullanan:** `BuildSharePreviewAction`

## Neden

Galerisi olmayan davetiye WhatsApp/Facebook önizlemesinde genel posteri gösteriyordu: şablon kapakları frontend
derlemesinde hash'li adla çıkıyor, backend adlarını bilemiyordu. Frontend artık derlemede kapakları sabit adla da
yayımlıyor (`dist/covers/<preset>.<uzantı>`) ve bir dizin yazıyor (`dist/covers/index.json`: `{ "<preset>": "/covers/<preset>.jpg" }`;
frontend `scripts/export-covers.ts`).

## Desen

- Dizin yolu `config('covers.index')` ([`config/covers.md`](../../config/covers.md)); istek başına bir kez okunur.
- Dizin yok, bozuk ya da preset yoksa `null` → önizleme genel posterde kalır (yerel geliştirme, ilk kurulum).
- Değer yalnızca `^/covers/<a-z0-9->.(jpg|jpeg|png|webp)$` ise kabul: dizin bir dosya olsa da önizleme HTML'ine
  başka bir alan adı ya da betik yolu sızmaz. SVG kapaklar dizinde yok (OG tarayıcıları SVG göstermez).

## Nasıl denenir

`php artisan test --compact tests/Feature/SharePreviewTest.php` (`an_invitation_without_a_gallery_shows_its_template_cover`).
Sunucuda: `ls /var/www/davetkart/frontend/dist/covers/index.json`, sonra `deploy/smoke/check-share-preview.sh`.
