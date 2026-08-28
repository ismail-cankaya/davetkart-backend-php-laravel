# `PHP-LARAVEL-SETUP.md` — Faz 5 eki

> **Bu bir yama dosyasıdır.** Aşağıdaki bloklar master `claude/PHP-LARAVEL-SETUP.md`
> dosyasının ilgili bölümlerine **elle** eklenecek.
>
> 🔴 Master dosya bu oturumda okunamadı (bkz. [`OKUBENI-FAZ-5.md`](OKUBENI-FAZ-5.md)),
> bu yüzden yeniden yazılmadı — görülmemiş 48 karar ve 41 ders silinirdi.

---

## A) §7 Karar tablosuna eklenecek satırlar (K49–K53)

| # | Karar | Gerekçe |
|---|---|---|
| **K49** | `RsvpStatus` = `attending \| pending \| declined`; `label()` metodu **yok** | Gösterim metni veri değeri olamaz (K21). Frontend `types.ts` satır 1'de Türkçe etiketleri değer olarak kullanıyordu; düzeltildi. `docs/07`'deki *"`label()` Türkçe"* notu K21 tarafından geçersiz kılınmıştı — yazılsaydı hiç çağrılmayan bir metot olurdu (ders 26) |
| **K50** | LCV kotası `attending + pending` toplamını sayar; `declined` saymaz | Kota bir **kapasite** sınırıdır (K28). Gelmeyeceğini bildiren misafir masada yer kaplamaz. Kural `RsvpStatus::consumesQuota()` içinde **tek yerde** |
| **K51** | Kota limiti `RsvpQuotaResolver` **arayüzü** arkasından okunur | Gerçek kaynak Faz 7'de doğacak (K42). Arayüz olmasaydı o gün `SubmitRsvpAction`'ın **içi** değişirdi; şimdi yalnızca `AppServiceProvider`'daki bağlama satırı değişecek |
| **K52** | `rsvps.id` = **ULID** | Kimlik URL'de geçiyor (`DELETE /api/rsvps/{id}`) → K40'ın kuralı doğrudan uygulanır. Artan bigint, platformdaki toplam LCV sayısını ele verirdi |
| **K53** | `Jobs/SendRsvpNotification` Faz 5'te **yazılmadı** | Bildirimin gideceği kanal hiçbir fazda tasarlanmamış (Faz 8'in dosya listesinde tek Mailable yok). Bugün yazılsa `handle()` gövdesi yer tutucu olurdu — K48 ile aynı gerekçe. **Faz 8'e önerildi**, karar İsmail'de |

---

## B) Kural listesine eklenecekler (Faz 5 · 10 kural)

### Yeni seri **L** — auth'suz yazma yolu

| # | Kural | Gerekçe |
|---|---|---|
| **L1** | Savunma katmanları **en ucuzdan pahalıya** sıralanır | Bot trafiği ezici çoğunluktaysa onları tek sorgu açtırmadan elemek, sonraki katmanların yükünü de azaltır |
| **L2** | Bot tespiti **sessizdir**; reddin kendisi bilgi sızıntısıdır | Bota "yakalandın" demek, savunmanın bir kez kullanılıp ölmesidir |
| **L3** | **Hız sınırı ile kota birbirinin yerine geçmez** | Biri *sıklığa*, diğeri *hacme* bakar |
| **L4** | Kişisel veri hash'lenerek saklanır ve **türevi de yayılmaz** | `ip_hash` sahibe bile gösterilmez (KVKK amaç sınırlaması) |

### Mevcut serilere eklenenler

| # | Kural | Gerekçe |
|---|---|---|
| **E8** | `date` kolonu, zaman damgası metotlarıyla sorgulanmaz | `isPast()` bir tarih kolonunda **bir gün kaydırır** |
| **E9** | **Toplam** üzerinden kurulan sınır `UNIQUE` ile korunamaz; kontrol + yazma tek transaction ve **üst kayıt kilidi** ister | E2 check-then-act'i yasaklamıştı ama çözümü benzersizlikti |
| **C7** | Sözleşmede **zorunlu** alan her zaman gider; **opsiyonel** alan yoksa hiç gitmez | `null` göndermek `string \| undefined` sözleşmesini kırar |
| **P5** | Alt kaynağın yetkisi **üst kaynağın** policy'sine devredilir | Sahiplik kuralı tek yerde kalır (P1) |
| **T16** | **Mutasyon tablosu** faz kapanış ölçütüdür | Faz 4'te üç IDOR testinin boş yeşil yandığı ancak sonraki faz koda dokunduğunda anlaşıldı |
| **B7** | Faz özetindeki **durum alanı**, gerçekten koşan bir komuta dayanır | Faz 1 §7.1'in kuralı: *"bir faz özetine 'doğrulandı' yazmak, doğrulamanın kendisi değildir"* |

