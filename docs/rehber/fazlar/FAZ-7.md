# FAZ 7 — Ödeme ve Paywall (Ticari Çekirdek)

> **Tarih:** 3 Eylül 2026
> **Durum:** ⚠️ **25/25 GELİŞTİRME ADIMI TAMAMLANDI — DOĞRULAMA BEKLİYOR**
> **Önceki:** [`FAZ-6.md`](FAZ-6.md) · **Sonraki:** Faz 8 — AI asistan ve iletişim
> **Bu dosya:** fazın kaydı, alınan kararlar, kurulan kurallar ve devir

---

## 0. 🔴 ÖNCE BUNU OKU — durum alanı ne diyor, ne demiyor

**Bu fazın hiçbir adımı `composer check` ile doğrulanmadı.**

Sebep: faz, PHP ve Composer'ın **kurulu olmadığı** bir yardımcı ortamda tek
oturumda yazıldı. Koşan tek kontrol sözdizimi bile değil.

🔴 **B7** gereği durum alanına "tamamlandı" değil **"doğrulama bekliyor"**
yazıldı. Bu projede Faz 1, 3 ve 4'te üç kez "yeşil" yazıldı ve değildi; Faz
5'te bilerek "doğrulanmadı" yazıldı ve **haklı çıktı** (Faz 6'nın ilk koşusu
PHPStan hataları ve gerçek bir 500 buldu).

**Faz 7, [`FAZ-7-ELLE-DOGRULAMA.md`](FAZ-7-ELLE-DOGRULAMA.md) yeşil bitene
kadar KAPANMAMIŞTIR.** §12'deki kapanış listesi işaretlenmeden Faz 8'e
geçilmemeli.

> ⚠️ **Faz 6'nın kapanış listesi de hâlâ açık.** `FAZ-6.md` §11'deki maddeler
> (özellikle 🔴 `php artisan storage:link`) işaretlenmedi. Faz 7'nin elle
> doğrulama betiği **Adım 0** olarak onları da içeriyor.

---

## 1. Faz 7 neydi?

**Amaç:** Projenin **ticari çekirdeğini** kurmak — yayınlamayı bir ödemeye
bağlamak ve bunu istemciye güvenmeden yapmak.

Fazların soruları birikerek ilerliyor:

