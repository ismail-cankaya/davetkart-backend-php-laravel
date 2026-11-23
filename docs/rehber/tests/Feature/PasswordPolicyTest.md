# `tests/Feature/PasswordPolicyTest.php`

> **Faz:** 19.9 (`SEC-05`) · **Karar:** K258 · **Kod:** [`app/Rules/PasswordPolicy.md`](../../app/Rules/PasswordPolicy.md)

## Ne sınar

| Test | Ne |
|---|---|
| `registration_rejects_common_passwords` | Kayıt: veri sağlayıcıdaki 5 parola → 422, `fields.password.0.rule = uncommon_password`, kullanıcı yok |
| `password_reset_rejects_common_passwords` | Aynı sağlayıcı, geçerli sıfırlama bağlantısıyla |
| `password_change_rejects_common_passwords` | Aynı sağlayıcı, doğru mevcut parolayla |
| `a_long_uncommon_passphrase_is_accepted` | Regresyon: üç uçta da `mor-bisiklet-kahve-2027` geçer |
| `the_minimum_length_is_eight` | 7 karakter `min` (`params.min = 8`), 8 karakter geçer |

Sağlayıcı: `12345678`, `password`, `qwerty123456`, `QWERTY123456` (büyük harf), `aaaaaaaaaaaa` (tekrar).
Hepsi 8+ karakter: `min` geçer, ilk başarısız kural `uncommon_password` olur.

## Neden üç uç ayrı ayrı

Bulgunun dersi: kural üç yerde yazılıyordu. Sağlayıcı üç ucun her birinde koşar; bir istek ortak kuralı
bırakırsa yalnızca o ucun testleri kırmızı olur.

## Mutantlar (19.9'da koşuldu)

- `rules()`'tan `uncommon_password` çıkarıldı → 15 kırmızı.
- `ChangePasswordRequest` eski `'min:8', 'max:255'`'e döndü → 5 kırmızı (yalnızca değiştirme).
