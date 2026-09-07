# FAZ 8 — AI Asistan ve İletişim

> **Tarih:** 7 Eylül 2026
> **Durum:** ✅ **`composer check` YEŞİL — 198 test, 560 assertion (7 Eylül 2026)**
> ⬜ **Elle doğrulama açık** ([`FAZ-8-ELLE-DOGRULAMA.md`](FAZ-8-ELLE-DOGRULAMA.md), 18 adım)
> **Önceki:** [`FAZ-7.md`](FAZ-7.md) · **Sonraki:** Faz 9 — Üretim hazırlığı
> **Bu dosya:** fazın kaydı, alınan kararlar, kurulan kurallar ve devir

---

## 0. 🔴 ÖNCE BUNU OKU — durum alanı ne diyor, ne demiyor

**`composer check` koştu ve son satırı yeşil bitti** (7 Eylül 2026):

```
Pint     ✅ 159 dosya
PHPStan  ✅ level 8 · 152 dosya · 0 hata
errors:export --check  ✅ katalog güncel (21 kod)
Tests:   ✅ 198 passed (560 assertions) · 16.58s
```

Migration'lar koştu (`assistant_usages`, `contact_messages`) ve
`php artisan errors:export` sonrası `git diff` **yalnızca `generatedAt`**
farkı gösterdi — yani elle düzenlenen katalog enum ile birebir uyumluydu.

🔴 **Bu, fazın kapandığı anlamına GELMEZ (B7).** Bir faz *çalışan bir
çıktıyla* biter ve `composer check`'in göremediği şeyler var:
gerçek Gemini sözleşmesi, `cache:clear` sonrası kotanın ayakta kalması,
honeypot'un frontend'de var olması. **Faz 8,
[`FAZ-8-ELLE-DOGRULAMA.md`](FAZ-8-ELLE-DOGRULAMA.md) (18 adım) yeşil bitene
kadar KAPANMAMIŞTIR.**

### 🔴 Faz 7 borcu — ayrı tutuldu ve kapandı

Faz 8'in **ilk** `composer check` koşusu (6 Eylül 2026, 8.1 yazılmadan
önce) Faz 7'nin **hiç koşmamış** 33 testini de çalıştırdı. Sonuç:

```
Pint  ✅ 137 dosya   ·  PHPStan level 8  ✅ 130 dosya, 0 hata
errors:export --check ✅ katalog güncel
Tests: 2 failed, 161 passed
```

> Bu koşu **bilerek** Faz 8'in ilk satırı yazılmadan önce yapıldı: amaç,
> kırmızı çıkarsa hangi fazdan geldiğini ayırt edebilmekti. Ayrım işe
> yaradı — iki kırmızının ikisi de Faz 7'den geldi.

İki kırmızı ve **ikisi de Faz 7 borcuydu, Faz 8 kodu değil**:

| Test | Kök sebep |
|---|---|
| `RsvpTest > rsvp is accepted on the deadline day` | K71 son tarihi davetiyenin saat dilimine taşıdı; testler hâlâ `now()` (UTC) ile kuruyordu |
| `MediaTest > guest can upload on the deadline day` | Aynı |

Koşu saat **21:11 UTC = 00:11 İstanbul** idi — yani iki takvim gününün
ayrıştığı 3 saatlik pencerede. Üretim kodu **doğruydu**; yanlış olan testin
varsayımıydı. Commit `8.0` zamanı dondurarak düzeltti (ders 58).

---

## 1. Faz 8 neydi?

**Amaç:** sisteme **para harcayan bir dış çağrı** ve **dördüncü auth'suz
yazma yolu** eklemek — ikisini de yeni bir mimari icat etmeden.

| Faz | Soru |
|---|---|
| 5 | Misafir **yazabiliyor** mu? |
| 6 | Misafir **dosya** yükleyebiliyor mu? |
| 7 | Davetiye nasıl yayınlanır — kim bunun bedelini ödedi? |
| **8** | **Sistem, her çağrısı para olan bir servise nasıl vekâlet eder?** |

İki bağımsız dilim:

