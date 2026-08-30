# `app/Actions/Rsvp/SubmitRsvpAction.php`

> **Kod dosyası:** `app/Actions/Rsvp/SubmitRsvpAction.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.7
> **Bu fazın kalbi.** Sistemin tek auth'suz yazma yolu buradan geçer.
> **Kardeş dosyalar:** [`../Invitation/ResolvePublicInvitationAction.md`](../Invitation/ResolvePublicInvitationAction.md) ·
> [`../Invitation/CreateInvitationAction.md`](../Invitation/CreateInvitationAction.md)

---

## 1. Neden bu dosya özel?

Sistemdeki diğer **her** yazma işleminin arkasında bir token var:

| Uç | Kim yazıyor |
|---|---|
| `POST /api/invitations` | Giriş yapmış kullanıcı |
| `PUT /api/invitations/{id}` | Davetiyenin sahibi |
| `POST /api/auth/register` | Yeni kullanıcı (ama yalnızca kendi kaydını) |
| **`POST /api/public/invitations/{id}/rsvps`** | 🔴 **Kimliği bilinmeyen herkes** |

Bir saldırgan için bu, sistemin en cazip noktası: veritabanına satır yazdırabildiği,
kimliğini kanıtlamadığı tek yer. Bu yüzden burada **tek bir kontrol yok, bir
katman dizisi var.**

---

## 2. Katmanlı savunma (defense in depth)

```
0. Hız sınırı      → rota katmanı (5.8) — Action'a hiç gelmez
1. Honeypot        → bot sessizce yutulur, VERİTABANINA HİÇ GİDİLMEZ
2. Hedef açık mı   → ResolveOpenRsvpInvitationAction  ⟵ 6.13'te ÇIKARILDI
                     ├─ yayında değil / modül kapalı → 404
                     └─ son tarih geçti              → 403
