# `app/Console/Commands/CheckEnvTemplate.php` — `php artisan env:template`

> **Faz:** 19.6 (`OPS-04`, K184) · **Test:** `tests/Feature/ProductionConfigTest.php` · **Zincir:** `composer check`

## Neden

Üretim `.env`'i sunucuda elle kuruldu (Y8.3b) ve üç düzeltme (`APP_URL`, `AWS_URL`, `MAIL_MAILER`)
şablona (`docs/10-URETIM-ENV-SABLONU.md`) geri işlenmedi. Sunucu "yok edilebilir"; yeniden kurulumda
`.env` şablondan üretilir ve aynı üç hata geri gelir. Şablon altyapının kod hâlidir: kod ile arasındaki
fark derleme gibi kırmızı yanmalı.

## Desen

- `config/*.php` içindeki `env('ANAHTAR'` çağrıları ↔ şablonun ` ```dotenv ` bloğundaki `ANAHTAR=` satırları.
  Yorumdaki örnekler (`#   MAIL_MAILER=smtp`) sayılmaz.
- **Eksik** (config okuyor, şablonda yok) ve **fazla** (şablonda var, config okumuyor) ikisi de hata.
- Bilinçli dışlamalar komutta açık liste: `IGNORED_PREFIXES` (kullanılmayan sürücüler: Redis, SQS,
  Memcached, Postmark, Sentry iz/kırıntı ayarları…) ve `IGNORED_KEYS` (yalnızca yerel/test:
  `DAVETKART_TEST_DB_SUFFIX`; varsayılanı üretimde doğru olanlar; yalnızca SMTP yolundakiler).
- `--check` farkta çıkış 1 (`plans:export --check` deseni); `--template=` testler için.

## Hangi hataya karşı

- Yeni bir `env()` okuması eklenir, şablona yazılmaz (`AWS_URL` tam olarak buydu).
- Config'ten bir okuma kalkar, şablonda ölü satır kalır (`BROADCAST_CONNECTION`: `config/broadcasting.php` yok).

## Sık hata

- Yeni anahtarı dışlama listesine "susturmak için" eklemek. Soru şu: üretimde varsayılan doğru mu?
  Değilse şablona yaz.
- `env()`'i config dışında çağırmak: komut onu görmez, `config:cache` sonrası da `null` döner.

## Nasıl denenir

```bash
php artisan env:template            # farkları listeler, çıkış 0
php artisan env:template --check    # farkta çıkış 1
php artisan test --compact --filter=ProductionConfigTest
```

Mutant: şablondan `AWS_URL` satırını sil → komut `missing in template: AWS_URL`, çıkış 1; test kırmızı.

## Faz 19 ortak düzeltme (A-E7) — sunucudaki `.env` ile karşılaştırma

OPS-04 şablonu yalnızca `config/`'e göre eşitledi; sunucudaki gerçek anahtar listesi hiç görülmedi. Şablonda olmayan bir anahtar
yeniden kurulumda kaybolur. Sunucuda:

```bash
php artisan env:template --env=.env           # farkları listeler
php artisan env:template --env=.env --check   # fark varsa çıkış 1
```

- `in .env, not in template: X` → şablona ekle (ya da sunucudan kaldır). `in template, missing in .env: X` → sunucuda eksik.
- Yalnızca satır başındaki `ANAHTAR=` (isteğe bağlı `export`) eşlenir; **değer hiçbir çıktıya girmez** (test: değer metni çıktıda aranır).
- `config/` karşılaştırması (`--env` olmadan) değişmedi.
