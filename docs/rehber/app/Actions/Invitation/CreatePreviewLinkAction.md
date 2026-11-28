# `app/Actions/Invitation/CreatePreviewLinkAction.php`

Faz 29.4 (K328). "Telefonda aç": `URL::temporarySignedRoute('public.invitations.preview', +ttl, absolute: false)`.

- **Göreli imza:** imza yol + sorgu üzerinden; alan adı ve şema içermez. Frontend ve API aynı alan adında olmasa da
  (yerelde vekil, üretimde nginx) doğrulanır. Rota `signed:relative` ile korunur.
- Dönen adres frontend'in: `{frontend.url}{preview_link.path}/{id}?expires=…&signature=…`. Frontend sayfası aynı
  sorgu dizesiyle `GET /api/public/invitations/{id}/preview`'u çağırır; imzalanan yol odur.
- Ömür `config('davetkart.preview_link.ttl_minutes')` (1440 = 24 saat). Bağlantı bir sır gibidir: taşıyan herkes taslağı
  görür; süre kısa, arama motoruna kapalı.
