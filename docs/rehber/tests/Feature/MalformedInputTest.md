# `tests/Feature/MalformedInputTest.php`

> **Faz:** 10 — Sertleştirme, adım 10.20 · **7 metot, 19 vaka**
> **Test edilen:** [`RejectMalformedInput.md`](../../app/Http/Middleware/RejectMalformedInput.md) ·
> [`bootstrap/app.md`](../../bootstrap/app.md) (kayıt ve öncelik) ·
> `ApiExceptionRenderer::headers()` (`Retry-After`)
> **Kapattığı bulgular:** test denetimi **K-2** (NUL), **K-5** (`Retry-After`), **K-6** (yarım JSON) · **K91**, **K93**

---

## 1. Neden ayrı bir dosya?

`ef7c692` bozuk girdi kapısını **tek bir middleware** olarak `api` grubuna koydu.
Gerekçe (K91): *"yeni bir uç eklendiğinde kimse bir kuralı hatırlamak zorunda
kalmasın."* Ama kapıyı yalnızca `RsvpTest` sınıyordu, o da yalnızca LCV ucunda.

Bir kapının *"her yerde"* çalıştığı ancak her yerde sınanarak kanıtlanır. Bu
dosya o kanıt. Tek bir teste yazılmadı; her rota grubundan bir uç alıyor.

---

## 2. Kapı testleri

### 2.1 Altı uç × iki bozukluk = 12 vaka

| Grup | Uç | Kimlik |
|---|---|---|
| auth | `POST /auth/login` | — |
| davetiye | `POST /invitations` | token |
| public LCV | `POST /public/invitations/{olmayan}/rsvps` | — |
| public iletişim | `POST /public/contact` | — |
| asistan | `POST /assistant/chat` | token |
| webhook | `POST /public/payments/webhook` | — |

Her uç iki bozuklukla deneniyor:

| Bozukluk | Nasıl gönderiliyor | Beklenen |
|---|---|---|
| Yarım JSON | `$this->call(..., content: '{"message": "Merhaba, düğün saat kaçta başl')` | 400 `MALFORMED_REQUEST` · `fields` **yok** |
| NUL baytı | `postJson(..., ['email' => "ayse\u{0000}@…"])` | aynı |

`fields` yokluğu önemli: 422 ile 400'ün farkı tam olarak bu. Doğrulama hatası
alan alan konuşur. Biçim hatası konuşmaz, çünkü gövdenin hangi alanı taşıdığı
bile bilinmiyor.

### 2.2 🔴 Neden bazı uçlara token veriliyor?

Kapı öncelik listesinde `Authenticate` ve `ThrottleRequests`'ten **sonra**,
`SubstituteBindings`'ten **önce** çalışıyor (`bootstrap/app.php` →
`prependToPriorityList`). Token olmadan kimlik isteyen uçta 400'den önce **401**
gelirdi ve test kapıyı değil, kimliği sınamış olurdu.

Bu sıra bilinçli:

| Önce | Sonra | Sonuç |
|---|---|---|
| `Authenticate` | kapı | Kimliksiz bozuk istek 401 alır. Anonim biri kapının varlığını yoklayamaz |
| `ThrottleRequests` | kapı | Bozuk istek yağdıran bot da sayacı doldurur |
| kapı | `SubstituteBindings` | Bozuk istek veritabanına sorgu açtırmaz (§2.3) |

### 2.3 `a_malformed_request_is_rejected_before_route_model_binding`

```php
$this->withToken($token)
    ->putJson(route('invitations.update', self::YOK_OLAN_ULID), ['invitation' => ['title' => "Dü\u{0000}ğün"]])
    ->assertStatus(400);

// invitations tablosuna HİÇ sorgu gitmedi
```

Var olmayan bir davetiyeye bozuk istek 404 değil **400** alıyor ve `invitations`
tablosuna tek bir sorgu gitmiyor. Öncelik satırı silinirse bağlama önce çalışır:
sorgu gider, 404 döner, test kırılır.