3. Kota            → dolduysa 403 (kilitli transaction içinde)
4. KVKK            → ham IP yerine hash
```

> 🔴 **6.13 (Faz 6) değişikliği:** eski 2. ve 3. katmanlar (görünürlük, modül,
> son tarih) bu dosyadan çıkarılıp
> [`ResolveOpenRsvpInvitationAction`](ResolveOpenRsvpInvitationAction.md)'a
> taşındı. Sebep: misafirin **medya yükleme** ucu tam olarak aynı üç koşulu
> istiyor ve aynı kural iki yerde duramaz (**C3**). Davranış birebir aynı
> kaldı — kanıtı `RsvpTest`'in 29 testi. Ayrıntı: §4.

**"Defense in depth" ne demek?** Hiçbir katman tek başına yeterli değildir;
her biri diğerinin kaçırdığını yakalar:

- Hız sınırı, kotayı **hızla** doldurmayı engeller — ama yavaş bir saldırgan
  yine doldurur. Onu kota durdurur.
- Honeypot, basit botları eler — ama gelişmiş bir bot alanı boş bırakır. Onu hız
  sınırı yavaşlatır.
- Kota, toplam hacmi sınırlar — ama son tarih geçtikten sonraki gönderimlere
  bakmaz. Onu son tarih kontrolü durdurur.

🔴 **Sıra tesadüfi değil: en ucuz kontrol en başta.** Honeypot bir `if`; kota
bir `SELECT SUM(...)` + satır kilidi. Bot trafiği ezici çoğunluktaysa —ki
öyledir— onları tek bir sorgu bile açtırmadan elemek, hız sınırının yükünü de
azaltır.

---

## 3. Katman 1 — Honeypot: sessizliğin savunma olduğu yer

```php
if ($honeypotTripped) {
    return $this->silentlyDiscard($attributes);
}
```

Alan doluysa gönderen bot demektir (nedeni 5.4'te). Ne yapıyoruz? **Hiçbir
şey** — ama bunu göstermiyoruz.

```php
private function silentlyDiscard(array $attributes): Rsvp
{
    $rsvp = new Rsvp($attributes);

    $rsvp->id = $rsvp->newUniqueId();
    $rsvp->created_at = now();
    $rsvp->updated_at = now();

    return $rsvp;      // ← save() ÇAĞRILMADI
}
```

Bu, **kaydedilmemiş bir Eloquent modelidir**. `HasUlids::newUniqueId()`
veritabanına hiç gitmeden gerçek bir ULID üretebildiği için, dönen yanıt geçerli
bir kayıttan **bit bit ayırt edilemez**:

```json
{ "data": { "id": "01k3x8...", "guestName": "Bot", "createdAt": "2026-08-28T12:00:00+00:00" } }
```

Bot "başarılı oldum" der ve gider. Bir hata kodu dönseydik, bot yazarı alanı
boş bırakmayı öğrenir ve savunma bir kez kullanılıp ölürdü.

> 🔴 **Bu tasarımın bedeli:** kaydedilmediğine dair **hiçbir çalışma anı kanıtı
> yok.** Yanıt `201`. Log yok. Metrik yok. Tek kanıt testtir — ve o test yanıtı
> değil **etkiyi** doğrulamak zorundadır (**T14**):
>
> ```php
> $this->assertDatabaseCount('rsvps', 0);
> ```
>
> `assertStatus(201)` yazan bir test bu savunma tamamen silinse de yeşil kalır.

### Neden Action karar veriyor, FormRequest değil?

`StoreRsvpRequest::isHoneypotTripped()` bir **olgu** bildirir. "Ne yapalım?"
sorusunun cevabı bir **iş kararıdır**: bugün sessizce yutuyoruz, yarın belki
`ip_hash`'i işaretleyeceğiz. İş kararları Action'da yaşar (`CLAUDE.md` §1).

### Neden sorgudan da önce?

Honeypot kontrolü davetiyeyi **çözmeden** önce yapılıyor. Yani bot geçersiz bir
ULID gönderse bile `201` alır ve tek bir `SELECT` bile açılmaz.

İki kazanç: (1) bot trafiği veritabanına hiç dokunmaz — bir DoS savunması,
(2) bot 404 ile 201 farkından davetiyenin varlığını öğrenemez.

---

## 4. Katman 2 — "Hedef açık mı?" (6.13'te bu dosyadan çıktı)

```php
$invitation = $this->resolveOpenInvitation->handle($invitationId);
```

Tek satır, üç kontrol:

| Kontrol | Sonuç |
|---|---|
| Davetiye yayında mı | değilse **404** |
| `show_rsvp` açık mı | değilse **404** (**C6**: kapalı modülün varlığı da bilgidir) |
| `rsvp_deadline` geçmiş mi | geçmişse **403** (gizlenecek bir şey yok) |

### Neden çıkarıldı?

Faz 5'te bu üçü **bu Action'ın gövdesindeydi** ve o zaman doğruydu: soruyu
soran tek bir uç vardı.

Faz 6 ikinci bir uç getirdi — misafirin LCV foto/videosunu yüklediği uç. O da
aynı üç koşulu istiyor. Kopyalasaydık iki somut sonuç doğardı:

1. 🔴 **Disk doldurma yolu.** Kopyalarken en kolay unutulan üçüncüsü: son
   tarih. Süresi dolmuş bir davetiyeye LCV gönderilemezken medya
   yüklenebilseydi, davetiye başına ~2.4 GB süresiz yükleme açılırdı.
2. **Kayma.** **P1**: *beş kopyanın dördünü doğru yazıp birini unutmak, tek
   yeri yazmaktan daha olasıdır.*

**C3**: *aynı sözleşmeyi üreten iki uç tek yerden üretir.*

> 🔴 Bu refactor sırasında `CLAUDE.md` §1'in *"controller'da `if` bulunamaz"*
> kuralı gevşetilmişti — yani üç kontrolü `PublicMediaController`'a yazmak
> **serbest** hâle gelmişti. Yine de yazılmadı, çünkü bu kararın gerekçesi
> `if` yasağı değil **C3/P1**'di. **Ders 42**: bir kuralı uygulamak,
> gerekçesini kontrol etmeden kopyalamak değildir.

### Bu bölümün eski içeriği nereye gitti?

`isPast()` tuzağı (**E8** / ders 43), `null` deadline kararı (ders 45), modül
reddinin neden 404 olduğu ve `ResolvePublicInvitationAction`'ın
`with('timelineEvents')` maliyeti (**B6**) — hepsi artık
[`ResolveOpenRsvpInvitationAction.md`](ResolveOpenRsvpInvitationAction.md)
§4-§8'de.

Burada tekrarlanmıyor: bir kılavuz da bir doğruluk kaynağıdır.

### 🔴 Refactor'ü ne korudu?

Hiçbir davranış değişmedi — ama bunu **iddia etmek** ile **bilmek** farklı.

Bu, Faz 5 koduna dokunan ilk değişiklikti ve `RsvpTest`'in 29 testi ilk kez
gerçek işlerini yaptı: *değişimi güvenli kılmak.* Testler Faz 5'te yazıldı ama
Faz 6'ya kadar **hiç koşmamıştı**; ilk koştukları hafta ilk faydalarını
verdiler.

> Bir refactor'ün tanımı budur: **davranış sabit, yapı farklı.** Testler
> yeşil kalmıyorsa yaptığın şey refactor değil, değişikliktir.

---

## 5. Katman 3 — Kota: `SUM` ve yarış koşulu

### Neden `SUM(guest_count)`, `COUNT(*)` değil?

`docs/09` §Faz 5 bunu açıkça yazmıştı:

> `LiveRsvpPanel` toplamları `reduce((s, r) => s + r.guestCount, 0)` ile
> hesaplıyor. Backend kotasını `COUNT(*)` ile kurarsak 100 kayıt × 4 kişi =
> **400 misafir** geçer.

İki taraf **aynı metriği** kullanmak zorunda. Aksi hâlde sahip panelde "400
misafir" görürken backend "100 kayıt, kota dolmadı" der.

### Hangi satırlar sayılıyor?

```php
->whereIn('status', RsvpStatus::quotaConsumingValues())
```

Liste burada **yazılmıyor**, enum'dan **soruluyor** (K50). Sorguya
`['attending','pending']` yazsaydık kota kuralı iki yere düşerdi ve C3'ün
uyardığı ayrışma başlardı.

### 🔴 Yarış koşulu ve satır kilidi

Kota mantığı özünde bir **check-then-act** kalıbıdır — Faz 2'de **E2** ile
tehlikeli ilan edilen kalıbın ta kendisi:

```
İstek A: SUM oku → 98    İstek B: SUM oku → 98
İstek A: 98+2 ≤ 100 ✅    İstek B: 98+2 ≤ 100 ✅
İstek A: yaz → 100        İstek B: yaz → 102     ← KOTA AŞILDI
```

E2 *"benzersizlik veritabanı kısıtıyla korunur"* diyordu — ama kota bir
benzersizlik değil bir **toplam**; `UNIQUE` ile ifade edilemez. Bu yüzden
klasik çözüm kullanılıyor:

```php
DB::transaction(function () { ... });                       // atomiklik
Invitation::query()->whereKey(...)->lockForUpdate()->first();  // seri hâle getirme
```

`lockForUpdate()` `SELECT ... FOR UPDATE` üretir: davetiyenin **satırını**
kilitler. Aynı davetiyeye gelen ikinci gönderim, birincinin `COMMIT`'ini
bekler; beklemesi bittiğinde `SUM` artık **100** görür ve reddedilir.

**Neden davetiye satırı kilitleniyor da `rsvps` satırları değil?**
PostgreSQL'in varsayılan `READ COMMITTED` izolasyonunda var olmayan satırlar
kilitlenemez — B'nin ekleyeceği satır A'nın `SUM`'ı sırasında **henüz yok**
(*phantom read*). Kilitlenebilecek tek ortak nesne **üst kayıttır**.

> **B6 — bu savunmanın bedeli:** kilit süresince o davetiyenin satırı
> yazma için meşguldür; sahibin autosave'i o anda denk gelirse birkaç
> milisaniye bekler. Kabul edilebilir: kilit yalnızca kotalı planlarda ve
> yalnızca transaction boyunca tutulur.

### Sınırsız planda sorgu bile açılmıyor

```php
if ($limit === null) {
    return;
}
```

`null` sınırsız demekti (5.6). Gold/Elit planlarda `SUM` de kilit de yok — hem
daha hızlı hem de sahibin autosave'ini hiç bloklamıyor.

---

## 6. Katman 4 — KVKK: `ip_hash`

```php
$rsvp->ip_hash = $this->hashIp($ip);
...
private function hashIp(string $ip): string
{
    return hash('sha256', $ip.Config::string('app.key'));
}
```

Neden ham IP saklanmadığı ve `APP_KEY`'in neden karışıma girdiği 5.2'nin
kılavuzunda ayrıntılı yazılı (§6). Burada iki kod detayı:

**1. Atama neden `make()` + `->ip_hash =` şeklinde?**

`ip_hash` `#[Fillable]` listesinde **yok**, dolayısıyla toplu atamayla
yazılamaz. Doğrudan atama toplu atama korumasını *aşmaz* — ona hiç uğramaz.
Faz 4'te `CreateInvitationAction`'da `status` için kurulan **E7** deseninin
aynısı: *sunucunun sahip olduğu alanın değerini sunucu kodu söyler.*

