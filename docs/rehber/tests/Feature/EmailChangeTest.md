# `tests/Feature/EmailChangeTest.php`

> **Faz:** 14, adım 14.10

| Test | Kanıtladığı |
|---|---|
| `a_guest_cannot_request_a_change` | 401 |
| `the_request_mails_the_new_address_and_changes_nothing_yet` | Adres değişmez; bağlantı frontend'e gider, eski adresi taşımaz, normalize edilir |
| `a_wrong_password_sends_nothing` | Parola onayı |
| `the_current_address_is_refused` | `not_in` (büyük/küçük harf farkı dahil) |
| `confirming_changes_the_address_and_closes_every_session` | Adres, doğrulama damgası, token'lar, sıfırlama satırı, eski adrese mail, yeni adresle giriş |
| `a_link_cannot_be_used_twice` | Tek kullanımlık (`old` parmak izi) |
| `a_taken_address_is_never_revealed` | 🔴 H6: istek 202, onay genel hata |
| `a_tampered_link_is_refused` · `an_expired_link_is_refused` · `a_link_without_parameters_gets_the_same_error` | İmza, süre, biçim — hepsi aynı kod, `params` yok |

`confirm()` yardımcısı frontend'in yaptığını taklit ediyor: sayfa adresinin sorgusunu API
yoluna ekleyip POST ediyor. İmzanın göreli olduğu böylece uçtan uca sınanıyor.
