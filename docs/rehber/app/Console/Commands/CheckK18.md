# `app/Console/Commands/CheckK18.php` — `php artisan k18:check`

> **Faz:** 19.28 (`ARC-03`, K259) · **Ölçen sınıf:** [`app/Support/K18Inspector.md`](../../Support/K18Inspector.md) ·
> **Test:** `tests/Feature/K18CheckTest.php` · **Zincir:** `composer check` (`env:template --check`'ten sonra)

## Neden

K18 der ki: kod yorumu kısa (1–3 satır), ayrıntı `docs/rehber/<aynı-yol>.md`'de. Denetim (`AUD-2026-11-12`, `ARC-03`)
ölçtü: üretim kodunun 287 dosyasının 265'inde 3 satırdan uzun yorum bloğu, 362 kod dosyasının 79'unun kılavuzu yok.
Kural doğru; sorun ölçülmemesiydi. Bütün kod tabanını bir günde temizlemek yerine **cırcır** (ratchet) kuruldu:
mevcut ihlaller kalır, ama **dokunulan** dosya temiz olmak zorunda. İhlal sayısı yalnızca azalabilir.

## Ne yapar

1. Taban referansını bulur: `--base=<ref>`, yoksa `CheckK18::BASELINE` (19.90 sonrası `faz-19-audit-fixes` ucu,
   `0743874`). `git merge-base <taban> HEAD` alınır; böylece taban dalı ilerlese de yalnızca **bu dalın** değişiklikleri sayılır.
2. Değişen dosyalar: `git diff --name-only --diff-filter=ACMR <merge-base>` (commit'lenmemiş değişiklikler dahil) +
   izlenmeyen yeni dosyalar (`git ls-files --others --exclude-standard`). Silinen dosya sayılmaz.
3. Yalnızca `app/`, `routes/`, `config/`, `database/` altındaki `.php` dosyaları `K18Inspector`'a gider.
4. Her ihlal bir satır (`dosya: sorun`), sonda `k18: N dosya, M ihlal`. İhlal varsa çıkış 1.

`paths` argümanı verilirse git kullanılmaz, yalnızca o dosyalar ölçülür (testler ve elle deneme için):
`php artisan k18:check routes/api.php`.

## Taban CI'da nasıl verilir

- CI her dalda `composer check` koşar; varsayılan taban (`BASELINE`) yeterli. Ama taban commit'i klonda **bulunmalı**:
  `.github/workflows/ci.yml`'de `actions/checkout` `fetch-depth: 0` ile tam geçmişi çeker. Sığ klonda
  `merge-base` başarısız olur ve komut **kapalı başarısız** olur (çıkış 1, "taban bulunamadı"): kapı sessizce yeşil yanmaz.
- Bir PR'da yalnızca PR'ın kendi değişikliklerini ölçmek istersen: `php artisan k18:check --base=origin/main`.
- Taban ileride ilerletilecekse (ör. büyük bir temizlikten sonra) `BASELINE` değişir; bu yeni bir karar değil,
  K259'un uygulamasıdır, ama faz kaydına yazılır.

## Sık hata

| Hata | Sonuç | Doğrusu |
|---|---|---|
| Küçük bir düzeltme için dokunulan eski dosyada kırmızı yanması "haksız" sayılıp dosyayı `paths` dışı bırakmak | Cırcır işlemez | O dosyanın uzun bloklarını kılavuzuna taşı; davranış değişmez |
| Uzun açıklamayı 3 satırlık parçalara boş satırla bölmek | Kural kâğıt üstünde geçer, amaç ölür | Ayrıntı kılavuza; kodda "neden" + kılavuz yolu |
| CI'da `fetch-depth: 0`'ı kaldırmak | Her koşu "taban bulunamadı" ile kırmızı | Tam geçmiş kalır |
| Yeni dosyanın kılavuzunu "sonra" yazmak | `kilavuz yok: docs/rehber/...` | Kılavuz aynı commit'te |

## Nasıl denenir

```bash
php artisan k18:check                          # dalın değiştirdiği dosyalar
php artisan k18:check routes/api.php           # tek dosya
php artisan k18:check --base=origin/main       # PR görünümü
php artisan test --compact --filter=K18CheckTest
```

Mutantlar (19.28'de denendi, ikisi de kırmızı): `K18Inspector::close()`'da `>` → `>=` (3 satırlık blok ihlal
sayılır); kılavuz denetimini kaldır (`kilavuz yok` satırı kaybolur).

## Frontend için öneri (D4)

Aynı cırcır frontend'e `npm run verify:k18` olarak kurulabilir (`src/**/*.ts(x)`, `//` ve `/* */` blokları, JSDoc
tip etiketleri hariç). Bu bulguda frontend'e dokunulmadı; karar İsmail'in.

## Terimler

- **Cırcır (ratchet):** geri gitmeyen dişli. Burada: ihlal sayısı yalnızca azalır, çünkü dokunulan her dosya temizlenir.
- **merge-base:** iki dalın ortak son atası; "bu dal neyi değiştirdi" sorusunun tabanı.
- **Kapalı başarısızlık (fail-closed):** emin olamadığında "geçti" değil "kaldı" demek.