- **A) AI asistan proxy'si** — kullanıcı sohbet eder, backend Gemini'ye
  vekâlet eder. Sır sızmaz, kota vardır, sağlayıcı çökerse sistem çökmez.
- **B) İletişim formu** — auth'suz, kayıt eden bir uç.

---

## 2. Yazılan dosyalar (21 adım)

| # | Dosya | Ne yapar |
|---|---|---|
| 8.0 | (Faz 7 borcu) `RsvpTest` · `MediaTest` | Zaman donduruldu — ders 58 |
| 8.1 | `app/Enums/ErrorCode.php` + `contracts/error-codes.json` | `ASSISTANT_QUOTA_EXCEEDED` (429) |
| 8.2 | `AiProviderException` · `AssistantQuotaExceededException` | 503 · 429 |
| 8.3 | `app/Services/Ai/AiProvider.php` | 🔴 Strategy Pattern (K8), üçüncü uygulama |
| 8.4 | `app/Services/Ai/NullProvider.php` | Null Object; ağa hiç çıkmaz |
| 8.5 | `app/Services/Ai/GeminiProvider.php` + `config/ai.php` | 🔴 Sır yönetimi + 15 sn hesabı |
| 8.6 | `AppServiceProvider` | Sürücü seçimi (K70) + 2 throttle kovası |
| 8.7 | `app/Support/IpHasher.php` · `HasHoneypot` trait | 🔴 C3: iki refleks birleşti |
| 8.8 | `..._create_assistant_usages_table` + model + factory | 🔴 Kalıcı günlük sayaç |
| 8.9 | `AskAssistantRequest` | Prompt sınırı |
| 8.10 | `AskAssistantAction` | 🔴 Kota + sağlayıcı çağrısı |
| 8.11 | `AssistantController` + rota | `POST /api/assistant/chat` (auth) |
| 8.12 | `app/Enums/ContactSubject.php` | Frontend tipiyle birebir |
| 8.13 | `..._create_contact_messages_table` + model + factory | CHECK kısıtı |
| 8.14 | `ContactRequest` + `SubmitContactAction` | Honeypot + KVKK |
| 8.15 | `PublicContactController` + rota | `POST /api/public/contact` → 204 |
| 8.16 | `tests/Feature/AssistantTest.php` | **21 test** |
| 8.17 | `tests/Feature/ContactTest.php` | **14 test** |
| 8.18–8.21 | 20 kılavuz + 2 mutasyon tablosu | K18 · T16 |

### Düzenlenen mevcut dosyalar

| Dosya | Değişiklik |
|---|---|
| `app/Enums/ErrorCode.php` | Yeni kod + `allowedParams` |
| `contracts/error-codes.json` | 20 → **21** kod |
| `config/ai.php` | 🔴 `timeout_seconds` 10 → **6**; `max_output_tokens` |
| `config/davetkart.php` | `assistant.rate_limit` · yeni `contact` bölümü |
| `app/Providers/AppServiceProvider.php` | `resolveAiProvider()` + 2 limiter |
| `app/Actions/Rsvp/SubmitRsvpAction.php` | `hashIp()` → `IpHasher::hash()` |
| `app/Http/Requests/Rsvp/StoreRsvpRequest.php` | Honeypot → trait |
| `routes/api.php` | 2 yeni uç |
| `tests/Feature/RsvpTest.php` · `MediaTest.php` | Faz 7 borcu (8.0) |

---

## 3. Çalışan uç noktalar

| Method | Path | Auth | Yanıt |
|---|---|:---:|---|
| POST | `/api/assistant/chat` | ✅ | `200` · `{data:{reply}}` · 429 · 503 |
| POST | `/api/public/contact` | — | `204` · gövde yok |

**Toplam uç sayısı: 21.**

---

## 4. 🔴 Bir asistan isteğinin geçtiği katmanlar

