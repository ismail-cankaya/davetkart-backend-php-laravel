# FAZ 5 — RSVP / LCV Modülü (Auth'suz Yazma Yolu)

> **Tarih:** 28 Ağustos 2026
> **Durum:** ⚠️ **KOD YAZILDI — DOĞRULANMADI**
> **Önceki:** [`FAZ-4.md`](FAZ-4.md) · **Sonraki:** Faz 6 — Media
> **Bu dosya:** fazın kronolojik kaydı, alınan kararlar, kurulan kurallar ve devir

---

## 0. 🔴 ÖNCE BUNU OKU — bu faz yeşil kapanmadı

`FAZ-0.md`'den bu yana her faz özeti *"`composer check` yeşil"* diye açılıyordu.
Bu dosya açılmıyor. Sebebi dürüstçe yazılmalı:

**Faz 5 kodu, `composer check` hiç koşturulmadan yazıldı.**

| Ortam | Durum |
|---|---|
| İkinci bilgisayar (geliştirme) | PHP ve PostgreSQL kurulu, ama `vendor/` ve `.env` yok — kurulum yarıda kaldı |
| Kodun yazıldığı çalışma alanı | PHP yok, Composer yok |
| Yardımcı konteyner | PHP 8.4 ve Composer var, **ağ kapalı** (packagist `403`) → `composer install` çalışmıyor |

Yani `pint`, `phpstan` ve `phpunit` **hiç koşmadı**. Koşan tek kontrol:
her PHP dosyası için `php -l` (yalnızca **sözdizimi**; tip veya mantık hatası
yakalamaz).

### Neden bu bir uyarı, bir dipnot değil?

Çünkü bu projenin en pahalı üç hatası tam olarak burada doğdu:

| Faz | Ne oldu |
|---|---|
| 1 | Özete *"`composer check` yeşil"* yazıldı — **komut çalıştırılmamıştı** (§7.1'de düzeltildi, B4 doğdu) |
| 3 | İki `AuthTest` 7 Ağustos'tan beri kırmızıydı; Faz 2 yeşil kapanmamıştı |
| 4 | Rota ULID kısıtı yanlıştı; **üç IDOR testi boş yeşil yanıyordu**, Faz 3 yeşil kapanmamıştı |

Ortak sebep hep aynı: *"yazıldı"* ile *"çalışıyor"* farklı durumlar.

Fark şu: **bu sefer önceden biliyoruz.** Bu yüzden durum alanına "tamamlandı"
değil "doğrulanmadı" yazıldı ve kapanış ölçütü
[`FAZ-5-ELLE-DOGRULAMA.md`](FAZ-5-ELLE-DOGRULAMA.md)'ye taşındı.

🔴 **Faz 5, o betik yeşil bitene kadar KAPANMAMIŞTIR.** Bir sonraki faza
geçilmeden önce §11'deki kapanış listesi işaretlenmelidir.

---

## 1. Faz 5 neydi?

**Amaç:** Sistemin **tek auth'suz yazma yolunu** açmak ve onu katmanlı
savunmayla korumak.

Fazların soruları birikerek ilerliyor:

| Faz | Soru |
|---|---|
| 2 | *"Sen kimsin?"* — kimlik doğrulama |
| 3 | *"Buna dokunabilir misin?"* — yetkilendirme |
| 4 | *"Kimliği bilinmeyen biri bunun neyini görebilir?"* — görünürlük ve okuma yükü |
| **5** | 🔴 *"Kimliği bilinmeyen biri buraya ne yazabilir, ve onu nasıl sınırlarız?"* |

Faz 4'te misafir yalnızca **okuyordu**. Faz 5'te **yazıyor** — ve bir token
göstermiyor.

**Öğrenme hedefleri:**

| Hedef | Nerede karşılandı |
|---|---|
| Katmanlı savunma (defense in depth) | 5.7 |
| Honeypot ve sessiz reddin ekonomisi | 5.4, 5.7 |
| Hız sınırı ile kotanın farkı | 5.7, 5.11 |
| KVKK veri minimizasyonu ve pepper'lı hash | 5.2, 5.7 |
| Toplam üzerinden kota ve yarış koşulu | 5.7 |
| Özel exception → HTTP kodu eşlemesini otomatikleştirmek | 5.5 |
| Bugün olmayan bir bağımlılığa dikiş yeri bırakmak | 5.6 |
| Tarih ile zaman damgasının farkı | 5.7 |

---

## 2. Adım adım ne yapıldı?

### 2.1 Giriş: dört karar kilitlendi

Faz 3 ve Faz 4'te olduğu gibi kod yazmadan önce kararlar tartışıldı:
**K49** (durum değerleri), **K50** (kota kapsamı), **K51** (kota kaynağı için
dikiş yeri), **K52** (birincil anahtar tipi).

K49 özel bir tartışma gerektirdi: frontend `types.ts` satır 1'de
`'Katılıyor' | 'Bekleniyor' | 'Katılamıyor'` yazıyordu — yani **gösterim metni
veri değeri olarak** kullanılmıştı. Bu K20/K21'in doğrudan ihlaliydi ve
düzeltmesi frontend'de beş dosya demekti (K35 ve K38'in aynı ailesi).

