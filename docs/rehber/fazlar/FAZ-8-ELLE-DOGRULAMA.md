# FAZ 8 — Elle Doğrulama Betiği

> **Amaç:** `composer check`'in **göremediği** şeyleri görmek.
> **Süre:** ~25 dakika · **18 adım**
> **Ön koşul:** `composer check` yeşil bitmiş olmalı.

> 🔴 Test yeşil yanmışsa doğru çalıştığı anlamına gelmez — yalnızca *bizim
> kurduğumuz dünyada* çalıştığı anlamına gelir. Bu betik gerçek veritabanı,
> gerçek ağ ve gerçek anahtar ile çalışır.

---

## Adım 0 — Önceki fazların borcu

- [ ] `FAZ-5-ELLE-DOGRULAMA.md` (16 adım) — **hâlâ açık**
- [ ] `FAZ-6-ELLE-DOGRULAMA.md` (18 adım) — **hâlâ açık**
- [ ] `FAZ-7-ELLE-DOGRULAMA.md` (20 adım) — **hâlâ açık**

Faz 8 bunları kapatmaz. Burada listelenmesinin sebebi, kapanış listesinde
"Faz 8 bitti" işaretlenirken bu üçünün **unutulmamasıdır**.

---

## Adım 1 — Migration

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
php artisan migrate
```

**Beklenen:** iki yeni tablo — `assistant_usages`, `contact_messages`.

- [ ] pgAdmin'de iki tablo görünüyor
- [ ] `assistant_usages` üzerinde `assistant_usages_user_id_usage_date_unique`
- [ ] `contact_messages` üzerinde `contact_messages_subject_check`

---

## Adım 2 — Hata kataloğu (K33/K34)

```powershell
php artisan errors:export
git diff contracts/error-codes.json
```

- [ ] 🔴 `generatedAt` **dışında hiçbir fark yok**
- [ ] `count` **21**

Fark çıkarsa katalog elle yanlış düzenlenmiş demektir; enum kazanır.

---

## Adım 3 — Kalite kapısı

```powershell
composer lint
composer check
```

- [ ] 🔴 **SON satır** yeşil (`composer check` fail-fast; ilk satıra bakma)
- [ ] `Tests: 198 passed`

---

## Adım 4 — Ortam: sürücü seçimi

`.env` dosyasına ekle:

```
AI_PROVIDER=null
```

```powershell
php artisan config:clear
php artisan tinker
```

```php
get_class(app(App\Services\Ai\AiProvider::class));   // NullProvider
app(App\Services\Ai\AiProvider::class)->reply('merhaba');
```

- [ ] `NullProvider` bağlandı
- [ ] Yanıt: *"The DavetKart assistant is not configured in this environment."*

---

## Adım 5 — 🔴 Bilinmeyen sürücü sessizce düşmüyor (K70)

```php
config(['ai.default' => 'openai']);
app()->forgetInstance(App\Services\Ai\AiProvider::class);
app(App\Services\Ai\AiProvider::class);
```

- [ ] `AiProviderException: AI provider 'openai' is not available.`
- [ ] 🔴 **NullProvider'a düşmedi.** Düşseydi üretimde yanlış yazılmış bir
      `AI_PROVIDER` sahte cevaplar üretir ve kimse fark etmezdi

---

## Adım 6 — Asistan ucu auth gerektiriyor

```powershell
curl -X POST http://localhost:8000/api/assistant/chat `
  -H "Content-Type: application/json" -H "Accept: application/json" `
  -d '{\"message\":\"merhaba\"}' -i
```

- [ ] `401` ve `{"error":{"code":"UNAUTHENTICATED"}}`
- [ ] 🔴 Gövdede **hiçbir** ipucu yok

---

## Adım 7 — Mutlu yol (NullProvider ile)

Önce giriş yapıp token al (`FAZ-2-ELLE-DOGRULAMA.md` Adım 4).

```powershell
curl -X POST http://localhost:8000/api/assistant/chat `
  -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" `
  -H "Accept: application/json" -d '{\"message\":\"Nikah daveti metni\"}' -i
