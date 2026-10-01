# `app/Http/Requests/Auth/DeleteAccountRequest.php`

> **Faz:** 10 — Dilim D, adım 10.39 · **Uç:** `DELETE /api/auth/me`
> **Eylem:** [`DeleteAccountAction.md`](../../../Actions/Auth/DeleteAccountAction.md)

## 1. Neden parola? Token yetmez mi?

Token **kimin** istek attığını söyler, **kimin klavyede olduğunu** söylemez:

| Durum | Token geçerli mi? | Hesap sahibi mi? |
|---|---|---|
| Kullanıcı kendi telefonunda | ✅ | ✅ |
| Paylaşılan bilgisayarda açık unutulmuş oturum | ✅ | ❌ |
| Çalınmış token (sızan bir tarayıcı eklentisi) | ✅ | ❌ |

Geri alınamaz bir işlem için token yetmez. Parolayı bilmek, sahibin **o anda**
orada olduğunun kanıtı.

## 2. `current_password:sanctum`

```php
'password' => ['required', 'string', 'current_password:sanctum'],
```

Laravel'in yerleşik kuralı: verilen guard'ın (`sanctum`) **o anki** kullanıcısının
parolasıyla karşılaştırır (`Hash::check`). Kural adı sözleşmeye girer (D6): yanlış
parola → 422 `VALIDATION_FAILED`, `fields.password.0.rule = current_password`.

**H6 burada neden geçerli değil?** H6 *"kimlik bilgisi hatasında hangi alanın yanlış
olduğunu söyleme"* der, çünkü kimliği doğrulanmamış biri hesap tarayabilir. Burada
kimlik zaten doğrulanmış; *"parolanız yanlış"* demek kimseye yeni bir bilgi vermiyor.

## 3. Doğrulama sınıfta, eylemde değil

CLAUDE.md §1: Action'lar doğrulama yapmaz, gelen veri güvenilir kabul edilir. Parola
kontrolü bir doğrulama kuralı, bu yüzden burada. `DeleteAccountAction` parolayı hiç
görmüyor, yalnızca `User`'ı alıyor.

## 4. Hız sınırı

Rota `throttle:auth` altında. Çalınmış bir token'la parola tahmin edilmesin diye.
Mutasyonla kanıtlandı: sınır kaldırılınca 6. yanlış deneme 422 alıyor, 429 değil.
