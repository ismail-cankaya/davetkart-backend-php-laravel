# `tests/Feature/RequestIdTest.php`

> Faz 21.4 · `OPS-08` · K268.

| Test | Korunan davranış | Öldürdüğü mutant |
|---|---|---|
| `every_response_carries_a_fresh_request_id` | `/api/ping` (200), bilinmeyen uç (404), `/up`: üçünde de ULID, üçü farklı | ara katman kayıtlı değil |
| `an_early_response_from_the_global_stack_carries_it_too` | `ValidatePostSize`'ın 413'ü de başlık taşır | zincirin sonuna eklenir (`append`) |
| `the_error_envelope_does_not_grow` | 404 gövdesi tam olarak `{error:{code}}` (§A-1, yokluk) | — (zarfa alan ekleyen değişiklik) |
| `a_well_formed_incoming_id_is_kept` (ULID, UUID) | Geçerli gelen kimlik aynen döner | gelen kimlik hiç kabul edilmez · UUID kabul edilmez |
| `a_malformed_incoming_id_is_replaced` (7 vaka) | Serbest metin, boş, 65 karakter, UUID + kuyruk, HTML, nginx 32 hex, taşan ULID → yeni ULID | her kimlik kabul |
| `log_lines_written_during_the_request_carry_the_id` | Test rotasında yazılan satırın `extra.request_id`'si = başlık | Context'e yazılmaz |
| `a_queued_job_carries_the_request_id` | Kayıt sırasında kuyruğa giren doğrulama postasının yükünde `illuminate:log:context.data.request_id` | Context'e yazılmaz |

Günlük testi `monolog` sürücülü geçici bir kanal (`TestHandler`) ve testte tanımlanan bir rota kullanır; kuyruk testi
`queue.default = database` yapar ve `jobs.payload`'ı okur (Context değeri `serialize` edilmiş durur).

Eşdeğer mutant: uzunluk sınırını (≤64) kaldırmak hiçbir testi kırmadı, çünkü ULID/UUID biçimi uzunluğu zaten sınırlar.
Kod bu yüzden ayrı bir uzunluk kontrolü taşımıyor.
