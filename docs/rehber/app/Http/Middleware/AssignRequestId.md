# `app/Http/Middleware/AssignRequestId.php`

> Faz 21.4 · `OPS-08` · K268. Her isteğe bir kimlik; günlük satırları, kuyruk işleri ve yanıt aynı kimliği taşır.

## 1. Hangi sorunu çözüyor?

Kullanıcı "ödeme sırasında hata aldım" der. Günlükte aynı dakikaya düşen onlarca satırdan hangisinin o isteğe ait
olduğu bilinmiyordu; kuyruğa giden iş (posta, görsel işleme) ile onu doğuran istek de eşlenemiyordu.

## 2. Ne yapıyor?

1. Gelen `X-Request-Id` başlığına bakar. **Yalnızca biçimi geçerliyse** (ULID ya da UUID) kullanır; değilse (yok, boş,
   serbest metin, HTML, nginx'in 32 haneli `$request_id`'si) yeni bir ULID üretir.
2. Kimliği `Context::add('request_id', …)` ile Laravel'in **Context**'ine yazar.
3. Yanıta `X-Request-Id: <kimlik>` başlığını koyar.

Context'in kendiliğinden yaptıkları (Laravel 11+):

| Nereye | Nasıl | Sonuç |
|---|---|---|
| Günlük satırı | `ContextLogProcessor` her kanala eklenir, `Context::all()`'u kaydın `extra`'sına yazar | `single`/`daily` satırının sonunda `{"request_id":"01J…"}` |
| Kuyruk işi | `Queue::createPayloadUsing` Context'i işin yüküne (`illuminate:log:context`) koyar; işçi `JobProcessing`'de geri yükler | İşin yazdığı satırlar da aynı `request_id`'yi taşır |

## 3. Neden zincirin en başında (`prepend`)?

Global zincirde bu ara katmandan **önce** dönen bir yanıt kimliksiz kalır: bakım kipi (`PreventRequestsDuringMaintenance`,
K264 deploy'u sırasında 503), çok büyük gövde (`ValidatePostSize`, 413), CORS ön kontrolü. `prepend` hepsini sarar. Test:
`an_early_response_from_the_global_stack_carries_it_too` (413). M1 kuralı korunur: istek başlığı değiştirilmez, yalnızca okunur.

## 4. Neden gelen kimliğe koşullu güveniliyor? (§A-2)

| Seçenek | Sorun |
|---|---|
| Hiç güvenme, hep üret | Önde bir vekil ya da istemci kendi kimliğini gönderirse iki kayıt eşlenemez |
| Her değere güven | Günlüğe istemcinin seçtiği serbest metin girer (satır kırma, çok uzun değer, sahte "kanıt") |
| **Yalnızca ULID/UUID** ✅ | Biçim uzunluğu da sınırlar (26/36 karakter); günlüğe yalnızca bu alfabe girer |

Sahte ama geçerli bir ULID göndermek bir şey kazandırmaz: kimlik yetki taşımaz, yalnızca satırları gruplar.

## 5. Hata zarfına neden girmedi? (§A-1)

K20 zarfı (`{error: {code, fields?, params?}}`) değişmedi; kimlik yalnızca başlıkta. Frontend hata bildiriminde
"destek kodu" göstermek ayrı bir iş (başlık zaten erişilebilir: API aynı köken, CORS gerekmez).

## 6. Sunucu adımı (öneri, koşulmadı)

Nginx'in erişim günlüğü aynı kimliği PHP'nin yanıt başlığından okuyabilir; nginx'e kimlik **ürettirmeye** gerek yok
(nginx'in `$request_id`'si 32 haneli onaltılık sayı, ULID/UUID değil, backend onu reddeder):

```nginx
# /etc/nginx/nginx.conf, http { } içinde
log_format davetkart '$remote_addr - [$time_local] "$request" $status $body_bytes_sent '
                     '$request_time rid=$upstream_http_x_request_id';
# deploy/nginx/davetkart.conf, API location'ında
access_log /var/log/nginx/davetkart.access.log davetkart;
```

`$upstream_http_x_request_id` = PHP-FPM'in döndürdüğü `X-Request-Id`. Böylece erişim günlüğü, Laravel günlüğü ve kuyruk
işi aynı kimlikle aranır: `grep 01J… /var/log/nginx/davetkart.access.log storage/logs/laravel-*.log`.

## 7. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| `append()` ile zincirin sonuna eklemek | 413/503 gibi erken yanıtlar kimliksiz |
| `Log::shareContext()` kullanmak | Yalnızca o anda çözülmüş kanallara gider, kuyruk işine taşınmaz; Context ikisini de yapar |
| Gelen başlığı olduğu gibi günlüğe yazmak | İstemci günlüğe istediği metni sokar |
| Kimliği hata zarfına eklemek | Sözleşme (K20) ve frontend tipleri değişir |

## 8. Neyi kapatmaz (B6)

- Sentry olaylarına kimlik etiketi eklenmedi (Sentry kendi olay kimliğini tutar); eşlemek gerekirse ayrı iş.
- Zamanlayıcı ve artisan komutlarının satırlarında `request_id` yok (istek yok).