```
POST /api/assistant/chat
  │
  ├─ [ForceJsonResponse]           API her zaman JSON konuşur
  ├─ [throttle:api]                60/dk, IP  (grup tavanı)
  ├─ [auth:sanctum]                token yoksa 401
  ├─ [throttle:assistant]          6/dk, KULLANICI anahtarlı
  │
  └─ AssistantController
       ├─ AskAssistantRequest      uzunluk sınırı (config)
       └─ AskAssistantAction
            ├─ insertOrIgnore      satırı var et (UNIQUE'e dayanır)
            ├─ koşullu UPDATE      🔴 kontrol + yazma TEK deyimde
            │    └─ 0 satır        → 429 ASSISTANT_QUOTA_EXCEEDED
            └─ AiProvider::reply() → 🔴 PARA
                 ├─ NullProvider   sabit metin
                 └─ GeminiProvider timeout 6 sn · retry yalnız bağlantıda
                      └─ hata      → 503 PROVIDER_UNAVAILABLE (H8)
```

Dokuz katman ve **bedel dördüncü katmanda yazılıyor, beşincide harcanıyor**.

---

## 5. Kurulan kurallar (Faz 8 · 10 kural)

### Yeni seri **Q** — kota ve maliyet

| # | Kural | Gerekçe |
|---|---|---|
| **Q1** | Bir **maliyet kontrolü** ancak harcamanın bir **kimliğe** yazılabildiği yerde kurulabilir | Anonim çağrıda tek anahtar IP'dir ve IP iki yönde birden başarısızdır: CGNAT'te çok geniş (meşru kullanıcı kapıda kalır), saldırgan için çok dar (IP döndürmek ucuz) |
| **Q2** | Bir **para kontrolü cache'te durmaz** | `cache:clear`, deploy veya Redis restart bütün kotaları sıfırlar. Hız sınırı için kabul edilebilir, fatura için değil |
| **Q3** | Ücretli bir dış çağrının **bedeli çağrıdan ÖNCE** yazılır | Zaman aşımı, isteğin işlenmediği anlamına gelmez. *"Cevap alamadım"* ile *"para harcanmadı"* aynı şey değildir (ders 57) |
| **Q4** | **Yenilenen** sınır 429, **kapasite** sınırı 403 | K28'in ayırt edici sorusu: *kullanıcı bekleyerek aşabilir mi?* LCV'de hayır, günlük bütçede evet |

### Yeni seri **X** — dış model çağrısı