> Kural sayıları: FAZ-0 (31) · FAZ-1 (19) · FAZ-2 (20) · FAZ-3 (15) ·
> FAZ-4 (11) · **FAZ-5 (10)** = **106**

---

## C) Ders listesine eklenecekler (42–47)

**42. 🔴 Bir kuralı uygulamak, gerekçesini kontrol etmeden kopyalamak değildir.**
Faz 5'te üç kez oldu: K38 (`draft` atılmıştı → `pending` **kaldı**), H7
(*sahiplik yoksa 404* → son tarih reddi **403**), C4 (iki Resource ayrılmıştı →
LCV'de **tek Resource yeterli**). Kural değişmedi, girdi değişti.

**43. Tarih ile zaman damgası farklı tiplerdir.** `date` kolonu günün `00:00`'ına
denk gelir; `isPast()` onu son gün boyunca "geçmiş" gösterir ve kullanıcıları
bir gün erken kapıda bırakır.

**44. Sessizlik bir savunma olabilir — ve o zaman testin yükü artar.** Honeypot
`201` döndüğü için yanıt hiçbir şey kanıtlamaz; `assertStatus(201)` yazan test
savunma tamamen silinse de yeşil kalır.

**45. Bir değerin yokluğunu, o değerin uzayındaki bir sayıyla temsil etme.**
Kota için `0`, `-1` ve `PHP_INT_MAX` reddedildi, `null` seçildi.

**46. Geçici olanı geçici görünen bir yere koy.** `FALLBACK_TIER` config'e
konsaydı kalıcı bir özellik gibi görünür ve Faz 7'de silinmesi unutulurdu.

**47. 🔴 Doğrulanmamış bir faz kapatılamaz — ama bunu bilerek yazmak, bilmeden
yazmaktan iyidir.** Faz 1, 3 ve 4'te "yeşil" yazıldı ve değildi; Faz 5'te
"doğrulanmadı" yazıldı ve öyle.

---

## D) Doküman haritasına eklenecek satırlar

| Dosya | İçerik |
|---|---|
| `docs/rehber/fazlar/FAZ-5.md` | Faz 5 kaydı — ⚠️ durum: DOĞRULANMADI |
| `docs/rehber/fazlar/FAZ-5-ELLE-DOGRULAMA.md` | 16 adımlık kapanış betiği |
| `docs/rehber/app/Enums/RsvpStatus.md` | Durum enum'u + K50 |
| `docs/rehber/app/Models/Rsvp.md` | Beyaz liste ve cast'ler |
| `docs/rehber/app/Http/Requests/Rsvp/StoreRsvpRequest.md` | Doğrulama + honeypot |
| `docs/rehber/app/Exceptions/HasErrorCode.md` | H11'i tip sistemine bağlayan arayüz |
| `docs/rehber/app/Exceptions/RsvpDeadlinePassedException.md` | 403 gerekçesi |
| `docs/rehber/app/Exceptions/RsvpQuotaExceededException.md` | K28 + parametresiz kurucu |
| `docs/rehber/app/Contracts/RsvpQuotaResolver.md` | Dikiş yeri (seam) deseni |
| `docs/rehber/app/Services/Rsvp/TierRsvpQuotaResolver.md` | Geçici uygulama |
| `docs/rehber/app/Actions/Rsvp/SubmitRsvpAction.md` | 🔴 Katmanlı savunma |
| `docs/rehber/app/Http/Resources/RsvpResource.md` | C1 + KVKK |
| `docs/rehber/app/Policies/RsvpPolicy.md` | P1/P5 devri |
| `docs/rehber/app/Http/Controllers/Api/V1/PublicRsvpController.md` | Auth'suz uç |
| `docs/rehber/app/Http/Controllers/Api/V1/RsvpController.md` | Sahibin paneli |
| `docs/rehber/database/migrations/2026_08_28_120000_create_rsvps_table.md` | İki CHECK + KVKK |
| `docs/rehber/database/factories/RsvpFactory.md` | Determinizm |
| `docs/rehber/tests/Feature/RsvpTest.md` | 🔴 18 satırlık mutasyon tablosu |

---

## E) "Teknik durum" bölümüne yazılacak

```
Faz 0-4 : ✅ tamamlandı ve doğrulandı
Faz 5   : ⚠️ KOD TAMAMLANDI (17 adım) · DOĞRULANMA BEKLİYOR
          composer check hiç koşmadı — gerekçe FAZ-5.md §0
          kapanış ölçütü: FAZ-5-ELLE-DOGRULAMA.md (16 adım)
Faz 6   : ⬜ sıradaki (Media)

Uç nokta sayısı : 13
Test sayısı     : 76 (47 + 29) — 29'u henüz koşmadı
PHPStan level   : 8 (Faz 5'te yükseltildi, doğrulanmadı)
Kural sayısı    : 106
Karar sayısı    : 53
```