**İlk yazımdaki hata (10.20'de mutasyonla bulundu):** test önce public LCV ucunu
kullanıyordu ve sorgu günlüğünün **tamamen** boş olmasını iddia ediyordu. Öncelik
satırı silindiğinde test **yeşil kaldı**. Sebep şu: LCV ucu davetiye kimliğini
`string $invitation` olarak alıp Action'da çözüyor, yani örtük bağlama hiç yok.
Test, sınamadığı bir şeyi iddia ediyordu. `invitations.update` ise
`Invitation $invitation` ile örtük bağlama yapıyor.

Sorgu günlüğü neden **tamamen** boş değil? Kapı `Authenticate`'ten sonra çalıştığı
için token ve kullanıcı sorguları beklenen. İddia bu yüzden yalnızca `invitations`
tablosu hakkında.

### 2.4 Diğer iki kapı testi

| Test | Neden |
|---|---|
| `a_nul_byte_in_a_key_is_malformed_too` | Middleware **anahtarları** da tarıyor: jsonb kolonları `\u0000`'ı anahtarda da reddeder (500) |
| `a_well_formed_request_passes_the_gate` | T6'nın varlık yarısı: sağlam istek kapıdan geçer (422: boş form, doğrulamaya ulaştı) |

---

## 3. `Retry-After` testleri (K-5 · K93)

`docs/08` §4.1: *"429 yanıtı `Retry-After` başlığını taşır."* `ef7c692`'ye kadar
değer yalnızca gövdede (`params.retryAfter`) vardı. `RsvpTest` LCV ucunu sınadı;
burada kalan uçlar sınanıyor:

| Test | 429'un kaynağı | Beklenen |
|---|---|---|
| `the_auth_limiter_…` | `throttle:auth` (5/dk) | başlık **ve** gövde tam `60` |
| `the_contact_limiter_…` | `throttle:contact` (config ile 1/dk) | `60` |
| `the_assistant_limiter_…` | `throttle:assistant` (config ile 1/dk) | `60` |
| `the_assistant_daily_quota_…` | **İş kuralı**: `AssistantQuotaExceededException` | başlık = gövde (> 0) |

### 3.1 Neden geçersiz istekle?

`ThrottleRequests` sayacı istek controller'a **ulaşmadan** artırıyor. Bu yüzden
limiti 1'e çekip boş bir gövde (422) göndermek sayacı doldurmaya yetiyor. Ne
yapay zekâ sağlayıcısı ne gerçek bir iletişim mesajı ne de veritabanı kaydı
gerekiyor. Test yalnızca sınadığı şeye bağımlı.

### 3.2 Neden zaman donduruluyor?

`freezeSecond()`: kova dakikalık. Donmuş zamanda kalan süre **tam 60** saniye,
değer bu yüzden kesin iddia edilebiliyor. Dondurulmasa değer 59 ya da 60 olabilirdi
ve test ya `assertIsInt` ile yetinirdi (Dilim E 10.52'nin `AssistantTest`'te
düzelteceği zayıflık) ya da ara sıra kırılırdı.

### 3.3 Kota testinde neden tam değer yok?

Günlük kotanın kalan süresi gün sınırına bağlı ve plan 10.57 o sınırı UTC'den
İstanbul saatine taşıyacak. Tam değer iddia edilseydi test bir iş kararını
sabitlemiş olurdu. Onun yerine asıl sözleşme iddia ediliyor: **başlık = gövde**.
İkisi ayrışırsa istemci hangisine inanacağını bilemez.

Bu test ayrıca başlığın **iki farklı kaynaktan** geldiğini kanıtlıyor: throttle
429'unda `ThrottleRequestsException`'ın başlığından, kota 429'unda istisnanın
`errorParams()`'ından. İkisi de aynı yerden (`ApiExceptionRenderer::headers()`)
geçiyor.

---

## 4. Mutasyon kanıtı (1 Ekim 2026, İsmail'in makinesi, PHP 8.5)

| # | Mutasyon | Kırılan |
|---|---|---|
| M1 | `bootstrap/app.php`: `appendToGroup('api', RejectMalformedInput::class)` silindi | 14/19: bütün kapı vakaları |
| M2 | `bootstrap/app.php`: `prependToPriorityList(...)` silindi | `a_malformed_request_is_rejected_before_route_model_binding` (ilk yazımda: **hiçbiri**, §2.3) |
| M3 | Middleware: yarım JSON kontrolü `return false` | 6/19: altı yarım JSON vakası |
| M4 | Middleware: anahtar taraması kapatıldı | `a_nul_byte_in_a_key_is_malformed_too` |
| M5 | `ApiExceptionRenderer::headers()` → `return []` | dört `Retry-After` testi |

---

## 5. Çalıştırma

```powershell
php artisan test --filter=MalformedInputTest
# 19 passed
```
