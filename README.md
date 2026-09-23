# DavetKart — Backend (PHP · Laravel)

Dijital davetiye SaaS'ının API'si. Kullanıcı davetiye tasarlar, plan satın alır,
yayınlar; misafirler paylaşılan linkten davetiyeyi görür, LCV bırakır ve
fotoğraf/video ekler. Frontend ayrı depoda (`davetkart-frontent`, React 19 + TS).

> Bu dosya Laravel'in varsayılan README'sinin yerine 23 Eylül 2026'da yazıldı.

## Yığın

| | |
|---|---|
| Dil / framework | **PHP ^8.5** · **Laravel 13** · Sanctum 4 |
| Veritabanı | **PostgreSQL 18** — geliştirme, test ve üretimde aynı motor (K19) |
| Mimari | Modüler monolit + **Action tabanlı** katman — Repository/Fat Service **yok** |
| Kalite | Pint · Larastan (level 8) · `errors:export --check` · PHPUnit → `composer check` |
| Hata izleme | Sentry (DSN boşsa kapalı) |
| CI | GitHub Actions — `composer check` + `composer audit` |

## Hızlı başlangıç (Windows + Laravel Herd)

```powershell
composer install
copy .env.example .env          # DB_PASSWORD'ü doldur
php artisan key:generate

# pgAdmin'de iki veritabanı: davetkart ve davetkart_test
php artisan migrate
php artisan storage:link        # onsuz her medya URL'i 404

php artisan serve               # http://127.0.0.1:8000
php artisan queue:work          # görsel optimizasyonu ve gecikmeli silme
```

Frontend (`npm run dev`, port 3000) `/api` ve `/storage` isteklerini Vite proxy'si
ile 8000'e yollar; yerelde CORS devreye girmez.

## Komutlar

| Komut | Ne yapar |
|---|---|
| `composer check` | Faz bitiş kapısı: pint → phpstan → errors:export → testler (fail-fast, **son satıra bak**) |
| `composer lint` | Kod stilini düzeltir (`check` yalnızca bakar) |
| `php artisan errors:export` | `contracts/error-codes.json`'u üretir (frontend'e tek yönlü kopyalanır) |
| `php artisan orders:expire --dry-run` | Süresi dolmuş bekleyen siparişler |
| `php artisan media:prune-orphans --dry-run` | LCV'ye bağlanmamış misafir yüklemeleri |
| `php artisan schedule:list` | Zamanlanmış 3 bakım işi |

## Sözleşme (kırılırsa frontend kırılır)

- Rotalar `/api/...` — **`/api/v1/...` değil**; sürüm controller namespace'inde
- Auth yanıtı zarfsız `{user, token}`; diğer her şey `{data: ...}`
- Hata: `{error: {code, fields?, params?}}` — **metin yok** (`docs/08`)
- Auth gerektirmeyen her rota `/api/public/` altında
- Sahiplik yoksa **404**; `id`'ler string (ULID)

## Dokümanlar

| Nereden başla | |
|---|---|
| `CLAUDE.md` | Bağlayıcı kod standartları |
| `claude/GOZDEN-GECIRME-RAPORU.md` | 🔴 23 Eylül 2026 tam gözden geçirme — açık bulgular |
| `claude/FAZ-9-DEVIR.md` | Devir dosyası (yeni geliştirici / asistan) |
| `docs/11-PROJE-TARIHCESI-VE-IS-AKISLARI.md` | Uçtan uca giriş: mimari, iş akışları, 22 uçluk harita |
| `docs/08-HATA-SOZLESMESI.md` | Hata sözleşmesi |
| `docs/10-URETIM-ENV-SABLONU.md` | Üretim `.env` şablonu ve kurulum sırası |
| `docs/rehber/` | Her kod dosyasının eğitim kılavuzu (yol birebir: `app/X.php` → `docs/rehber/app/X.md`) |
| `docs/rehber/fazlar/` | Faz özetleri ve elle doğrulama betikleri |
