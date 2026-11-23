# `SharePreviewController` · `resources/views/share-preview.blade.php` · `routes/web.php`

> **Faz:** 19.23 — `FE-04` · **Karar:** K230

`GET /invite/{id}` (`whereUlid`): `BuildSharePreviewAction` → Blade. Her değer `{{ }}` ile kaçışlı (adlar kullanıcı
girdisi), betik yok, `robots noindex`. `Cache-Control: public, max-age=300` (`share_preview.max_age`) + `Vary: User-Agent`.
Rota `routes/web.php`'de ama **`web` grubu yok** (`bootstrap/app.php` `then:`): Session/CSRF/çerez gereksiz; global
`SecurityHeaders` (CSP `default-src 'none'`) yeter. Nginx yalnızca botları buraya yollar (`deploy/nginx/davetkart.md`).
Testler: `tests/Feature/SharePreviewTest.php` (5).
