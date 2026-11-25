# `config/covers.php` — Kılavuz

> **Faz:** 19 ortak düzeltme (B-E4) · **Karar:** K235

| Anahtar | Değer | Neden |
|---|---|---|
| `index` | `base_path('../frontend/dist/covers/index.json')` | Üretim yerleşimi (`deploy/nginx/davetkart.conf`): `/var/www/davetkart/backend` ve `/var/www/davetkart/frontend/dist` kardeş klasör. Ortam değişkeni yok: yerleşim sabit; yerelde dosya bulunmaz ve önizleme genel postere düşer (zararsız). |

Neden `config/davetkart.php` değil: o dosyaya dokunmak K18 cırcırını bütün dosyanın eski uzun yorumlarına açar.
Ayrıntı: [`app/Support/TemplateCovers.md`](../app/Support/TemplateCovers.md).