**2. `Config::string('app.key')` — `env('APP_KEY')` değil**

**Y1**: kod içinde `env()` çağrılmaz. `config:cache` sonrası `env()` sessizce
`null` döner — ve `null` dönerse hash'imiz **pepper'sız** kalırdı. Yani bu kural
burada doğrudan bir güvenlik özelliğini koruyor.

> 💡 **İyileştirme önerisi (İsmail'in onayına açık):** `hash('sha256', $ip.$key)`
> yerine `hash_hmac('sha256', $ip, $key)` kriptografik olarak daha doğru
> kullanımdır (anahtarlı özet için tasarlanmış primitif). Bu senaryoda pratik
> fark yok, ama `CLAUDE.md` §3 formülü `hash(ip + app_key)` diye yazdığı için
> **standarda sadık kalındı**. Değiştirmek `CLAUDE.md`'yi de güncellemeyi
> gerektirir — bu yüzden tek taraflı yapılmadı (çalışma kuralı 5).

---

## 7. Action'ın bilmediği şeyler

`CLAUDE.md` §1'in kuralları burada da geçerli:

| Action ne YAPMAZ | Kim yapar |
|---|---|
| Doğrulama | `StoreRsvpRequest` (5.4) |
| HTTP yanıtı üretme | Controller (5.9) |
| Durum kodu seçme | `ErrorCode` + `ApiExceptionRenderer` |
| Kotanın kaynağını bilme | `RsvpQuotaResolver` (5.6) |
| Hız sınırı | Rota middleware'i (5.8) |

Bunun somut kazancı: **Action HTTP'siz test edilebilir.** Kota mantığını
sınamak için istek göndermek gerekmez.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Honeypot'ta `403` dönmek | Bota yakalandığını söylersin; savunma bir kez kullanılıp ölür |
| 2 | Honeypot'u sorgudan sonra kontrol etmek | Bot trafiği veritabanını meşgul eder |
| 3 | ~~`$deadline->isPast()`~~ | 6.13'te taşındı → [`ResolveOpenRsvpInvitationAction.md`](ResolveOpenRsvpInvitationAction.md) §9 |
| 4 | Kotayı `COUNT(*)` ile ölçmek | 100 kayıt × 4 kişi = 400 misafir geçer |
| 5 | Kota kontrolünü transaction dışında yapmak | Eşzamanlı istekler kotayı birlikte aşar |
| 6 | `lockForUpdate()` koymamak | Aynı sorun; transaction tek başına yetmez (READ COMMITTED) |
| 7 | `ip_hash`'i `#[Fillable]`'a eklemek | İstemci kendi hash'ini uydurur |
| 8 | Ham IP saklamak | KVKK ihlali |
| 9 | 🔴 Üç açıklık kontrolünü buraya **geri** yazmak | Kural iki yere düşer; misafirin medya ucuyla ayrışır (**C3**) |
| 10 | ~~Modül kapalıyken `403` dönmek~~ | 6.13'te taşındı → [`ResolveOpenRsvpInvitationAction.md`](ResolveOpenRsvpInvitationAction.md) §4 |
| 11 | `env('APP_KEY')` kullanmak | `config:cache` sonrası pepper'sız hash |
| 12 | Kaydedilmemiş modeli `save()` sanmak | Honeypot testi `201` görüp yeşil yanar (T14) |

---

## 9. Kendin dene

### Mutasyon tablosu (kural 14)

Her satır: *"bu korumayı bozarsam hangi test kırılmalı?"* Kırılmıyorsa test
süs demektir.

| Bozulan | Kırılması gereken test |
|---|---|
| `if ($honeypotTripped)` bloğu silinir | `honeypot_submission_is_not_persisted` |
| `silentlyDiscard()` içine `$rsvp->save()` eklenir | aynı test (T14 sayesinde) |
| `resolveOpenInvitation->handle()` çağrısı `resolvePublic`'e döndürülür | `rsvp_is_rejected_when_module_is_closed` **ve** `rsvp_is_accepted_on_the_deadline_day` — 🔴 bu iki test 6.13 refactor'ünün **koruyucusu**; kırılırlarsa taşıma davranışı değiştirmiş demektir |
| `quotaConsumingValues()` → `values()` | `declined_rsvps_do_not_consume_quota` |
| `sum('guest_count')` → `count()` | `quota_counts_guests_not_rows` |
| `$rsvp->ip_hash = ...` silinir | veritabanı `NOT NULL` ihlali → tüm gönderim testleri |

### Elle

```powershell
# Son tarihi dün olan bir davetiye
php artisan tinker --execute="App\Models\Invitation::factory()->published()->create(['show_rsvp'=>true,'rsvp_deadline'=>now()->subDay()])->id"

# -> 403, RSVP_DEADLINE_PASSED beklenir
```

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Defense in depth** | Üst üste binen, tek başına yeterli olmayan savunma katmanları |
| **Honeypot** | Yalnızca botların dolduracağı görünmez tuzak alan |
| **Check-then-act** | "Önce sor, sonra yap" — eşzamanlılıkta hatalı kalıp |
| **Race condition** | Sıraya bağlı olarak yanlış sonuç üreten eşzamanlılık hatası |
| **Transaction** | Ya hepsi ya hiçbiri çalışan yazma bloğu |
| **`SELECT ... FOR UPDATE`** | Satırı, transaction bitene kadar yazmaya kapatan kilit |
| **READ COMMITTED** | PostgreSQL'in varsayılan izolasyon seviyesi |
| **Phantom read** | Aynı transaction'da ikinci okumada beliren yeni satırlar |
| **Pepper** | Hash'e karışan, veritabanında olmayan gizli anahtar |
| **Veri minimizasyonu** | Yalnızca gerekeni saklama ilkesi |

---

## 11. Sırada ne var?

> Bu bölüm Faz 5'te *"5.8 — `RsvpResource` ve `RsvpPolicy`"* diyordu; o adım
> tamamlandı. 6.13 refactor'ünden sonraki halka aşağıda.

**6.14 — `StoreGuestMediaAction`.** Misafirin medya yüklemesi, bu Action'ın
2. katmanını (`ResolveOpenRsvpInvitationAction`) **paylaşarak** kullanacak.
Refactor'ün amacı tam olarak oydu: aynı üç koşul, tek yerde.

Aradaki tek fark kota metriğinde: LCV `SUM(guest_count)` sayar (kaç **misafir**),
medya `COUNT(*)` sayar (kaç **dosya**). İkisi ortaklaşmadı — çünkü ortaklaşacak
bir şey yok; sınırların **tanımı** farklı (**ders 42**).

| İlgili | Nerede |
|---|---|
| Kota arayüzü | [`../../Contracts/RsvpQuotaResolver.md`](../../Contracts/RsvpQuotaResolver.md) |
| Exception'lar | [`../../Exceptions/RsvpQuotaExceededException.md`](../../Exceptions/RsvpQuotaExceededException.md) · [`../../Exceptions/RsvpDeadlinePassedException.md`](../../Exceptions/RsvpDeadlinePassedException.md) |
| 🔴 Açıklık kuralı (6.13'te buradan çıktı) | [`ResolveOpenRsvpInvitationAction.md`](ResolveOpenRsvpInvitationAction.md) |
| Görünürlük | [`../Invitation/ResolvePublicInvitationAction.md`](../Invitation/ResolvePublicInvitationAction.md) |
| Tablo | [`../../../database/migrations/2026_08_28_120000_create_rsvps_table.md`](../../../database/migrations/2026_08_28_120000_create_rsvps_table.md) |