```

- [ ] `200`
- [ ] Gövde **zarflı**: `{"data":{"reply":"..."}}`

```php
// tinker
App\Models\AssistantUsage::latest()->first()->only(['user_id','usage_date','message_count']);
```

- [ ] Sayaç **1**
- [ ] 🔴 Tabloda gönderdiğin **metin yok** — yalnızca sayı

---

## Adım 8 — 🔴 Kota gerçekten duruyor

```php
// tinker
config(['davetkart.assistant.daily_message_limit_per_user' => 1]);
```

Adım 7'yi **tekrarla**.

- [ ] `429`
- [ ] `error.code` = **`ASSISTANT_QUOTA_EXCEEDED`** (❗ `RATE_LIMITED` değil)
- [ ] `error.params.limit` = 1
- [ ] `error.params.retryAfter` bir tamsayı

---

## Adım 9 — 🔴 Kota cache'e bağlı DEĞİL (Q2)

```powershell
php artisan cache:clear
```

Adım 8'i tekrarla.

- [ ] **Hâlâ 429** — cache temizlendi ama bütçe durdu
- [ ] 🔴 Bu adım, kotanın neden `throttle` kovasında tutulmadığını
      **gösteren** tek doğrulamadır; hiçbir test bunu söyleyemez

---

## Adım 10 — Hız sınırı kotadan ayrı (L3)

```php
config(['davetkart.assistant.daily_message_limit_per_user' => 100]);
config(['davetkart.assistant.rate_limit.per_user_per_minute' => 2]);
```

Aynı istekle **3 kez** üst üste dene.

- [ ] İlk iki istek `200`
- [ ] Üçüncü `429` ve `error.code` = **`RATE_LIMITED`**
- [ ] 🔴 İki farklı sınır, **iki farklı kod**

---

## Adım 11 — Gerçek Gemini (isteğe bağlı ama önerilir)

`.env`:

```
AI_PROVIDER=gemini
GEMINI_API_KEY=...
```

```powershell
php artisan config:clear
```

Adım 7'yi tekrarla.

- [ ] Gerçek, Türkçe bir yanıt geldi
- [ ] Yanıt **15 saniyeden** kısa sürede döndü
- [ ] `storage/logs/laravel.log` içinde **anahtar geçmiyor**

---

## Adım 12 — 🔴 Sağlayıcı çökerse sistem çökmüyor (H8)

`.env` içinde anahtarı boz (`GEMINI_API_KEY=gecersiz`), `config:clear`,
Adım 7'yi tekrarla.

- [ ] `503` ve `error.code` = `PROVIDER_UNAVAILABLE`
- [ ] `error.params.retryAfter` var
- [ ] 🔴 Gövdede Google'ın hata metni **yok**
- [ ] `laravel.log`'da `Gemini rejected the request.` satırı **var**

---

## Adım 13 — 🔴 Hata sırasında bile kota düşüldü (Q3)

Adım 12'den hemen sonra:

```php
App\Models\AssistantUsage::where('user_id', ID)->first()->message_count;
```

- [ ] Sayaç **arttı**
- [ ] 🔴 Bu bir hata değil karardır: bir zaman aşımı, isteğin
      faturalanmadığı anlamına gelmez (ders 57)

---

## Adım 14 — İletişim formu

```powershell
curl -X POST http://localhost:8000/api/public/contact `
  -H "Content-Type: application/json" -H "Accept: application/json" `
  -d '{\"name\":\"Deniz\",\"email\":\"d@example.test\",\"subject\":\"pricing\",\"message\":\"Merhaba\"}' -i
```

- [ ] `204`
- [ ] Gövde **tamamen boş**
- [ ] `contact_messages` tablosunda 1 satır

---

## Adım 15 — 🔴 KVKK: ham IP yok

```php
App\Models\ContactMessage::first()->ip_hash;
```

- [ ] 64 karakterlik onaltılık dizi
- [ ] 🔴 `127.0.0.1` **hiçbir kolonda** geçmiyor

```php
App\Support\IpHasher::hash('127.0.0.1') === App\Models\ContactMessage::first()->ip_hash;  // true
```

---

## Adım 16 — 🔴 Honeypot sessizce yutuyor (L2)

Aynı isteği `"website":"http://spam.example"` ekleyerek gönder.

- [ ] Yine `204` — yanıt **birebir aynı**
- [ ] 🔴 `contact_messages` sayısı **artmadı**
- [ ] Bu, `curl` çıktısına bakarak **anlaşılamaz**; kanıt tabloda

---

## Adım 17 — Geçersiz konu

`"subject":"sikayet"` ile gönder.

- [ ] `422`, `error.fields.subject.0.rule` = **`in`**
- [ ] 🔴 `illuminate_validation_rules_enum` **görünmüyor** (D6)

Sonra doğrulamayı **atlayarak** kısıtı sına:

```php
DB::table('contact_messages')->insert([
  'name'=>'x','email'=>'x@y.test','subject'=>'sikayet','message'=>'x',
  'ip_hash'=>str_repeat('a',64),'created_at'=>now(),'updated_at'=>now(),
]);
// QueryException: contact_messages_subject_check
```

- [ ] Kısıt patladı — kural yalnızca HTTP katmanında değil

---

## Adım 18 — Mutasyon denemeleri (T16, en az 5)

`AssistantTest.md` §5 ve `ContactTest.md` §5 tablolarından **beş** satır seç,
kodu boz, `composer check` koş, sonra `git checkout` ile geri al.

Önerilen beşli:

- [ ] `AskAssistantAction::handle` sırasını ters çevir → satır 2 kırmızı
- [ ] `where('message_count','<',$limit)` kaldır → satır 4 kırmızı
- [ ] Anahtarı `?key=` ile gönder → satır 18 kırmızı
- [ ] Action'daki honeypot dalını sil → Contact satır 2 kırmızı
- [ ] `IpHasher`'ı düz `hash()`'e çevir → Contact satır 6 kırmızı

🔴 **Beklenen test kırmızı yanmıyorsa**, o davranış aslında test
edilmiyordur. Faz 4'te üç IDOR testi tam olarak böyle boş yeşil yanmıştı.

---

## Kapanış

Bu betik yeşil bittiğinde `FAZ-8.md` §12'yi işaretle ve **durum alanını**
güncelle (**B7**).
