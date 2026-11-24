# `app/Http/Requests/Account/RequestEmailChangeRequest.php`

> **Faz:** 14 (14.10) · **Ana kılavuz:** `RequestEmailChangeAction.md` · **Test:** `tests/Feature/EmailChangeTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

`POST /api/auth/email-change` gövdesi: yeni adres + mevcut parola.

## Neden böyle

🔴 `unique:users` kuralı **bilerek yok** (RegisterRequest ile aynı gerekçe): "bu adres kullanımda" demek hesap tarayıcısı açardı. Çakışma onay anında, veritabanının UNIQUE kısıtında yakalanır.

## Kurallar

- `not_in` kullanıcının kendi adresi için: sızdıracak bir şey yok.
- Adres doğrulamadan önce kayıt/girişle aynı biçimde normalize edilir (`EmailNormalizer`, Faz 10.13): `İ` iki hesap açmasın.
- Mevcut parola zorunlu (`current_password:sanctum`); uç `throttle:auth`.

## Sık hata

| Hata | Sonuç |
|---|---|
| `unique:users` eklemek | Kayıtlı adresler tek tek sorgulanabilir |
| Normalizasyonu atlamak | `İsmail@` ile `ismail@` iki ayrı hesap olur |
