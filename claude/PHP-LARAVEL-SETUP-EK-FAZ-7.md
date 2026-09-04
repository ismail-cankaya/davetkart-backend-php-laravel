# `PHP-LARAVEL-SETUP.md` — Faz 7 eki

> **Bu bir yama dosyasıdır.** Aşağıdaki bloklar master
> `claude/PHP-LARAVEL-SETUP.md` dosyasının ilgili bölümlerine **elle** eklenecek.
>
> 🔴 `PHP-LARAVEL-SETUP-EK-FAZ-5.md` ve `-EK-FAZ-6.md` de hâlâ işlenmemiş
> olabilir — üç ek dosya **birlikte** işlenmeli.

---

## A) §7 Karar tablosuna eklenecek satırlar (K64–K71)

| # | Karar | Gerekçe |
|---|---|---|
| **K64** | Checkout **iki uç**: `/invitations/{id}/checkout` (tekil) · `/payments/checkout` (paket) | `docs/09` düz bir uç öngörmüştü; **N1** gereği aidiyet URL'nin **yapısında** durur. Faz 6 aynı kararı medya uçlarında zaten vermişti. Kimlik gövdede olsaydı `exists` kuralı cazip olur ve kimlik uzayı taranabilir hâle gelirdi (A1) |
| **K65** | Webhook ucu **`/api/public/`** altında | **K12** fail-safe grubu; `docs/09`'un `/api/payments/webhook` planı ezildi. Auth'suz yüzeyin tamamı `routes/api.php`'de tek bakışta görünür |
| **K66** | `public_slug` **üretilmiyor** | **K40** onu geçersiz kıldı: `invitations.id` zaten ULID ve paylaşılan linkin kendisi. Sonuç: `SLUG_TAKEN` kodu kullanılmıyor ama **silinmiyor** — yayınlanmış kod adı sözleşmedir (`docs/08` §5.1) |
| **K67** | Ödeme **yayınlamaz**; yayın ayrı bir kullanıcı eylemidir | Paket alımda hangi davetiye belirsiz (K42); kullanıcı ödeme ile yayın arasında son düzenleme isteyebilir; webhook bir **makine** bildirimi, yayın bir **insan** kararıdır |
| **K68** | "Zaten yayında" → **409**, sessiz başarı değil | Yayın ücretli ve yan etkili. 200 dönmek kullanıcıya iki kez ödediğini düşündürür. (Webhook'ta idempotans **isteniyor** — orada tekrar eden taraf bir makine ve niyeti teyit) |
| **K69** | İmza hatası → **404** (401/403/400 değil) | 401 frontend interceptor'ını tetikler + saldırgana sinyal verir; 403 ucun **varlığını** doğrular; 400 bozuk gövde ile sahte imzayı **ayırt ettirir**. 404 hiçbir ayrım vermez (L2/L6) |
| **K70** | Sürücü seçimi **çözüm anında**, sessiz varsayılan **yok** | Hatalı config yalnızca ödeme uçlarını kırmalı (blast radius). `default => fake` olsaydı eksik `IYZICO_API_KEY` her ödemeyi **bedava** yapardı |
| **K71** | `invitations.timezone` = **duvar saati + IANA kimliği**, `timestamptz` değil | Sorun depolama değil **niyet**: "19:00" düğünün olduğu yerin saatidir. Yaz saati kuralı değişirse `timestamptz` saati **kaydırır**, duvar saati kaydırmaz (iCal `TZID` modeli) |

---

## B) Kural listesine eklenecekler (Faz 7 · 10 kural)

### Yeni seri **M5+** — para ve ticari kurallar

> M1-M4 Faz 1'de middleware serisiydi; kural **numaraları** benzersizdir, harf
> yalnızca gruplama.

| # | Kural | Gerekçe |
|---|---|---|
| **M5** | Para **en küçük birimde tam sayı** saklanır (`amount_minor`) | `0.1 + 0.2 !== 0.3`; bin satırlık toplamda kuruş kaybolur ve mutabakat tutmaz |
| **M6** | Fiyat **asla** istek gövdesinden okunmaz | Bir fiyat alanı *doğrulanabilir* değildir: `{"price":1}` `integer\|min:1`'i geçer. Çözüm doğrulama değil **mimaridir** |
| **M7** | Bir satışın **para birimi ve sağlayıcısı satırda** saklanır | Config bugünü, kolon geçmişi anlatır (F4'ün para eksenindeki hâli) |
| **M8** | İdempotans **iki katmandır**: UNIQUE kısıt **+** durum makinesi & satır kilidi | UNIQUE "ikinci satır olamaz" der, "bir satır iki kez ilerleyemez" **demez** |

### Yeni seri **W** — auth'suz makine trafiği (webhook)

| # | Kural | Gerekçe |
|---|---|---|
| **W1** | İmza **ham gövde** üzerinden doğrulanır | `$request->all()` yeniden serileştirir; anahtar sırası/boşluk değişir ve imza "bazen" tutmaz |
| **W2** | İmza karşılaştırması **`hash_equals()`** ile | `===` ilk farklı baytta durur → zamanlama saldırısı (A4'ün kriptografik hâli) |
| **W3** | Webhook ucu **her zaman 2xx** döner | 404 sağlayıcıyı sonsuza kadar retry ettirir **ve** "bu referans bizde var/yok" sızdırır (L2) |

### Mevcut serilere eklenenler

| # | Kural | Gerekçe |
|---|---|---|
| **L7** | Bir **dış servis** transaction'a dâhil değildir — geri alınamayan iş **en sona** | F3'ün (dosya sistemi) dış servisteki ikizi. Ters sırada "ödenmiş ama kaydı olmayan ödeme" kalır |
| **P6** | Policy'nin cevabı `bool`dur; **bilgi taşıması gereken red** Policy'ye konmaz | Policy reddi 404'e çevrilir (H7); paywall reddi 402 olmalı ve `requiredTier` taşımalı |
| **E11** | Çok kolonlu bir **değişmez** CHECK kısıtına yazılır | `status='paid'` ⟺ `paid_at IS NOT NULL`. Bir `if` konsoldan/kuyruktan/seeder'dan atlanabilir (A8'in şema hâli) |

> Kural sayıları: FAZ-0 (31) · FAZ-1 (19) · FAZ-2 (20) · FAZ-3 (15) ·
> FAZ-4 (11) · FAZ-5 (10) · FAZ-6 (11) · **FAZ-7 (10)** = **127**

---

## C) Ders listesine eklenecekler (50–54)

**50. 🔴 Bir alanın "doğrulanabilir" olması, kabul edilebilir olduğu anlamına
gelmez.** `{"price": 1}` gövdesi `integer|min:1` kurallarının hepsini geçer.
Fiyat için doğru savunma bir **kural** değil, alanı **hiç kabul etmemektir**.
Doğrulama katmanı biçimi doğrular; değeri kimin ürettiği bir **mimari
karardır**.

**51. Aynı teknik soru, farklı çağıranda farklı cevap ister.** İdempotans
webhook'ta **isteniyor** (tekrar eden bir makine, niyeti teyit), yayında
**istenmiyor** (tekrar eden bir insan, niyeti yeni bir şey yapmak). Ders 42'nin
üçüncü örneği: kural değişmedi, **çağıran** değişti.

**52. Bir arayüzün değerini, yazdığın gün değil kaldırdığın uygulamanın
maliyetiyle ölçersin.** `RsvpQuotaResolver` Faz 5'te "gereksiz dolaylılık" gibi
görünüyordu; Faz 7'de gerçek kaynağa geçiş **tek satır** oldu ve
`SubmitRsvpAction` ile testlerine hiç dokunulmadı. `PaymentGateway` aynı
kazancı ikinci kez verdi: 502 yolu, gerçek bir sağlayıcı olmadan test edildi.

**53. Bir `bool` bilgi taşıyamaz — ve yetki katmanı bunu bilmeli.** Paywall
kontrolünü Policy'ye koymak doğal görünüyordu; ama Policy reddi H7 gereği
**404**'e çevriliyor ve kullanıcı "davetiyem kayboldu" derdi. Kuralı iki
katmana bölerken ayırt edici soru: *"bu red bir bilgi taşımak zorunda mı?"*

**54. Framework'ün "modern" yolu, projenin sözleşmesini bozabilir.**
`Rule::enum(SubscriptionTier::class)` daha temiz görünüyordu ama
`$validator->failed()` anahtarı **sınıf adı** olurdu ve hata zarfına
`illuminate\validation\rules\enum` diye sızardı. Faz 3'ün **D6** kuralı
(`Password::min(8)` → `'min:8'`) tam olarak bunu yasaklamıştı. Aynı tuzak,
yeni kılıkta — bu kez **kod yazılmadan önce** yakalandı.

---

## D) Doküman haritasına eklenecek satırlar

| Dosya | İçerik |
|---|---|
| `docs/rehber/fazlar/FAZ-7.md` | Faz 7 kaydı — ⚠️ durum: **DOĞRULANMADI** |
| `docs/rehber/fazlar/FAZ-7-ELLE-DOGRULAMA.md` | 20 adımlık kapanış betiği (Adım 0 = Faz 6'nın listesi) |
| `docs/rehber/app/Enums/OrderStatus.md` | Durum makinesi + iki katmanlı idempotans |
| `docs/rehber/app/Models/Order.md` | Boş `#[Fillable]`, query scope |
| `docs/rehber/database/factories/OrderFactory.md` | Determinizm + plandan türetilen tutar |
| `docs/rehber/database/migrations/…create_orders_table.md` | 🔴 UNIQUE, 4 CHECK, para aritmetiği |
| `docs/rehber/database/migrations/…add_timezone_to_invitations_table.md` | 🔴 K63/K71 |
| `docs/rehber/app/Exceptions/PaywallViolationException.md` | Dördünün ortak kaydı |
| `docs/rehber/app/Services/Payment/PaymentGateway.md` | Strategy Pattern + 2 DTO |
| `docs/rehber/app/Services/Payment/FakeGateway.md` | HMAC, `hash_equals`, sürücü seçimi |
| `docs/rehber/app/Services/Pricing/TierResolver.md` | Sunucu ikizi, K6'nın karşılığı |
| `docs/rehber/app/Contracts/PublishEntitlementResolver.md` | 🔴 K42 + operatör önceliği |
| `docs/rehber/app/Services/Rsvp/SubscriptionRsvpQuotaResolver.md` | Dikiş yeri kapandı |
| `docs/rehber/app/Actions/Payment/StartCheckoutAction.md` | Sunucu fiyatı + L7 telafisi |
| `docs/rehber/app/Actions/Payment/HandlePaymentCallbackAction.md` | İdempotans |
| `docs/rehber/app/Actions/Invitation/PublishInvitationAction.md` | 🔴 Paywall kapısı |
| `docs/rehber/app/Http/Requests/Payment/StoreCheckoutRequest.md` | D6 tuzağı |
| `docs/rehber/app/Http/Resources/OrderResource.md` | Beyaz liste |
| `docs/rehber/app/Http/Controllers/Api/V1/PaymentController.md` | İki kol |
| `docs/rehber/app/Http/Controllers/Api/V1/PublicPaymentWebhookController.md` | Üçüncü auth'suz yol |
| `docs/rehber/tests/Feature/PaywallTest.md` | 🔴 33 satırlık mutasyon tablosu |
| **silindi** | `docs/rehber/app/Services/Rsvp/TierRsvpQuotaResolver.md` (**B8**) |

---

## E) "Teknik durum" bölümüne yazılacak

```
Faz 0-4 : ✅ tamamlandı ve doğrulandı
Faz 5   : ⚠️ KOD TAMAMLANDI (17 adım) · composer check Faz 6'da koştu ve
          YEŞİL bitti · elle doğrulama (16 adım) HÂLÂ AÇIK
Faz 6   : ⚠️ KOD TAMAMLANDI (24+6 commit) · 6.29/6.30 ilk gerçek koşunun
          hatalarını düzeltti · FAZ-6.md durum alanı ve §11 listesi HÂLÂ AÇIK
          🔴 php artisan storage:link HİÇ ÇALIŞTIRILMADI
Faz 7   : ⚠️ KOD TAMAMLANDI (25 adım) · composer check HİÇ KOŞMADI
          kapanış ölçütü: FAZ-7-ELLE-DOGRULAMA.md (20 adım)
Faz 8   : ⬜ sıradaki (AI asistan ve iletişim)

Uç nokta sayısı : 19
Test sayısı     : 156 (123 + 33 doğrulanmamış)
PHPStan level   : 8
Kural sayısı    : 127
Karar sayısı    : 71
Ders sayısı     : 54
```

---

## F) §12 "Bilinen Frontend Uyuşmazlıkları" tablosuna

| Konu | Backend | Frontend | Karar |
|---|---|---|---|
| Checkout ucu | `POST /invitations/{id}/checkout` **veya** `/payments/checkout`, gövde `{tier}` | `paymentService.checkout({tier})` — **mock** | 🔴 Frontend uyarlanacak (K64) |
| Checkout yanıtı | **Zarflı** `{data:{orderId,tier,status,redirectUrl}}`, `status='pending'` | Zarfsız bekliyor, `status:'paid'` sabit | 🔴 Ödeme **anında** tamamlanmıyor; `paid`'e geçişi webhook yapar |
| Yayınlama | `POST /invitations/{id}/publish` → 200/402/409 | **Uç yok** | 🔴 `services/invitations.ts`'e eklenecek |
| `activeTier` | Sunucu tek doğruluk kaynağı (`orders`) | Oturum içi **mock** | 🔴 En kritik madde: mock kalırsa yayın 402 dönünce kullanıcı şaşırır |
| `invitation.timezone` | Her iki Resource'ta da gönderiliyor | `types.ts`'te **alan yok** | 🔴 Eklenecek; geri sayım bu dilimde hesaplanmalı (K63/K71) |
| Hata kodları | 5 yeni kod | `errors.json`'da karşılığı yok | `Notlar/03` — frontend yapacak |