### 2.2 5.1 — `RsvpStatus`

Üç durum: `attending | pending | declined`. K38'in testi (*"bir durumu doğuran
bir olay var mı?"*) yeniden uygulandı ve bu kez **"kalsın"** çıktı: `RsvpModal`
misafire üç seçenek sunuyor, `pending` bilinçli bir kullanıcı seçimi.

`docs/07`'deki *"`label()` Türkçe"* notu **uygulanmadı** — o satır K21'den önce
yazılmış ve K21 tarafından geçersiz kılınmıştı. Yazılsaydı hiç çağrılmayan bir
metot olurdu (ders 26).

Kota kuralı (**K50**) enum'un içine kondu: `consumesQuota()`. `match`'te
`default` **yok** — dördüncü bir durum eklendiği gün PHP karar vermeye zorlar.

### 2.3 5.2 — `rsvps` migration

Üç karar burada uygulandı:

- **ULID birincil anahtar** (K52): kimlik `DELETE /api/rsvps/{id}` ile URL'de
  geçiyor, yani K40'ın kuralı doğrudan uygulanır.
- **İki CHECK kısıtı**: `status` (enum'dan türetilmiş) ve `guest_count >= 1`.
  İkincisi şart, çünkü 🔴 **PostgreSQL'de `UNSIGNED` yoktur** —
  `unsignedSmallInteger` düz `smallint`e düşer ve `-5` kabul ederdi; negatif
  misafir sayısı kotayı **aşağı** çekerdi.
- `photo_url`/`video_url` **açılmadı**: medya Faz 6, bugün açılırsa bir faz
  boyunca yazanı olmayan kolon olur (ders 26).

### 2.4 5.3 — `Rsvp` modeli

`#[Fillable]` listesinden `invitation_id` ve `ip_hash` **bilerek** çıkarıldı.
`status` ise **içeride** — ve bu, `Invitation::status`'ün tam tersi.

Çelişki değil: *aynı kolon adı aynı kural anlamına gelmez.* Davetiyede durum
sunucunun malıdır (yayın akışı belirler); LCV'de durum **misafirin verdiği
cevabın kendisidir**.

### 2.5 5.4 — `StoreRsvpRequest` ve honeypot

Gövde **düz** bırakıldı (`{ invitation: {...} }` sarmalı yok). O sarmal Faz
3'te `status`'ü `validated()`'ten uzak tutan yapısal bir sınırdı; burada
`status` zaten meşru girdi.

Honeypot alanına **doğrulama kuralı konmadı** — `prohibited` yazsaydık `422`
döner ve bota *"yakalandın"* derdik.

**D6** yeniden uygulandı: `Rule::enum()` yerine `in:` kuralı, çünkü kural
nesnesi hataya **sınıf adıyla** raporlanır.

### 2.6 5.5 — `HasErrorCode` ve iki exception

`FAZ-4.md` §9.2'de *"üçüncü exception gelince"* diye ertelenen iş yapıldı.
Artık exception kendi kodunu kendisi söylüyor; `ApiExceptionRenderer` tek kol
taşıyor.

İki eski auth exception'ı da arayüze taşındı — aynı işi yapan iki mekanizmayı
yan yana bırakmak **C3**'ün uyardığı şey.

🔴 `RsvpQuotaExceededException`'ın kurucusu **parametre almıyor**:
`remaining`/`limit` yalnızca sahibe verilebilir (H9) ve bugünkü tek fırlatma
yeri anonim misafir ucu. Kural yorumla değil **sınıfın şekliyle** korunuyor —
`InvalidCredentialsException`'daki aynı desen (A2).

### 2.7 5.6 — `RsvpQuotaResolver` dikiş yeri

Kotanın gerçek kaynağı Faz 7'de doğacak (K42), ama Faz 5 kotayı bugün uygulamak
zorunda. Araya bir arayüz kondu (**K51**): Faz 7'de değişecek tek satır
`AppServiceProvider`'daki bağlama olacak.

Geçici uygulama her davetiyeyi **en dar plandan** sayıyor. Yön bilinçli:
"sınırsız" varsayılsaydı kota kodu Faz 7'ye kadar **bir gün bile çalışmazdı**
(ders 34) ve K47'nin engellediği paywall'sız yol açılırdı.

### 2.8 5.7 — `SubmitRsvpAction` (fazın kalbi)

Beş katman, **en ucuzdan pahalıya** sıralandı:

```
honeypot → görünürlük → son tarih → kota → KVKK hash
```

Honeypot **en başta ve sorgudan önce**: bot ne satır yazdırır ne sorgu
açtırır, ama geçerli bir kayıttan ayırt edilemeyen `201` alır. Bu, kaydedilmemiş
bir Eloquent modeliyle mümkün — `HasUlids` kimliği veritabanına gitmeden
üretebiliyor.

İki ince nokta:

1. 🔴 **`isPast()` yazılamaz.** `rsvp_deadline` bir `date`'tir ve `00:00`'a denk
   gelir; `isPast()` **son gün boyunca** herkesi reddederdi. Karşılaştırma günün
   başlangıcıyla yapılıyor.
2. 🔴 **Kota bir check-then-act kalıbıdır** (E2'nin yasakladığı şey). Ama kota
   bir toplamdır, `UNIQUE` ile ifade edilemez. Bu yüzden kontrol ve yazma aynı
   transaction'da, üst kayıt satırı `lockForUpdate()` ile kilitli.

### 2.9 5.8-5.9 — Resource ve Policy

`RsvpResource` `ip_hash`'i **hiç** göstermiyor — sahibe bile. Kişisel veriyi
saklamamaya karar vermek yetmez, **türevini de yaymayacaksın** (KVKK amaç
sınırlaması).

`RsvpPolicy` sahiplik kuralını **kopyalamıyor**, `InvitationPolicy`'ye
devrediyor (**P1**). Bir LCV yanıtı kimseye ait değildir; bağlı olduğu davetiye
birine aittir.

### 2.10 5.10-5.11 — Controller'lar, rotalar, hız sınırı

LCV yanıtı **iç içe kaynak** olarak konumlandı
(`/public/invitations/{invitation}/rsvps`). Düz bir `/rsvps` ucunda aidiyet
gövdeden gelirdi — yani istemcinin sözüne kalırdı.

`throttle:rsvp` **yalnızca POST'ta**: okuma ucu 15 saniyede bir çağrılıyor ve
dakikada 10'luk kovada boğulurdu.

Ayrıca `throttleApi()` eklendi — **FAZ-4 §9.2'nin açık borcu**.

### 2.11 5.12-5.13 — Fabrika ve testler

29 test. Kota testinin sayıları `COUNT(*)` mutasyonunu **öldürecek** şekilde
seçildi: `4 + 2 = 6 > 5` reddedilir, ama `COUNT(*)` ile `1 + 1 = 2 ≤ 5` geçerdi.

Kılavuza **18 satırlık mutasyon tablosu** yazıldı ve bu bir öneri değil, kabul
ölçütü.

### 2.12 5.14 — PHPStan 6 → 8

K22 takvimi. 🔴 **Ayrı commit** — çünkü doğrulanamadı ve `composer check`
fail-fast: patlarsa testler hiç koşmaz. `git revert` ile yalnızca bu adım geri
alınabilir.

---

## 3. Yazılan dosyalar

### 3.1 Yeni (13 kod dosyası + kılavuzları)

| # | Dosya | Ne yapar |
|---|---|---|
| 5.1 | `app/Enums/RsvpStatus.php` | `attending\|pending\|declined`, `consumesQuota()` |
| 5.2 | `..._create_rsvps_table.php` | ULID PK, iki CHECK, `ip_hash`, bileşik indeks |
| 5.3 | `app/Models/Rsvp.php` | Beyaz liste, cast'ler, `belongsTo` |
| 5.4 | `Requests/Rsvp/StoreRsvpRequest.php` | Doğrulama + honeypot tespiti |
| 5.5a | `Exceptions/HasErrorCode.php` | 🆕 arayüz — H11'i tip sistemine bağlar |
| 5.5b | `Exceptions/RsvpDeadlinePassedException.php` | 403 |
| 5.5c | `Exceptions/RsvpQuotaExceededException.php` | 403, parametresiz kurucu |
| 5.6a | `Contracts/RsvpQuotaResolver.php` | Kota kaynağı dikiş yeri |
| 5.6b | `Services/Rsvp/TierRsvpQuotaResolver.php` | Geçici config tabanlı uygulama |
| 5.7 | `Actions/Rsvp/SubmitRsvpAction.php` | 🔴 Katmanlı savunma |
| 5.8 | `Resources/RsvpResource.php` | Sözleşme; `ip_hash` yok |
| 5.9 | `Policies/RsvpPolicy.php` | Yetki devri |
| 5.10a | `Controllers/Api/V1/PublicRsvpController.php` | Misafirin gönderimi |
| 5.10b | `Controllers/Api/V1/RsvpController.php` | Sahibin listesi + silme |
| 5.12 | `database/factories/RsvpFactory.php` | Test verisi |
| 5.13 | `tests/Feature/RsvpTest.php` | **29 test** |

### 3.2 Düzenlenenler

| Dosya | Değişiklik |
|---|---|
| `app/Models/Invitation.php` | `rsvps()` ilişkisi |
| `app/Exceptions/ApiExceptionRenderer.php` | `HasErrorCode` kolu; iki eski kol kaldırıldı |
| `app/Exceptions/RegistrationFailedException.php` · `InvalidCredentialsException.php` | Arayüze taşındı |
| `app/Providers/AppServiceProvider.php` | Konteyner bağlaması + `rsvp` ve `api` limiter'ları |
| `bootstrap/app.php` | `throttleApi()` |
| `routes/api.php` | Üç yeni rota |
| `database/seeders/DatabaseSeeder.php` | LCV demo verisi |
| `phpstan.neon` | level 6 → **8** (K22) |

---

## 4. Alınan kararlar

| # | Karar | Gerekçe |
|---|---|---|
| **K49** | `RsvpStatus` = `attending \| pending \| declined`, `label()` **yok** | Gösterim metni veri değeri olamaz (K21). `label()` hiç çağrılmayacak bir metot olurdu |
| **K50** | Kota `attending + pending` toplamını sayar; `declined` saymaz | Kota bir **kapasite** sınırıdır (K28). Gelmeyeceğini bildiren misafir masada yer kaplamaz |
| **K51** | Kota limiti bir **arayüz** arkasından okunur | Gerçek kaynak Faz 7'de (K42); arayüz olmasaydı o gün Action'ın **içi** değişirdi |
| **K52** | `rsvps.id` = **ULID** | Kimlik URL'de geçiyor (`DELETE /api/rsvps/{id}`) → K40'ın kuralı |
| **K53** | `Jobs/SendRsvpNotification` **Faz 5'te yazılmadı** | Bildirimin gideceği bir kanal hiçbir fazda tasarlanmamış; bugün yazılsa `handle()` gövdesi boş bir yer tutucu olurdu (K48 ile aynı gerekçe). Ayrıntı §7 |

---

## 5. Kurulan kurallar

### 5.1 Auth'suz yazma — yeni seri **L**

| # | Kural | Gerekçe |
|---|---|---|
| **L1** | Katmanlar **en ucuzdan pahalıya** sıralanır | Bot trafiği ezici çoğunluktaysa, onları tek sorgu açtırmadan elemek diğer katmanların yükünü de azaltır |
| **L2** | Bot tespiti **sessizdir**; reddin kendisi bir bilgi sızıntısıdır | Bota "yakalandın" demek, savunmanın bir kez kullanılıp ölmesidir |
| **L3** | **Hız sınırı ile kota birbirinin yerine geçmez** | Biri *sıklığa*, diğeri *hacme* bakar. Saatte 60 istekle günlerce gönderen biri 100'lük kotayı yine doldurur |
| **L4** | Kişisel veri hash'lenerek saklanır ve **türevi de yayılmaz** | `ip_hash` sahibe bile gösterilmez; göstermek, toplamadığımızı iddia ettiğimiz bilgiyi dolaylı vermektir |

### 5.2 Mevcut serilere eklenenler

| # | Kural | Gerekçe |
|---|---|---|
| **E8** | `date` kolonu, zaman damgası metotlarıyla sorgulanmaz | `isPast()` bir tarih kolonunda **bir gün kaydırır**; son gün boyunca herkes reddedilir |
| **E9** | **Toplam** üzerinden kurulan sınır `UNIQUE` ile korunamaz; kontrol + yazma tek transaction ve **üst kayıt kilidi** ister | E2 check-then-act'i yasaklamıştı ama çözümü benzersizlikti; toplamlar için çözüm kilittir |
| **C7** | Sözleşmede **zorunlu** alan her zaman gider; **opsiyonel** alan yoksa hiç gitmez | `null` göndermek `string \| undefined` sözleşmesini kırar (C6'nın küçük ölçekli hâli) |
| **P5** | Alt kaynağın yetkisi **üst kaynağın** policy'sine devredilir | Sahiplik kuralı hâlâ tek yerde kalır (P1) |
| **T16** | **Mutasyon tablosu** faz kapanış ölçütüdür | Faz 4'te üç IDOR testinin boş yeşil yandığı ancak bir sonraki faz koda dokunduğunda anlaşıldı |
| **B7** | Faz özetindeki **durum alanı**, gerçekten koşan bir komuta dayanır | Faz 1 §7.1: *"bir faz özetine 'doğrulandı' yazmak, doğrulamanın kendisi değildir"* |

> **Kuralların tam listesi:** FAZ-0 §4 (31) · FAZ-1 §4 (19) · FAZ-2 §4 (20) ·
> FAZ-3 §5 (15) · FAZ-4 §5 (11) · **FAZ-5 §5 (10)**

---

## 6. Öğrenilen dersler

**42. 🔴 Bir kuralı uygulamak, gerekçesini kontrol etmeden kopyalamak
değildir.** Bu fazda üç kez oldu:

| Kural | Faz 3-4'teki sonuç | Faz 5'teki sonuç |
|---|---|---|
| K38 — "durumu doğuran olay yok ise atılır" | `draft` **atıldı** | `pending` **kaldı** (misafir bilinçli seçiyor) |
| H7 — "sahiplik yoksa 404" | Reddedilen erişim **404** | Son tarih reddi **403** (gizlenecek bir şey yok) |
| C4 — "ayrı okuyucu, ayrı Resource" | İki Resource **ayrıldı** | Tek Resource **yeterli** (misafir kendi verisini alıyor) |

Kural değişmedi; girdi değişti. Sonucu taşımak, kuralı taşımak değildir.

**43. Tarih ile zaman damgası farklı tiplerdir.** `date` kolonu günün
`00:00`'ına denk gelir; `isPast()` onu son gün boyunca "geçmiş" gösterir. Bu tür
hatalar üretimde *"bazı kullanıcılar şikâyet ediyor"* olarak görünür ve
loglarda hiçbir iz bırakmaz.

**44. Sessizlik bir savunma olabilir — ve o zaman testin yükü artar.** Honeypot
`201` döndüğü için yanıt **hiçbir şey kanıtlamaz**. `assertStatus(201)` yazan
bir test, savunma tamamen silinse de yeşil kalır. Yanıtın ayırt edilemez olduğu
her yerde T14 zorunlu hâle gelir.

**45. Bir değerin yokluğunu, o değerin uzayındaki bir sayıyla temsil etme.**
Kota için `0` (sıfır kotalı planla karışır), `-1` (sihirli sayı) ve
`PHP_INT_MAX` (sınırsızlığı sayı gibi gösterir) reddedildi; `null` seçildi.
Yan kazanç: sınırsız planda `SUM` sorgusu **hiç açılmıyor**.

**46. Geçici olanı geçici görünen bir yere koy.** `FALLBACK_TIER` config'e
konsaydı kalıcı bir özellik gibi görünür ve Faz 7'de silinmesi unutulurdu. Sınıf
sabiti olarak, sınıfla birlikte gidecek.

**47. 🔴 Doğrulanmamış bir faz kapatılamaz — ama bunu bilerek yazmak, bilmeden
yazmaktan iyidir.** Faz 1, 3 ve 4'te "yeşil" yazıldı ve değildi; bu fazda
"doğrulanmadı" yazıldı ve öyle. Birincisi bir sonraki fazda patlayan bir
sürprizdir, ikincisi bir yapılacaklar listesidir.

---

## 7. Plandan sapmalar

Kararların hepsi tartışıldı ya da **açıkça İsmail'in onayına bırakıldı**
(çalışma kuralı 5). Bu fazda benim tek taraflı karar verdiğim yerler
🟡 ile işaretlendi — **gözden geçirilmeli**.

| # | Plandaki | Yapılan | Gerekçe |
|---|---|---|---|
| 1 | `RsvpStatus` + `label()` Türkçe | `label()` **yok** | K21 o notu geçersiz kılmıştı; yazılsaydı ölü kod (ders 26) |
| 2 | Tek `RsvpQuotaExceededException` | \+ `RsvpDeadlinePassedException` \+ `HasErrorCode` arayüzü | `08` §4 zaten `RSVP_DEADLINE_PASSED` kodunu tanımlıyordu; arayüz FAZ-4 §9.2'de planlıydı |
| 3 | (yok) | 🟡 `Contracts/RsvpQuotaResolver` + `Services/Rsvp/TierRsvpQuotaResolver` | **K51** — İsmail onayladı. Klasör seçimi (`app/Contracts/`) `CLAUDE.md` §1'in küçük bir genişletmesi ve gözden geçirilmeli |
| 4 | Tek `RsvpController` | İki controller (public + owner) | `PublicInvitationController` / `InvitationController` ayrımının aynısı; K12 grubu ayrı |
| 5 | (belirtilmemiş) | 🟡 `rsvps.id` = ULID | **K52** — K40'ın kuralının doğrudan uygulaması |
| 6 | `Jobs/SendRsvpNotification` | 🔴 **YAZILMADI** | **K53** — aşağıda |
| 7 | (Faz 4 borcu) | `throttleApi()` eklendi | FAZ-4 §9.2 |
| 8 | (belirtilmemiş) | 🟡 `hash('sha256', $ip.$key)` | `CLAUDE.md` §3 formülüne **sadık kalındı**. `hash_hmac()` kriptografik olarak daha doğru kullanımdır; değiştirmek `CLAUDE.md`'yi de güncellemeyi gerektirir, bu yüzden tek taraflı yapılmadı |

### 🔴 K53 — bildirim job'u neden yazılmadı?

`docs/07` ve `docs/09` Faz 5'e `Jobs/SendRsvpNotification` yazıyor. Yazmadım ve
sebebini açıkça bırakıyorum:

**Bildirimin gideceği bir kanal hiçbir fazda tasarlanmamış.** Faz 8 ("AI asistan
ve iletişim") dosya listesinde tek bir Mailable/Notification yok; `app/Jobs/`
klasörü henüz mevcut değil. Bugün yazılsaydı `handle()` gövdesi ya boş olur ya
bir `Log::info()` çağrısı olurdu.

Bu tam olarak **ders 26**'nın tanımı ve Faz 4'te `InvitationPublished`'ın
`InvitationChanged`'e dönüşme sebebi (K48): *çağıranı olmayan kod, doğru olduğu
varsayılan koddur.*

Ayrıca çözülmemiş bir tasarım sorusu var: **e-posta metni hangi dilde?** K20/K21
"API yanıtlarında metin döndürülmez" diyor — e-posta bir API yanıtı değil, yani
K21 bunu yasaklamıyor; ama backend'in kullanıcıya gösterilecek metin üretmesi
bu projede ilk kez olacak ve bu bir **karar** gerektiriyor.

> **İsmail'e:** "yine de yazalım" dersen tek commit'lik iş. İki seçenek var:
> (a) gerçek `Mailable` + Türkçe metin (yeni bir karar: backend e-posta metni
> üretir), (b) Faz 8'e ertele ve `docs/07`/`docs/09`'da oraya taşı.
> Ben (b)'yi öneriyorum.

---

## 8. Frontend'e düşen iş

🔴 **Backend sözleşmesi değişti; frontend bugünkü hâliyle LCV modülünde
çalışmaz.** Faz 3'ün F1-F8 uyarlamasıyla aynı sınıftan bir iş.

Sıra önemli — Faz 3'ün **32. dersi**: önce `types.ts` değişir ki TypeScript
kalan işi derleme hatası olarak **listelesin**.

| # | Dosya | Değişiklik |
|---|---|---|
| F1 | `src/types.ts` | `RsvpStatus` = `'attending' \| 'pending' \| 'declined'`; `RSVPResponse.id: string` (ULID) |
| F2 | `src/data.ts` | `INITIAL_RSVP_DRAFT.status` → `'attending'` |
| F3 | `src/components/preview/RsvpModal.tsx` | Üç seçeneğin **değerleri** kod, **etiketleri** Türkçe; 🔴 **honeypot alanı eklenecek** |
| F4 | `src/components/templates/shared/RSVPForm.tsx` | Aynı; iki seçenek (`attending`/`declined`) |
| F5 | `src/components/rsvp/LiveRsvpPanel.tsx` | `r.status === 'attending'` vb.; etiketler ayrı bir eşlemeden |
| F6 | `src/services/rsvps.ts` | 🔴 Uçlar değişti: `POST /public/invitations/{id}/rsvps`, `GET /invitations/{id}/rsvps`, `DELETE /rsvps/{id}` |
| F7 | `src/stores/useRsvpStore.ts` | `fetchRsvps(invitationId)` — davetiye kimliği gerekli |

### 🔴 Honeypot alanı olmadan savunma çalışmaz

`RsvpModal.tsx` ve `RSVPForm.tsx`'e şu eklenmeli:

```tsx
<input
  type="text"
  name="website"
  tabIndex={-1}
  autoComplete="off"
  aria-hidden="true"
  style={{ position: 'absolute', left: '-9999px' }}
  value=""
  onChange={() => {}}
/>
```

Ve gönderimde `website: ''` olarak yollanmalı (boş string global
`ConvertEmptyStringsToNull` ile `null` olur, yani dürüst kullanıcı elenmez).

**Bu eksikliği hiçbir test söylemez**, çünkü backend testleri alanı kendileri
gönderiyor. `FAZ-5-ELLE-DOGRULAMA.md`'de bunun için ayrı bir adım var.

---

## 9. Hâlâ açık kalanlar

### 9.1 Faz 5'in kendi kalan işi

- 🔴 **`composer check` hiç koşmadı** — [`FAZ-5-ELLE-DOGRULAMA.md`](FAZ-5-ELLE-DOGRULAMA.md)
- 🔴 Frontend uyarlaması (§8)
- `claude/PHP-LARAVEL-SETUP.md` §7 karar tablosuna K49-K53 eklenmesi
- `claude/Notlar/03-FRONTEND-YAPILACAKLAR.md` güncellemesi

> ⚠️ Son iki madde bu fazda **yapılamadı**: `claude/` klasörü çalışılan depo
> kopyasında bulunmuyor (git'te izlenmiyor, `.gitignore`'da da değil).

### 9.2 Sonraki fazlara

| Konu | Ne zaman |
|---|---|
| 🔴 `event_at` ve `rsvp_deadline` saat dilimi | Faz 6 — `invitations.timezone` kolonu. **Faz 4'ten ikinci kez ertelendi**; artık iki alanı birden etkiliyor |
| `Jobs/SendRsvpNotification` (K53) | Faz 8 önerisi — §7 |
| `rsvps.photo_url` / `video_url` | Faz 6 (medya) |
| Eşzamanlılık (kilit) testi | Otomatik testte doğrulanamıyor (T15 ailesi); elle doğrulamada adım var |
| `hash_hmac` önerisi (§7 madde 8) | `CLAUDE.md` §3 güncellemesiyle birlikte |
| `PublishInvitationAction` boş iskeleti | Faz 7 — ya doldurulacak ya silinecek |
| `routes/web.php` closure'ı (R1/R4) | Faz 9 — `route:cache` orada kırılır |
| K20'nin frontend tarafı: `toDisplayError()` | Frontend borcu |

---

## 10. Ortaya çıkan zincir

Faz 5, zincire **iki halka** ekledi:

```
1. public/index.php
2. bootstrap/app.php
3. [global middleware]
4. Router::findRoute()            ← whereUlid: bicimsiz kimlik burada durur
5. [ForceJsonResponse]            ← M3: grubun basinda
6. [throttle:api]                 ← 🆕 5.11 · genel tavan
7. [throttle:rsvp]                ← 🆕 5.11 · yalnizca LCV gonderiminde
8. [auth:sanctum]                 ← yalnizca sahibin uclarinda
9. [SetEtag]                      ← okuma uclarinda (K46)
10. FormRequest                   ← bicim + honeypot OLGUSU
11. Controller                    ← if YOK
12. Policy                        ← sahibin uclarinda (P1/P5)
13. Action                        ← 🔴 katmanli savunma
14. Model                         ← #[Fillable] beyaz listesi
15. Resource                      ← C1 beyaz listesi
    │
    ├─ basarili ────────────────→ JSON
    └─ exception firladi
         ↓
16. bootstrap/app.php render()
17. ApiExceptionRenderer          ← 🆕 HasErrorCode kolu
18. ErrorCode                     ← status() + filterParams()
```

---

## 11. Faz 5 kapanış listesi

Bu faz ancak aşağıdakilerin **hepsi** işaretlendiğinde kapanır:

- [ ] `composer install` başarılı
- [ ] `php artisan migrate` başarılı (iki CHECK kısıtı oluştu)
- [ ] `php artisan db:seed` başarılı ve çıktısı okundu (B5)
- [ ] `composer check` **son satırı** yeşil (fail-fast: ilk satıra bakma)
- [ ] `php artisan test --filter=RsvpTest` → 29 test
- [ ] PHPStan level 8 geçti **veya** 5.14 commit'i geri alındı ve not düşüldü
- [ ] [`FAZ-5-ELLE-DOGRULAMA.md`](FAZ-5-ELLE-DOGRULAMA.md) 16 adımı tamamlandı
- [ ] Mutasyon tablosundan en az 5 satır denendi (T16)
- [ ] Frontend uyarlaması (§8) yapıldı ve misafir sayfasından gerçek LCV gönderildi
- [ ] Bu dosyanın **durum alanı** güncellendi (B7)

---

## 12. Bir cümlelik özet

Faz 5'te sistemin tek auth'suz yazma yolunu açtık ve beş katmanlı bir savunmayla
sardık; ama asıl öğrendiğimiz şey **bir kuralı uygulamanın, onun gerekçesini her
seferinde yeniden sormak** olduğu — ve **yanıtın sessiz olduğu her yerde kanıtı
testin taşımak zorunda kaldığı**ydı.
