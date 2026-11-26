# `tests/Feature/AccountLoginThrottleTest.php`

> Faz 21.1 · `SEC-16` · K265. Hesap başına başarısız giriş kovasının kanıtı.

## Düzen

`login()` yardımcısı her isteği **yeni bir IP'den** yapar (`withServerVariables(['REMOTE_ADDR' => '10.21.x.y'])`). Böylece
`throttle:auth`'un iki IP'li kovası hiç dolmaz; görülen her 429 yalnızca hesap kovasından gelebilir. Sınır (`LIMIT = 30`)
bilerek sabit yazıldı: değişirse test kırılmalı (`RateLimitTest` deseni).

## Testler ve öldürdükleri mutantlar

| Test | Korunan davranış | Öldürdüğü mutant |
|---|---|---|
| `login_attempts_from_many_ips_share_an_account_bucket` | 30 IP'den 30 başarısızlık → 31. 429, `Retry-After`, 0 < `retryAfter` ≤ 3600 | denetim yok · sınır 31 |
| `a_full_account_bucket_refuses_even_the_right_password` | Dolu kovada doğru parola da 429 | denetim yok |
| `the_bucket_belongs_to_one_account_only` | Komşu hesap etkilenmez (T6: yokluk) | anahtar e-postadan bağımsız |
| `successful_logins_are_not_counted` | 30 başarılı giriş sonrası ilk hatalı deneme 401 | başarılı giriş de sayılır |
| `an_unknown_address_fills_its_bucket_the_same_way` | Kayıtsız adreste de 429: sayım açığı yok | yalnızca kayıtlı hesapta sayılır |
| `both_forms_of_the_turkish_capital_i_fill_one_bucket` | `İsmail@` ve `ismail@` tek kova (HTTP yolu) | denetim yok |
| `the_throttle_normalizes_the_address_itself` | Sınıf normalizasyonu çağırana bırakmaz | anahtarda normalizasyon yok |
| `the_bucket_empties_after_an_hour` | 61 dk sonra doğru parola 200 | pencere iki saat |
| `a_password_reset_empties_the_bucket` | Sıfırlama kovayı boşaltır: sahibin yolu | sıfırlama kovayı boşaltmaz |
| `the_owner_is_warned_once_when_the_bucket_fills` | 29'da posta yok, 30'da bir, sonraki 429'larda yok | uyarı bir deneme erken |
| `at_most_one_warning_per_hour` | Aynı saatte ikinci doluş postasız; 61 dk sonra ikinci posta | uyarı kovası yok |
| `the_warning_is_queued_turkish_and_carries_no_ip` | `ShouldQueue`, `tr`, sıfırlama sayfası bağlantısı, metinde IP yok | posta kuyruksuz |

`travel(61)->minutes()` çalışır, çünkü testte önbellek `array` sürücüsü ve `RateLimiter` zamanı `Carbon`'dan okur.