| # | Kural | Gerekçe |
|---|---|---|
| **X1** | **Sistem talimatı çağıranın parametresi olamaz** | Parametre olsaydı konu/güvenlik sınırlaması cagıranın kararına düşerdi; sürücünün içinde durduğunda hiçbir çağrı yolu onu zayıflatamaz (H12'nin aynı fikri) |
| **X2** | **200 yanıt, kullanılabilir yanıt demek değildir** | Güvenlik filtresine takılan istek de 200 döner, `candidates` boştur (ders 34'ün ailesi) |
| **X3** | Bir **sır URL'e konmaz** | URL'ler kopyalanır: erişim logu, proxy kaydı, APM izi, `Referer`. Aynı sır bir başlıkta taşınabiliyorsa başlıkta taşınır |

### Mevcut serilere eklenenler

| # | Kural | Gerekçe |
|---|---|---|
| **L8** | Bir savunma katmanı **cevapladığı bir soru yoksa kopyalanmaz, çıkarılır** | Anlamsız katman bakımda *"bu neden burada?"* diye silinir ve **gerekli olanı da beraberinde götürür** (iletişim formunda "hedef açık mı" ve "kota" katmanları) |
| **C8** | **Zarf istisnası büyütülmez** | C2 istisnayı ad ad tanımlar. Bir istisnayı "kolay olduğu için" büyütmek sözleşmeyi kuralsız bırakmanın ilk adımıdır |
| **B9** | Bir savunmanın **karşı tarafta karşılığı yoksa** savunma kurulmamıştır | Honeypot alanı formda render edilmiyorsa tuzak hiç kurulmamıştır; backend testi bunu göremez |

> Kural sayıları: FAZ-0 (31) · FAZ-1 (19) · FAZ-2 (20) · FAZ-3 (15) ·
> FAZ-4 (11) · FAZ-5 (10) · FAZ-6 (11) · FAZ-7 (10) · **FAZ-8 (10)** = **137**

---

## 6. Alınan kararlar (K72–K79)

| # | Karar | Gerekçe |
|---|---|---|
| **K72** | Asistan ucu **auth'lu** (`auth:sanctum`) | **Q1**. Frontend'de `AssistantWidget` her sayfada görünüyor; çelişki backend lehine çözüldü ve maliyeti frontend'e yazıldı. Ticari modelde ücretsiz katman yok — parayla ilgisi olan herkesin hesabı var |
| **K73** | Kota **veritabanında** sayılır (`assistant_usages`), `throttle` kovası **ayrıca** durur | **Q2 + L3**. Plan "kota exception'ı" diyordu; sayaç bir yerde durmalı ve o yer cache olamaz |
| **K74** | Kota aşımı **429** + yeni kod `ASSISTANT_QUOTA_EXCEEDED` | **Q4**. `RATE_LIMITED` yeniden kullanılmadı: "hızlısın" ile "hakkın bitti" aynı metni gösteremez |
| **K75** | AI sağlayıcı hatalarının tamamı **tek kod**: `PROVIDER_UNAVAILABLE` (503) | K27'nin 502/503 ayrımı **tekrarlanmadı**. Ayrım izleme alarmı içindi ve ödemede biz bir gateway'iz; burada kullanıcının önündeki eylem her iki hâlde aynı. İkinci kod bugün eklenirse ölü sözleşme maddesi olur (ders 26) |
| **K76** | İletişim ucu **`/api/public/`** altında (`/api/contact` değil) | **K12** fail-safe. Auth'suz yüzeyin tamamı tek önekte durur; ilk istisna ikincisinin gerekçesi olur. Faz 6 (N1) ve Faz 7 (K65) aynı kararı vermişti |
| **K77** | `ip_hash` **`hash_hmac`**'e birleşti; ortak yer `app/Support/IpHasher` | FAZ-7 §9 madde 2 kapandı. Düz hash uzunluk-uzatma saldırısına açıktır; `ip_hash` hiçbir yerde karşılaştırılmadığı için değişim zararsız. `app/Support/` yeni bir katman değil, **katmansızlık** işareti |
| **K78** | `ai.request.timeout_seconds` **10 → 6** | 🔴 Eski değerler 15 sn kuralını çiğniyordu: 10 + 0.2 + 10 = **20.2 sn**. Yenisi 12.2 + ~0.4 = **~12.6 sn**. Ayrıca retry **yalnızca bağlantı hatasında**: cevap veren sağlayıcıyı tekrar çağırmak parayı ikiye katlar |
| **K79** | `Jobs/SendRsvpNotification` **Faz 8'de yazılmadı** | Bir bildirim e-postası, backend'in ilk kez **insan tarafından okunacak metin** üretmesidir — K20/K21'in kapsamı dışında kalan ilk şey. Kanal, dil (`users`'ta dil kolonu yok), politika ve şablon dört ayrı **karar**; dosya değil. Bugün yazılsaydı `handle()` gövdesi yer tutucu olurdu (ders 26 / K48) |

---

## 7. Doğrulama durumu (dürüst liste)

### ✅ Doğrulandı (7 Eylül 2026)

| Ne | Nasıl |
|---|---|
| PHP sözdizimi · Pint stili | `composer lint` — 159 dosya |
| PHPStan level 8 | 152 dosya, **0 hata** |
| 35 yeni test (21 + 14) | `composer check` → 198 passed |
| 2 migration + CHECK kısıtları | `php artisan migrate` |
| Katalog senkronu | `errors:export` sonrası fark yalnızca `generatedAt` |
| 🔴 `insertOrIgnore` + koşullu `increment` | PostgreSQL'de gerçekten koştu (kota testleri) |
| 🔴 Trait sabiti (PHP 8.2+) | `RsvpTest:524` yeşil |
| 🔴 `@phpstan-require-extends` | PHPStan hata vermedi |

### ⬜ Hâlâ doğrulanmadı — `composer check` bunları GÖREMEZ

| Ne | Neden test edilemez | Nerede kapanır |
|---|---|---|
| Gerçek Gemini sözleşmesi | `Http::fake()` bizim **varsayımımızı** sınar, Google'ın yanıtını değil | Elle doğrulama Adım 11 |
| Kotanın `cache:clear`'a dayanması | Test cache'i `array`; fark görünmez | Adım 9 |
| Sağlayıcı çökerken kotanın düşülmesi (gerçek ağda) | Sahte sürücüyle sınandı | Adım 12–13 |
| Honeypot'un **formda var olması** | Backend testi frontend'i göremez (**B9**) | Frontend borcu |
| Hız sınırının **kullanıcı** anahtarlı olması | Tek testte tüm istekler aynı IP'den | Kod incelemesi (`AssistantTest.md` §6) |
| Eş zamanlı iki isteğin kotayı aşamaması | Tek süreçli PHPUnit | Koruma veritabanında (UNIQUE + atomik UPDATE) |

---

## 8. Frontend uyarlaması (⚠️ yapılmadı)

Frontend deposu Faz 4/5/6/7'den zaten geride. Faz 8 buna **beş** madde
ekliyor:

| # | Dosya | Değişiklik |
|---|---|---|
| 1 | `components/assistant/useAssistantChat.ts` | 🔴 `generateReply()` mock'u kalkar: `api.post('/assistant/chat', {message})` → `data.data.reply` |
| 2 | `components/layout/AppLayout.tsx` | 🔴 **En kritik madde.** Widget giriş yapmamış ziyaretçide render edilmemeli ya da "sohbet için giriş yap" demeli — uç **auth'lu** (K72) |
| 3 | `services/contact.ts` | 🔴 Yol `/contact` → **`/public/contact`** (K76). Ayrıca docblock'taki *"destek ekibine yönlendirilir"* ifadesi **yanlış** — bugün böyle bir kanal yok (B4) |
| 4 | `pages/ContactPage.tsx` | 🔴 **Honeypot alanı yok.** Görünmez `website` input'u eklenmezse tuzak hiç kurulmaz (**B9**) |
| 5 | `locales/*/errors.json` | `ASSISTANT_QUOTA_EXCEEDED` (+ `limit`, `retryAfter`), `PROVIDER_UNAVAILABLE` |

---

## 9. 🔴 Açık kararlar — İsmail'in onayı bekleniyor

| # | Konu | Öneri |
|---|---|---|
| 1 | **Bildirim kanalı** (K79) | Kanal (SMTP/Resend/Postmark) + dil (`users.locale` kolonu?) + politika (her LCV mi, günlük özet mi) seçilmeden `SendRsvpNotification` yazılmamalı |
| 2 | `SubscriptionTier::label()` | Faz 8 de çağıran doğurmadı. **Ders 26 gereği silinmeli** |
| 3 | Kotanın gün sınırı **UTC** | İstanbul'da 03:00'te yenileniyor. Doğru çözüm `users.timezone` kolonu; bugün eklemek okunmayan alan üretir |
| 4 | `contact_messages` için **okuma ucu** | Bugün yazan var, okuyan yok. Admin paneli/uç Faz 9'da mı? |
| 5 | Paket alım kaç yayın açar? (FAZ-7 §9) | **Hâlâ açık** — Faz 8 buna dokunmadı |
| 6 | `rsvps.id` ULID (K52) | Faz 5'ten beri onay bekliyor |

---

## 10. Faz 8'in üç sapması (plandan)

| Konu | Plan ne diyordu | Ne yapıldı | Neden |
|---|---|---|---|
| Kota sayacı | Örtük (yalnızca "kota exception'ı") | `assistant_usages` tablosu | **Q2** — cache bir fatura kontrolü tutamaz |
| `AiProvider` dönüş tipi | "DTO'lar" | `string` | Tek alanlı sarmalayıcı bugün tören (K15) |
| İletişim yolu | `/api/contact` (frontend) | `/api/public/contact` | **K12** (K76) |

Üçü de **daha eski ve daha güçlü bir kararın** uygulanması — plandan keyfî
sapma değil.

---

## 11. Öğrenilen dersler (56–59)

**56. 🔴 Bir maliyet kontrolü, kimliği olmayan bir çağrıda kurulamaz.**
Anonim asistan çağrısını sınırlayacak tek anahtar IP'dir ve IP aynı anda
hem çok geniş hem çok dardır: CGNAT arkasındaki on binlerce abone tek IP'yi
paylaşır, saldırgan için ise IP döndürmek saatlik birkaç kuruştur. Sonuç,
meşru kullanıcı için sıkı, saldırgan için gevşek bir sınır — bir güvenlik
kontrolünün olabileceği en kötü hâli. Bu yüzden çelişki, "hangi taraf daha
kolay değişir" diye değil, **kuralı hangi taraf taşıyabilir** diye çözüldü.

**57. 🔴 "Cevap alamadım" ile "para harcanmadı" aynı şey değildir.** Bir
zaman aşımı, isteğin işlenmediğini söylemez: sağlayıcı isteği almış,
üretmiş ve faturalamış olabilir. Bu yüzden bedel çağrıdan **önce** yazılır.
Sezgisel olan ters sıraydı ("çağrı başarısızsa hak yanmasın") ve yanlış
tarafa hata yapardı: hata anında para sızardı. Fail-safe yön, kullanıcının
bir mesaj kaybetmesidir.

**58. 🔴 Bir test saatin kaçında koştuğuna göre yeşil ya da kırmızı
yanıyorsa, yanlış olan testtir.** Faz 7'nin K71'i son tarih karşılaştırmasını
davetiyenin saat dilimine taşımıştı; testler hâlâ `now()` (UTC) ile
kuruyordu. Günün 21 saati yeşil, 3 saati kırmızıydı — ve ilk koşu tam o
pencereye denk geldi. Ders 34'ün zaman eksenindeki hâli: bir testin geçmesi,
**doğru sebeple** geçtiği anlamına gelmez; bazen sadece saat uygundur.

**59. Bir katmanı kopyalamak savunmayı güçlendirmez.** İletişim formuna
LCV'nin "hedef açık mı" ve "kota" katmanlarını taşımak kolaydı ve
"güvenli" görünürdü. İkisinin de cevaplayacağı bir soru yoktu (form bir
davetiyeye ait değil; kotanın metriği yok). Anlamsız bir katman bakımda
*"bu neden burada?"* diye silinir ve **gerekli olanı da beraberinde
götürür**. Doğru boyutlandırma, kopyalamak değil **çıkarmaktır** (L8).

---

## 12. Faz 8 kapanış listesi

- [x] `php artisan migrate` başarılı (2 yeni migration)
- [x] 🔴 `php artisan errors:export` → `git diff` yalnızca `generatedAt`
- [x] `composer lint` (159 dosya)
- [x] `composer check` **son satırı** yeşil
- [x] `php artisan test --filter=AssistantTest` → **21 test**
- [x] `php artisan test --filter=ContactTest` → **14 test**
- [x] `php artisan test` → **198 test** (163 + 35), 560 assertion
- [ ] [`FAZ-8-ELLE-DOGRULAMA.md`](FAZ-8-ELLE-DOGRULAMA.md) tamamlandı
- [ ] Mutasyon tablolarından en az 5 satır denendi (**T16**)
- [ ] §9'daki 6 açık karar okundu ve cevaplandı
- [ ] Frontend uyarlaması (§8) — en azından 2. ve 3. madde
- [ ] Bu dosyanın **durum alanı** güncellendi (**B7**)
- [ ] 🔴 Faz 5, 6 ve 7'nin elle doğrulama betikleri **hâlâ açık**

---

## 13. Bir cümlelik özet

Faz 8'de sisteme para harcayan bir dış çağrı öğrettik ve öğrendik ki bir
maliyet kontrolü teknik değil **kimlik** sorunudur: harcamayı yazacak bir
kimlik yoksa kota, meşru kullanıcıyı sıkan ve saldırganı hiç durdurmayan
bir süse dönüşür — ve aynı sebeple bedel, çağrıdan **sonra** değil
**önce** yazılır.
