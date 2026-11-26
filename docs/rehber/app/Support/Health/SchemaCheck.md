# `app/Support/Health/SchemaCheck.php` — `schema`

> **Faz:** 20.1 (`OPS-10`, K263) · **Genel bakış:** [`ReadinessProbe.md`](ReadinessProbe.md) · **Test:** `tests/Feature/ReadinessTest.php`

## Ne ölçer

Göç (migration) dosyalarının hepsi `migrations` tablosunda kayıtlı mı. Kayıtlı olmayan tek bir dosya varsa kontrol
başarısız: kod, veritabanının bilmediği bir sütunu okuyabilir. `migrations` tablosu hiç yoksa da başarısız.

Dosya listesi `php artisan migrate:status` ile aynı yerlerden gelir: `database/migrations` + paketlerin
`loadMigrationsFrom()` ile kaydettiği yollar (`Migrator::paths()`).

## Hangi hataya karşı

26 Kasım 2026 (`AUD-2026-11-26`, `OPS-10`): yerel veritabanı 26 Ekim'den beri göç almamıştı. Kod
`orders.first_published_at`'i okuyunca katı kip (`Model::shouldBeStrict`) `MissingAttributeException` fırlattı;
Hesap Ayarları'nın üç sayfası "Bir aksaklık oldu" dedi. `/api/ping` ve `/api/health` ise 200 diyordu: bağlantı
çalışıyordu, eksik olan **şemanın sürümüydü**.

Canlıda aynı durum iki yoldan doğar: `deploy.sh` içinde `migrate --force` yarıda kalır (ör. `2026_11_16_100000`
göçü çakışan veride bilerek durur) ya da göç adımı atlanır. Kod `git pull` ile zaten yerindedir.

🔴 **Canlıda belirti daha kötü:** katı kip yalnızca canlı dışında açık (`AppServiceProvider`,
`shouldBeStrict(! isProduction())`). Canlıda eksik sütun hata vermez, **`null` okunur**: `Order::hasPublished()`
`false` döner ve yayın yapmış hak iade edilebilir görünür (SEC-02 savunması sessizce kapanır). Yazan yollar
(yayın damgası, `buyer_phone`) ise SQL hatasıyla 500 verir. Bu kontrol dış izleyiciye (`IZLEME.md`) "kod şemanın
önünde" alarmını verir; sessiz yolu ancak böyle görürüz.

## Neden `DatabaseCheck`'in içinde değil

`DatabaseCheck` bilerek yalnızca `select 1` çalıştırır: "bağlantı var mı". Bu soru farklı (sürüm uyumu) ve farklı
eylem ister (`php artisan migrate`). Ayrı ad, alarmda ayrı anlam. Veritabanına ulaşılamıyorsa iki kontrol birden
başarısız olur (`["database","schema"]`); kök neden `database`'tir.

## Maliyet

Her çağrıda bir dizin taraması (~100 dosya adı, içerik okunmaz) ve bir sorgu (`select migration from migrations`).
Uç `throttle:60,1` ile sınırlı; izleyici dakikada bir vurur.

## Sık hata

| Hata | Neden yanlış |
|---|---|
| Yalnızca `database/migrations`'a bakmak | Paketin kendi göçü (`loadMigrationsFrom`) bekliyorsa görülmez; `migrate:status` görür |
| "Fazla" kayıt (tabloda olup dosyası olmayan) için de başarısız olmak | Eski dal göçü ya da silinmiş dosya kodu bozmaz; tek tehlike eksik göç |
| Hata metnine göç adını yazmak | Yanıt yalnızca adı taşır (`the_health_response_leaks_no_details`) |

## Nasıl denenir

`readiness_fails_when_a_migration_has_not_run` son göçün kaydını (transaction içinde) siler ve `failed: ["schema"]`
bekler. Yerelde: `php artisan migrate:rollback --step=1` → `curl -i http://localhost:8000/api/health` → 503,
`schema` listede → `php artisan migrate` → `schema` listeden çıkar.

## Terimler

- **Göç (migration):** şemayı değiştiren, sırayla bir kez çalışan dosya; çalıştığı `migrations` tablosuna yazılır.
- **Katı kip (strict mode):** Eloquent'in yüklenmemiş sütunu okumayı `null` yerine istisnaya çevirmesi.