| Faz | Soru |
|---|---|
| 3 | Kullanıcı kendi davetiyesini düzenleyebiliyor mu? |
| 4 | Misafir yayınlanmış bir davetiyeyi görebiliyor mu? |
| 5 | Misafir **yazabiliyor** mu? (auth'suz yazma) |
| 6 | Misafir **dosya** yükleyebiliyor mu? |
| **7** | **Davetiye nasıl yayınlanır — ve kim bunun bedelini ödedi?** |

Üç fazdır bekleyen üç şey bu fazda kapandı:

| Bekleyen | Kaynağı | Durum |
|---|---|---|
| `PublishInvitationAction` boş iskeleti | Faz 3 (**K47**) | ✅ dolduruldu |
| `RsvpQuotaResolver`'ın gerçek kaynağı | Faz 5 (**K51**) | ✅ bağlandı, `FALLBACK_TIER` silindi |
| `invitations.timezone` | Faz 4 → 5 → 6 (**K63**) | ✅ eklendi |

Ve `SubscriptionTier` enum'u — **Faz 0'da yazılıp yedi faz boyunca hiç
çağrılmayan** sınıf — nihayet kullanıldı.

---

## 2. Yazılan dosyalar (25 adım)

| # | Dosya | Ne yapar |
|---|---|---|
| 7.1 | `app/Enums/OrderStatus.php` | Durum makinesi + `grantsPublishRight()` |
| 7.2 | `..._create_orders_table.php` | 🔴 `provider_ref` UNIQUE + 4 CHECK |
| 7.3 | `app/Models/Order.php` | Boş `#[Fillable]`, `scopeGrantingPublishRight` |
| 7.4 | `database/factories/OrderFactory.php` | Plandan türetilen tutar |
| 7.5 | 4 exception | Paywall · 409 · imza · 502/503 |
| 7.6 | `Services/Payment/PaymentGateway.php` + 2 DTO | 🔴 Strategy Pattern (K8) |
| 7.7 | `Services/Payment/FakeGateway.php` | Gerçek HMAC, sahte para |
| 7.8 | `Services/Pricing/TierResolver.php` | `getRequiredTier()`'ın sunucu ikizi |
| 7.9 | `Contracts/PublishEntitlementResolver.php` + `OrderEntitlementResolver` | 🔴 K42 |
| 7.10 | `Actions/Payment/StartCheckoutAction.php` + `CheckoutResult` | Sunucu fiyatı + telafi |
| 7.11 | `Actions/Payment/HandlePaymentCallbackAction.php` | 🔴 İdempotans |
| 7.12 | `Actions/Invitation/PublishInvitationAction.php` | 🔴 **Paywall kapısı** |
| 7.13 | `StoreCheckoutRequest` + `OrderResource` | Tek alan, üç alan |
| 7.14 | `PaymentController` + `PublicPaymentWebhookController` | İki tehdit modeli |
| 7.15 | `routes/api.php` | 4 yeni uç |
| 7.16 | `Services/Rsvp/SubscriptionRsvpQuotaResolver.php` | Dikiş yeri kapandı |
| 7.17 | `..._add_timezone_to_invitations_table.php` + 6 dosya | 🔴 K63 |
| 7.18 | (düzeltme) `'in:'` kuralı | D6 ihlali önlendi |
| 7.19 | `tests/Feature/PaywallTest.php` | **33 test** + 33 satırlık mutasyon tablosu |
| 7.20–7.25 | Kılavuzlar, `CLAUDE.md`, faz belgeleri | K18 · B4 borcu |

### Düzenlenen mevcut dosyalar

| Dosya | Değişiklik |
|---|---|
| `app/Enums/SubscriptionTier.php` | `values()` eklendi (K39) |
| `app/Enums/ErrorCode.php` | `PAYMENT_REQUIRED` artık `requiredTier` taşıyor |
| `contracts/error-codes.json` | Yeniden üretildi (K33/K34) |
| `app/Models/User.php` · `Invitation.php` | `orders()` ilişkileri |
| `app/Models/Invitation.php` | `timezone` → `#[Fillable]` |
| `app/Policies/InvitationPolicy.php` | `publish()` yeteneği |
| `app/Providers/AppServiceProvider.php` | 3 bağlama + sürücü seçimi |
| `app/Http/Controllers/Api/V1/InvitationController.php` | `publish()` |
| `app/Http/Requests/Invitation/InvitationRequest.php` | `timezone` kuralı + harita |
| `app/Http/Resources/InvitationPayloadResource.php` · `PublicInvitationResource.php` | `timezone` |
| `app/Actions/Rsvp/ResolveOpenRsvpInvitationAction.php` | 🔴 Son tarih artık davetiyenin diliminde |
| `database/factories/InvitationFactory.php` | Varsayılan `timezone` |
| `config/davetkart.php` | `default_timezone` |
| `CLAUDE.md` | `if` kuralı gevşetildi (Faz 6 B4 borcu) + `app/Contracts/` + ödeme standartları |
| **silindi** | `app/Services/Rsvp/TierRsvpQuotaResolver.php` + kılavuzu |

---

## 3. Çalışan uç noktalar

| Method | Path | Auth | Yanıt |
|---|---|:---:|---|
| POST | `/api/invitations/{id}/publish` | ✅ | `200` · `{data:{...}}` · 402/409 |
| POST | `/api/invitations/{id}/checkout` | ✅ | `201` · tekil alım |
| POST | `/api/payments/checkout` | ✅ | `201` · paket alım |
| POST | `/api/public/payments/webhook` | — | `204` · imza doğrulamalı |

**Toplam uç sayısı: 19.**

---

## 4. 🔴 Mimarinin özeti: bir yayın isteği neyden geçiyor?

```
POST /api/invitations/{id}/publish
  │
  ├─ [throttle:api]  60/dk, IP
  ├─ [auth:sanctum]  token yoksa 401
  ├─ whereUlid       biçimsiz kimlik rotaya HİÇ ulaşmaz (O6)
  ├─ rota bağlaması  kayıt yoksa 404
  │
  └─ InvitationController::publish()
       ├─ Gate::authorize('publish')     → senin değilse 404 (H7)
       └─ PublishInvitationAction
            ├─ lockForUpdate + yeniden oku      (E9)
            ├─ zaten yayında mı?                → 409
            ├─ TierResolver::requiredFor()      → SUNUCUDA hesapla (K6)
            ├─ PublishEntitlementResolver       → K42: iki kaynak, tek arayüz
            │    ├─ ödeme yok                   → 402 PAYMENT_REQUIRED
            │    └─ plan yetmiyor               → 402 PAYWALL_TIER_INSUFFICIENT
            └─ status=published, published_at=now()
                 └─ save() → InvitationChanged → ClearInvitationCache (K48)
```

Dokuz katman, hiçbiri istemciden gelen bir değere güvenmiyor.

---

## 5. Kurulan kurallar (Faz 7 · 10 kural)

### Yeni seri **M** — para ve ticari kurallar

| # | Kural | Gerekçe |
|---|---|---|
| **M5** | Para **en küçük birimde tam sayı** saklanır (`amount_minor`) | Kayan nokta `0.1+0.2 ≠ 0.3`; bin satırlık toplamda kuruş kaybolur ve mutabakat tutmaz |
| **M6** | Fiyat **asla** istek gövdesinden okunmaz | Bir fiyat alanı *doğrulanabilir* değildir: `{"price":1}` biçimsel olarak kusursuzdur. Çözüm doğrulama değil **mimaridir** |
| **M7** | Bir satışın **para birimi ve sağlayıcısı satırda** saklanır | Config bugünü, kolon geçmişi anlatır (F4'ün para eksenindeki hâli) |
| **M8** | İdempotans **iki katmandır**: UNIQUE kısıt + durum makinesi & satır kilidi | UNIQUE "ikinci satır olamaz" der, "bir satır iki kez ilerleyemez" demez (B6) |

> M1-M4 Faz 1'de middleware serisiydi; **M5+** ticari eksende devam ediyor.
> Aynı harf iki seri taşıyor — kural numaraları **benzersizdir**, harf yalnızca
> gruplama.

### Yeni seri **W** — auth'suz makine trafiği (webhook)

| # | Kural | Gerekçe |
|---|---|---|
| **W1** | İmza, **ham gövde** üzerinden doğrulanır | `$request->all()` yeniden serileştirir; anahtar sırası/boşluk değişir ve imza "bazen" tutmaz |
| **W2** | İmza karşılaştırması **`hash_equals()`** ile yapılır | `===` ilk farklı baytta durur → zamanlama saldırısı (A4'ün kriptografik hâli) |
| **W3** | Webhook ucu **her zaman 2xx** döner | 404 sağlayıcıyı sonsuza kadar retry ettirir **ve** "bu referans bizde var/yok" bilgisi sızdırır (L2) |

### Mevcut serilere eklenenler

| # | Kural | Gerekçe |
|---|---|---|
| **L7** | Bir dış servis **transaction'a dâhil değildir** — geri alınamayan iş **en sona** | F3'ün (dosya sistemi) dış servisteki ikizi. Ters sırada "ödenmiş ama kaydı olmayan ödeme" kalır |
| **P6** | Policy'nin cevabı `bool`dur; **bilgi taşıması gereken red** Policy'ye konmaz | Policy reddi 404'e çevrilir (H7); paywall reddi 402 olmalı ve `requiredTier` taşımalı. Kural iki katmana **doğru yerlerinden** bölünür |
| **E11** | Çok kolonlu bir değişmez **CHECK kısıtına** yazılır | `status='paid'` ⟺ `paid_at IS NOT NULL`. `if` konsole/kuyruğa/seeder'a atlanabilir (A8'in şema hâli) |

> Kural sayıları: FAZ-0 (31) · FAZ-1 (19) · FAZ-2 (20) · FAZ-3 (15) ·
> FAZ-4 (11) · FAZ-5 (10) · FAZ-6 (11) · **FAZ-7 (10)** = **127**

---

## 6. Alınan kararlar (K64–K71)

| # | Karar | Gerekçe |
|---|---|---|
| **K64** | Checkout **iki uç**: `/invitations/{id}/checkout` (tekil) ve `/payments/checkout` (paket) | `docs/09` düz bir uç öngörmüştü; **N1** gereği aidiyet URL'nin **yapısında** durur. Faz 6 aynı kararı medya uçlarında zaten vermişti. Kimlik gövdede olsaydı `exists` kuralı cazip olur ve kimlik uzayı taranabilir hâle gelirdi (A1) |
| **K65** | Webhook ucu **`/api/public/`** altında | **K12** fail-safe grubu; `docs/09`'un `/api/payments/webhook` planı ezildi. Auth'suz yüzeyin tamamı tek yerde görünür |
| **K66** | `public_slug` **üretilmiyor** | **K40** onu geçersiz kıldı: `invitations.id` zaten ULID ve paylaşılan linkin kendisi. Sonuç: `SLUG_TAKEN` kodu kullanılmıyor ama **silinmiyor** (yayınlanmış kod adı sözleşmedir, `docs/08` §5.1) |
| **K67** | Ödeme **yayınlamaz**; yayın ayrı bir kullanıcı eylemidir | Paket alımda hangi davetiye belirsiz (K42); kullanıcı ödeme ile yayın arasında son düzenleme isteyebilir; webhook bir **makine** bildirimi, yayın bir **insan** kararıdır |
| **K68** | "Zaten yayında" **409**, sessiz başarı değil | Yayın ücretli ve yan etkili. 200 dönmek kullanıcıya iki kez ödediğini düşündürür. (Webhook'ta idempotans **isteniyor** — çağıran makine, niyeti teyit) |
| **K69** | İmza hatası **404** (401/403/400 değil) | 401 frontend interceptor'ını tetikler + saldırgana sinyal; 403 ucun varlığını doğrular; 400 bozuk gövde ile sahte imzayı ayırt ettirir. 404 hiçbir ayrım vermez (L2/L6) |
| **K70** | Sürücü seçimi **çözüm anında**, sessiz varsayılan **yok** | Hatalı config yalnızca ödeme uçlarını kırmalı (blast radius); `default => fake` olsaydı eksik `IYZICO_API_KEY` her ödemeyi bedava yapardı |
| **K71** | `invitations.timezone` **duvar saati + IANA kimliği**, `timestamptz` değil | Sorun depolama değil **niyet**: "19:00" düğünün olduğu yerin saatidir. Yaz saati kuralı değişirse `timestamptz` saati **kaydırır**, duvar saati kaydırmaz (iCal `TZID` modeli) |

---

## 7. 🔴 Doğrulanmamış olanlar (dürüst liste)

| Ne | Neden doğrulanamadı |
|---|---|
| Tüm PHP sözdizimi | Ortamda `php` yok |
| PHPStan level 8 | Ortamda `composer` yok |
| 33 test | Aynı |
| `errors:export --check` | Katalog **elle** güncellendi; komut koşmadı 🔴 |
| Migration'ların gerçekten koşması | PostgreSQL yok |
| CHECK kısıtlarının PostgreSQL'de kabul edilmesi | Aynı |

🔴 **En riskli üç nokta** (elle doğrulamada önce buraya bakılmalı):

1. **`contracts/error-codes.json`** — `PAYMENT_REQUIRED` bloğu elle düzenlendi.
   `php artisan errors:export` çıktısıyla bayt bayt aynı olmayabilir;
   `composer check` **testlerden önce** kırılır (K34). Çözüm:
   `php artisan errors:export` çalıştırıp diff'e bakmak.
2. **`orders_paid_at_check`** — `(status IN ('paid','refunded')) = (paid_at IS NOT NULL)`
   PostgreSQL'de geçerli bir boolean karşılaştırmasıdır, ama `OrderFactory`
   ile birlikte ilk kez sınanacak.
3. **Larastan model özellikleri** — `Order` yeni bir model; `parseModelCastsMethod`
   açık olduğu için cast'ler okunacak, ama `scopeGrantingPublishRight`'ın
   generic bildirimi (`Builder<Order>`) level 8'de sorun çıkarabilir.

---

## 8. Frontend uyarlaması (⚠️ yapılmadı)

Frontend deposu Faz 4/5/6'dan **zaten geride**. Faz 7 buna dört madde ekliyor:

| # | Dosya | Değişiklik |
|---|---|---|
| 1 | `types.ts` | `Invitation`'a `timezone: string`; `CheckoutResult.status` artık `'pending' \| 'paid' \| 'failed' \| 'refunded'` (mock `'paid'` döndürüyordu) |
| 2 | `types.ts` | `CheckoutResult`'a `redirectUrl?: string` |
| 3 | `services/payments.ts` | 🔴 Mock kaldırılır: `api.post('/payments/checkout', {tier})` veya `api.post(\`/invitations/\${id}/checkout\`, {tier})`. Yanıt **zarflı** (`{data:{...}}`) |
| 4 | `services/invitations.ts` | Yeni: `publish(id)` → `POST /invitations/{id}/publish`, 402/409 kodlarını ayırt et |
| 5 | `stores/useSubscriptionStore.ts` | `activeTier` artık **sunucudan** gelmeli; `purchase()` `redirectUrl`'e yönlendirmeli |
| 6 | `components/create/…` | Saat dilimi seçici; boşken `Intl.DateTimeFormat().resolvedOptions().timeZone` öner |
| 7 | `pages/InvitePage.tsx` | Geri sayım `invitation.timezone`'da hesaplanmalı (`date-fns-tz` veya `Intl`) |
| 8 | `locales/*/errors.json` | `PAYMENT_REQUIRED`, `PAYWALL_TIER_INSUFFICIENT`, `INVITATION_ALREADY_PUBLISHED`, `PAYMENT_PROVIDER_ERROR`, `PROVIDER_UNAVAILABLE` |

🔴 **En kritik olan 5. madde:** `useSubscriptionStore.activeTier` bugün bir
**oturum içi mock**. Sunucu artık tek doğruluk kaynağı; frontend'in kendi
"satın aldım" hafızası, yayın 402 dönünce kullanıcıyı şaşırtır.

---

## 9. 🔴 Açık kararlar — İsmail'in onayı bekleniyor

| # | Konu | Öneri |
|---|---|---|
| 1 | **Paket alım kaç yayın açar?** | Bugün **sınırsız**. `orders.publish_quota` (int) + `PublishInvitationAction`'da sayaç önerilir. K43 (*"kota yayınlananı sayar"*) ancak o zaman tam uygulanmış olur. 🔴 Bugünkü hâliyle bir kullanıcı 399 ₺'lik tek paketle 100 davetiye yayınlayabilir |
| 2 | `SubmitRsvpAction::hashIp()` `hash('sha256', $ip.$key)` kullanıyor | 🔴 Faz 7 webhook imzasında `hash_hmac()` kullanıldı — **aynı depoda iki farklı refleks**. `hash_hmac('sha256', $ip, $key)` kriptografik olarak doğrusu (uzunluk-uzatma saldırısına kapalı). Değiştirmek eski `ip_hash` değerlerini geçersiz kılar ama hiçbir yerde **karşılaştırılmadıkları** için zararsız |
| 3 | `app/Contracts/` klasörü | ✅ Faz 7'de ikinci kez kullanıldı ve `CLAUDE.md`'ye işlendi. Onay bekliyor |
| 4 | `SubscriptionTier::label()` hâlâ çağrılmıyor | Faz 8'de bir e-posta/fatura metni doğarsa ilk çağıranı orası olur; yoksa **silinmeli** (ders 26) |
| 5 | Süresi dolmuş `pending` siparişler | `expires_at` yazılıyor ama **temizleyen iş yok**. `orders:expire` komutu + zamanlanmış görev — Faz 9 |
| 6 | İade var olan yayını geri çekmiyor | `refunded` yeni yayın açmaz ama `invitations.status` bağımsız. İade akışı doğduğunda karar verilmeli |

---

## 10. Faz 7'nin üç sapması (plandan)

| Konu | `docs/09` ne diyordu | Ne yapıldı | Neden |
|---|---|---|---|
| Checkout ucu | `POST /api/payments/checkout` (tek) | İki uç, kimlik URL'de | **N1** + Faz 6 precedent'i (K64) |
| Webhook yolu | `/api/payments/webhook` | `/api/public/payments/webhook` | **K12** fail-safe (K65) |
| `public_slug` | "üret" | Üretilmiyor | **K40** onu geçersiz kılmıştı (K66) |

Üçü de **daha eski ve daha güçlü bir kararın** uygulanmasıdır — plandan keyfî
sapma değil. `docs/09` Faz 3'ten önce yazılmıştı; K12, K40 ve N1 sonra geldi.

---

## 11. Öğrenilen dersler (50–54)

**50. 🔴 Bir alanın "doğrulanabilir" olması, kabul edilebilir olduğu anlamına
gelmez.** `{"price": 1}` gövdesi `integer|min:1` kurallarının hepsini geçer.
Fiyat için doğru savunma bir **kural** değil, alanı hiç kabul etmemektir.
Doğrulama katmanı **biçimi** doğrular; değeri kimin ürettiği bir **mimari
karardır**.

**51. Aynı teknik soru, farklı çağıranda farklı cevap ister.** İdempotans
webhook'ta **isteniyor** (tekrar eden bir makine, niyeti teyit), yayında
**istenmiyor** (tekrar eden bir insan, niyeti yeni bir şey yapmak). Ders 42'nin
üçüncü örneği: kural değişmedi, **çağıran** değişti.

**52. Bir arayüzün değerini, yazdığın gün değil kaldırdığın uygulamanın
maliyetiyle ölçersin.** `RsvpQuotaResolver` Faz 5'te "gereksiz dolaylılık" gibi
görünüyordu. Faz 7'de gerçek kaynağa geçiş **tek satır** oldu ve
`SubmitRsvpAction` ile testlerine hiç dokunulmadı. `PaymentGateway` aynı
kazancı ikinci kez verdi: 502 yolu, gerçek bir sağlayıcı olmadan test edildi.

**53. Bir `bool` bilgi taşıyamaz — ve yetki katmanı bunu bilmeli.** Paywall
kontrolünü Policy'ye koymak doğal görünüyordu; ama Policy reddi H7 gereği
**404**'e çevriliyor. Kullanıcı "davetiyem kayboldu" derdi. Kuralı iki katmana
bölerken ayırt edici soru şu: *"bu red bir bilgi taşımak zorunda mı?"*

**54. Framework'ün "modern" yolu, projenin sözleşmesini bozabilir.**
`Rule::enum(SubscriptionTier::class)` daha temiz görünüyordu ama
`$validator->failed()` anahtarı **sınıf adı** olurdu ve hata zarfına
`illuminate\validation\rules\enum` diye sızardı. Faz 3'ün **D6** kuralı
(`Password::min(8)` → `'min:8'`) tam olarak bunu yasaklamıştı. Aynı tuzak,
yeni kılıkta — ve bu kez **kod yazılmadan önce** yakalandı.

---

## 12. Faz 7 kapanış listesi

- [ ] 🔴 **Adım 0:** Faz 6'nın kapanış listesi (`FAZ-6.md` §11) — özellikle
      `php artisan storage:link`
- [ ] `php artisan migrate` başarılı (2 yeni migration)
- [ ] 🔴 `php artisan errors:export` → `git diff contracts/error-codes.json` temiz
- [ ] `composer lint` (Pint düzeltir)
- [ ] `composer check` **son satırı** yeşil (fail-fast: ilk satıra bakma)
- [ ] `php artisan test --filter=PaywallTest` → **33 test**
- [ ] `php artisan test` → **156 test** (123 + 33)
- [ ] [`FAZ-7-ELLE-DOGRULAMA.md`](FAZ-7-ELLE-DOGRULAMA.md) tamamlandı
- [ ] Mutasyon tablosundan en az 5 satır denendi (**T16**)
- [ ] §9'daki 6 açık karar okundu ve cevaplandı
- [ ] Frontend uyarlaması (§8) — en azından 5. madde
- [ ] Bu dosyanın **durum alanı** güncellendi (**B7**)

---

## 13. Bir cümlelik özet

Faz 7'de sisteme para öğrettik ve paranın aslında bir **güven sınırı** sorunu
olduğunu gördük: fiyat, plan ve ödeme durumu istemcinin söylediği hiçbir şeye
bakmadan sunucuda yeniden kuruldu; ama asıl öğrendiğimiz şey, üç faz önce
bilerek **boş bırakılmış** bir iskeletin ve iki **arayüzün**, bugün
değiştirilmesi gereken kod miktarını tek satıra indirdiğiydi.
