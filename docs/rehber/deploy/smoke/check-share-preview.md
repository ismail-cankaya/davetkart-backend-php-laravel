# `deploy/smoke/check-share-preview.sh`

> **Faz:** 19.23 — `FE-04` · **Karar:** K230

`bash deploy/smoke/check-share-preview.sh [-k] https://davetkart.com <yayındaki-davetiye-id>`

| İstek | Beklenen |
|---|---|
| `/invite/<id>`, UA `WhatsApp/2.23.20.0` | `og:title` var, `id="root"` yok (Laravel OG sayfası) |
| `/invite/<id>`, tarayıcı UA'sı | `id="root"` (SPA) |
| `/invite/0000…` (olmayan), UA `TelegramBot` | genel önizleme ("DavetKart — Dijital Davetiye") |

Yerelde Herd Nginx ile: `-k https://davetkart.test`. Faz 19.23'te **koşulmadı** (kayıt).
