# `tests/Feature/AssistantTest.php`

> **Faz:** 8, dosya 8.16 · **21 test** · **Kurallar:** T10 · T13 · T14 · T16

---

## 1. Bu dosya neyi kanitliyor?

Faz 8'in birinci dilimi: **para harcayan uc**. Uc soru cevaplaniyor.

| Soru | Kaniti |
|---|---|
| Kimlik olmadan girilebiliyor mu? | 401 |
| Butce gercekten tutuluyor mu? | `assistant_usages.message_count` **kolonu** |
| Sir ve saglayici hatalari sizmiyor mu? | Giden istek ve donen govde |

---

## 2. 🔴 Yanit degil ETKI (T14)

Bu dosyanin en onemli dort testi yaniti degil **kaliciyi** dogruluyor:

| Test | Yanit ne der | Gercek kanit |
|---|---|---|
| `the_quota_is_charged_even_when_the_provider_fails` | 503 | `message_count = 1` |
| `yesterdays_usage_does_not_count_today` | 200 | **Iki** satir, bugunkinde 1 |
| `an_invalid_request_never_reaches_the_provider` | 422 | Tabloda **hicbir** satir |
| `the_api_key_travels_in_a_header_and_never_in_the_url` | 200 | Giden istegin **kendisi** |

Yalnizca duruma bakan bir test bu dordunde de **yesil yanardi** ve hicbirini
kanitlamazdi.

---

## 3. Sahte surucu neden anonim sinif?

Testler **gercek Gemini'yi hic cagirmaz**; ag hic acilmaz. Iki sahte surucu
var:

| Sahte | Ne yapar | Neyi sinar |
|---|---|---|
| `bindReplyingProvider()` | Sabit metin doner | Mutlu yol, kota, hiz siniri |
| `bindFailingProvider()` | `AiProviderException` firlatir | 503 yolu, H8 sizinti testi |

`GeminiProvider`'in **kendisi** ayri testlerde `Http::fake()` ile sinaniyor —
yani "sahte surucu kullandik" bahanesiyle atlanan bir kod yok
(**FakeGateway**'in Faz 7'deki ayni disiplini).

---

## 4. T10 / T13

- Token yolu `withToken()` ile sinaniyor. `actingAs()` guard'i atlar ve
  **yesil yanan bos bir test** uretir.
- Ayni test icinde ikinci kimlikli istekten once `forgetAuthState()`
  cagriliyor; aksi halde `RequestGuard` ilk kullaniciyi onbellekten doner.

---

## 5. 🔴 Mutasyon tablosu (T16)

Her satir: **kodda ne bozulursa** hangi test kirmizi yanar. Bir satirin
karsisi bossa o davranis **test edilmiyor** demektir.

| # | Mutasyon | Kirmizi yanan test |
|---|---|---|
| 1 | Rotadan `auth:sanctum` kaldir | `the_assistant_requires_authentication` |
| 2 | `AskAssistantAction::handle` sirasini ters cevir (once cagri, sonra kota) | `the_quota_is_charged_even_when_the_provider_fails` |
| 3 | `chargeDailyQuota()` govdesini bosalt | `the_daily_quota_is_enforced` |
| 4 | `where('message_count', '<', $limit)` kosulunu kaldir | `the_daily_quota_is_enforced` |
| 5 | `$charged === 0` yerine `> 0` yaz | `the_daily_quota_is_enforced` |
| 6 | `insertOrIgnore` satirini sil | `an_authenticated_user_gets_a_reply` (0 satir artar → hep 429) |
| 7 | Kota sorgusundan `usage_date` kosulunu cikar | `yesterdays_usage_does_not_count_today` |
| 8 | Kota sorgusundan `user_id` kosulunu cikar | `the_quota_is_counted_per_user` |
| 9 | `AssistantQuotaExceededException`'i `RsvpQuotaExceededException` ile degistir | `the_daily_quota_is_enforced` (kod farki) |
| 10 | `ErrorCode::AssistantQuotaExceeded` durumunu 403 yap | `the_daily_quota_is_enforced` |
| 11 | `errorParams()`'tan `limit` cikar | `the_quota_rejection_carries_the_limit_and_a_retry_hint` |
| 12 | `errorParams()`'tan `retryAfter` cikar | `the_quota_rejection_carries_the_limit_and_a_retry_hint` |
| 13 | `AskAssistantRequest`'ten `required` kuralini kaldir | `the_message_is_required` |
| 14 | `max` degerini config yerine sabit yaz | `the_message_length_is_capped_by_configuration` |
| 15 | Controller'da zarfi kaldir (`['reply' => ...]`) | `the_reply_is_wrapped_in_the_data_envelope` |
| 16 | `resolveAiProvider`'da `default =>` kolunu `NullProvider`'a dusur | `an_unknown_driver_is_rejected` |
| 17 | `GeminiProvider::apiKey()` kontrolunu kaldir | `the_gemini_driver_refuses_to_run_without_an_api_key` |
| 18 | Anahtari `?key=` sorgusuyla gonder | `the_api_key_travels_in_a_header_and_never_in_the_url` |
| 19 | `systemInstruction`'i kullanici mesajiyla birlestir | `the_system_instruction_is_sent_as_its_own_field` |
| 20 | Bos `candidates` kontrolunu kaldir | `an_empty_gemini_candidate_is_treated_as_a_failure` |
| 21 | `$response->failed()` kolunu kaldir | `a_rejected_gemini_request_becomes_service_unavailable` |
| 22 | Saglayici mesajini exception'a koyup yanita sizdir | `the_raw_provider_error_never_reaches_the_response` |
| 23 | `NullProvider::reply()` icinde `Http::get()` cagir | `the_null_provider_answers_without_touching_the_network` |
| 24 | Rotadan `throttle:assistant` kaldir | `assistant_requests_are_rate_limited` |
| 25 | `assistantLimits()` anahtarini IP yap | ⚠️ **hicbiri** — bkz. §6 |

---

## 6. 🔴 Testin kapatamadigi bosluklar (B6)

| Bosluk | Neden test edilemiyor |
|---|---|
| Hiz sinirinin **kullanici** anahtarli olmasi | Tek testte tum istekler ayni IP'den (127.0.0.1) gelir; IP anahtarli bir limiter de ayni sonucu verir. Ayrimi ancak iki farkli IP kurabilir — HTTP test istemcisinde bunu kurmak, korumanin kendisinden karmasik. **Kod incelemesiyle** korunur (satir 25) |
| Es zamanli iki istegin kotayi asamamasi | Tek surecli PHPUnit'te gercek eszamanlilik kurulamaz. Koruma **veritabaninda** (atomik UPDATE + UNIQUE); T15 ailesi |
| Prompt'un hicbir yere yazilmamasi | "Hicbir yerde yok" testi tum semayi taramayi gerektirir. Koruma **sema**dadir: boyle bir kolon yok |
| Gercek Gemini'nin sozlesmesi | `Http::fake()` bizim **varsayimimizi** sinar, Google'in gercek yanitini degil. `FAZ-8-ELLE-DOGRULAMA.md` bunu gercek anahtarla dogruluyor |

---

## 7. Sirada ne var?

[`ContactTest.md`](ContactTest.md) — dorduncu auth'suz yazma yolunun kaniti.
