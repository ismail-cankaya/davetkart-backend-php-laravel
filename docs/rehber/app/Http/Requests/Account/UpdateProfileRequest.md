# `app/Http/Requests/Account/UpdateProfileRequest.php`

> **Faz:** 14 (14.7) · **Ana kılavuz:** `UpdateProfileAction.md` · **Test:** `tests/Feature/ProfileUpdateTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

`PATCH /api/auth/me` gövdesi: ad ve soyad.

## Neden böyle

🔴 E-posta bu uçtan değişmez: gövdede gelse bile kurallarda yok, `validated()`'a girmez. E-posta hesabın kimliği; değişmesi doğrulama ister.

## Kurallar

- Kurallar kayıtla aynı (`max:60`): iki yerde iki farklı uzunluk olmasın.
- Doğrulamadan önce kırpılır: `"  "` boş sayılır ve `required`'a takılır.
- camelCase giriş → snake_case çıkış (`profile()`).

## Sık hata

| Hata | Sonuç |
|---|---|
| `$request->all()` ile güncellemek | Gövdedeki `email` sessizce yazılır |
| Kırpmadan doğrulamak | Yalnızca boşluktan oluşan ad kabul edilir |
