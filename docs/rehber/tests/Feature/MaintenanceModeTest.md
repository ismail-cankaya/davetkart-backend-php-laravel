# `tests/Feature/MaintenanceModeTest.php`

> **Faz:** 20.4 (`OPS-06`, K264) · sınadığı: bakım kipinde API yanıtı · ilgili: [`../../deploy/deploy.md`](../../deploy/deploy.md) adım 0b

## Ne kanıtlar

- `php artisan down --retry=60` ile aynı yük açıkken `GET /api/ping` **503** ve gövde birebir
  `{"error":{"code":"PROVIDER_UNAVAILABLE"}}`: Laravel'in HTML bakım sayfası değil, frontend'in `toDisplayError`'ının
  tanıdığı K20 zarfı. `--render` ile şablon verilseydi ya da hata işleyici API yolunu tanımasaydı test kırılır.
- Kip kalkınca aynı istek 200: deploy'un son adımı (`artisan up`) siteyi gerçekten açar.
- Mutant: `activate()`'ten hemen sonra `deactivate()` → ilk test 200 görür, kırmızı.

## Kurulum

- Sürücü **`cache` + `array`**: dosya sürücüsü `storage/framework/down` yazar. Test yarıda çökerse dosya kalır ve
  aynı klasörden çalışan yerel sunucu (`php artisan serve`) da bakımda kalırdı. `array` deposu istekle birlikte biter.
- `app.debug = false`: gövdede `debug` alanı olmasın (H3); `assertExactJson` canlıdaki gövdeyi sınar.

## Nasıl denenir

`php artisan test --compact --filter=MaintenanceModeTest` · yerelde: `php artisan down --retry=60` →
`curl -i -H "Accept: application/json" http://127.0.0.1:8000/api/ping` → 503 → `php artisan up`.
