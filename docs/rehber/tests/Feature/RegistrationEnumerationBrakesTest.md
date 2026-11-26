# `tests/Feature/RegistrationEnumerationBrakesTest.php`

> Faz 21.2 · `SEC-15` · K266. Kayıt ucu kayıtlı adresi 422 ile söyler (kabul edilen risk); bu dosya frenleri kilitler.
> Davranış değişmedi: testler bugünkü kodu korur, bu yüzden "önce kırmızı" yok, her biri mutantla sınandı.

| Test | Korunan davranış | Öldürdüğü mutant |
|---|---|---|
| `attempts_on_a_taken_address_fill_the_address_and_ip_bucket` | Kayıtlı adrese 5 deneme (422) → 6. 429; aynı IP'den başka adres 201, aynı adres başka IP'den 422 | kayıt rotası `throttle:auth` dışında · kova adresi taşımaz (yalnızca IP) |
| `scanning_many_addresses_from_one_ip_is_capped` | Tek IP'den 20 yeni adres 201 → 21. 429 | rota `throttle:auth` dışında · IP kovası yok |
| `the_password_is_hashed_even_when_the_address_is_taken` | Kayıtlı adreste `Hash::make` bir kez çağrılır; 422, `fields` yok | "adres var mı?" kısa yolu (hash'siz 422) |

## `tests/Support/CountingHasher.php`

`Hash::swap()` ile yerleşen, gerçek `HashManager`'a devreden ve `make()` çağrılarını sayan küçük sınıf. Neden
`Hash::partialMock()` değil? Facade'ın kısmi taklidi `HashManager`'ı kurucusuz üretir; içindeki konteyner `null` kalır
ve gerçek hash'e geçen her çağrı `Call to a member function get() on null` ile düşer (denendi). Sayaç yalnızca kayıt
yolunun kullandığı metotları taşır: `Hasher` sözleşmesi + `hashed` cast'inin sorduğu `isHashed()`.
