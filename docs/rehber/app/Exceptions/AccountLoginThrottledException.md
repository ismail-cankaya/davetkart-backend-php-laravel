# `app/Exceptions/AccountLoginThrottledException.php`

> Faz 21.1 · `SEC-16` · K265.

Hesabın başarısız giriş kovası doluyken `AccountLoginThrottle::ensureOpen()` fırlatır.

- `errorCode()`: `RATE_LIMITED` (429). Yeni hata kodu **yok**: istemci için IP kovasının 429'uyla aynı yanıt. Ayrı bir kod
  ("hesabınız yavaşlatıldı") hem frontend'e yeni bir dal açardı hem de saldırgana hangi kovaya takıldığını söylerdi.
- `errorParams()`: `retryAfter` (saniye, en az 1). `ErrorCode::RateLimited` bu parametreyi beyaz listede tutuyor;
  `ApiExceptionRenderer` aynı değerden `Retry-After` başlığını üretir (08 §4.1).
- Özellik adı `$retryAfterSeconds`: `Exception`'ın kendi `$code`/`$message`'ını gölgelememek için (ders 55).
- `max(1, …)`: `RateLimiter::availableIn()` pencerenin tam sonunda 0 dönebilir; "0 saniye sonra dene" anlamsız.

Benzerleri: `MediaStorageUnavailableException` ([`MediaDiskGuard.md`](../Support/MediaDiskGuard.md)) (503 + `retryAfter`),
[`AssistantQuotaExceededException.md`](AssistantQuotaExceededException.md) (429, ama kota: ayrı kod).
