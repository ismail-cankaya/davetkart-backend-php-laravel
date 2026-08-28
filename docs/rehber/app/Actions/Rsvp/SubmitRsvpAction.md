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
2. Görünürlük      → yayında değilse / modül kapalıysa 404
3. Son tarih       → geçtiyse 403
4. Kota            → dolduysa 403 (kilitli transaction içinde)
5. KVKK            → ham IP yerine hash
```

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

## 4. Katman 2 — Görünürlük: kural neden burada tekrarlanmıyor?

```php
$invitation = $this->resolveInvitation->handle($invitationId);
```

Faz 4'te yazılan `ResolvePublicInvitationAction` yeniden kullanılıyor. Kopyala
yapıştır bir sorgu yazabilirdik:

```php
Invitation::where('status', InvitationStatus::Published)->findOrFail($id);   // ❌
```

Yazmıyoruz. **P3**'ün ruhu: *görünürlük bir `if` değil, sorgunun kapsamıdır* —
ve o kapsam **tek yerde** tanımlı olmalı. Yarın "askıya alınmış davetiye" diye
bir durum eklenirse, tek dosya değişir; iki kopya olsaydı biri unutulur ve
askıya alınmış davetiyeye LCV yazılmaya devam ederdi.

> **B6 — bu tercihin bedeli:** `ResolvePublicInvitationAction`
> `with('timelineEvents')` yapıyor, yani LCV gönderiminde işimize yaramayan bir
> ek sorgu daha açılıyor. LCV gönderimi okumaya göre çok seyrek olduğu için bu
> maliyet bilinçli olarak kabul edildi. Ölçüm bir gün aksini söylerse,
> `ResolvePublicInvitationAction`'a bir "ilişkisiz" varyant eklemek doğru
> çözümdür — sorguyu buraya kopyalamak değil.

### Modül kapalıysa neden 404?

```php
if (! $invitation->show_rsvp) {
    throw (new ModelNotFoundException)->setModel(Invitation::class, [$invitationId]);
}
```

**C6** Faz 4'te şunu kurmuştu: *kapalı modülün verisi gövdeye hiç girmez.* Yani
LCV modülü kapalıyken misafir `rsvpDeadline` alanını bile **görmedi**. Onun
dünyasında bu davetiyenin LCV'si yok.

O hâlde uç da yok: `404` tutarlı olan cevap. `403` deseydik "bu davetiyenin bir
LCV modülü var ama kapalı" bilgisini vermiş olurduk — sahibin yapılandırmasını
ifşa etmek.

`ModelNotFoundException` fırlatmak `abort(404)`'ten iyidir: **H10** gereği
Action HTTP yanıtı üretmez; `ApiExceptionRenderer` bu exception'ı zaten
`RESOURCE_NOT_FOUND`'a eşliyor (Faz 1).

---

## 5. Katman 3 — Son tarih: bir günlük hata

```php
if ($deadline->lessThan(now()->startOfDay())) {
    throw new RsvpDeadlinePassedException;
}
```

🔴 **En sık yapılan hata burada `isPast()` yazmaktır.**

`rsvp_deadline` kolonu bir **`date`**'tir; saat taşımaz. Eloquent onu
`immutable_date` olarak okuduğunda elimize günün **00:00**'ı gelir:

```
rsvp_deadline = 2026-09-01  →  CarbonImmutable('2026-09-01 00:00:00')
```

Bugün 1 Eylül, saat 14:00 olsun:

```php
$deadline->isPast();    // true!   ← 00:00 gerçekten geçti
```

Yani **son gün boyunca** herkes kapıda kalırdı. Kullanıcı "1 Eylül'e kadar"
yazdığında 1 Eylül'ü **dâhil** kastediyor.

Doğrusu: bugünün başlangıcıyla karşılaştır.

```
deadline = 2026-09-01, bugün 2026-09-01 → 00:00 < 00:00 değil → GEÇER ✅
deadline = 2026-09-01, bugün 2026-09-02 → 00:00 < 00:00 değil... 09-01 < 09-02 → REDDEDİLİR ✅
```

**Ders:** *tarih* ile *zaman damgası* farklı şeylerdir. Birini diğerinin
metotlarıyla sorgulamak bir gün kayması üretir — ve bu tür hatalar üretimde
"bazı kullanıcılar şikâyet ediyor" olarak görünür.

> ⚠️ **Açık konu (Faz 4'ten devredildi):** `now()` uygulamanın saat dilimini
> kullanır (`config('app.timezone')`, şu an `UTC`). Türkiye'deki bir misafir
> için 1 Eylül 02:00'de gün hâlâ 31 Ağustos'tur (UTC). Doğru çözüm
> `invitations.timezone` kolonudur ve `event_at` sorunuyla **aynı** çözümü
> paylaşır. `FAZ-5.md` §9'da açık madde olarak duruyor.

### `null` deadline

```php
if ($deadline === null) {
    return;
}
```

Son tarih girilmemişse sınır yoktur. **N4**'ün akrabası: *`null` ile bir değer
farklı bilgilerdir.* `null` "sahip bunu bilerek boş bıraktı" demek; "bugün"
varsaymak kullanıcının kararını uydurmak olurdu.

---

## 6. Katman 4 — Kota: `SUM` ve yarış koşulu

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

## 7. Katman 5 — KVKK: `ip_hash`

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

## 8. Action'ın bilmediği şeyler

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

## 9. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Honeypot'ta `403` dönmek | Bota yakalandığını söylersin; savunma bir kez kullanılıp ölür |
| 2 | Honeypot'u sorgudan sonra kontrol etmek | Bot trafiği veritabanını meşgul eder |
| 3 | `$deadline->isPast()` | Son gün boyunca herkes reddedilir |
| 4 | Kotayı `COUNT(*)` ile ölçmek | 100 kayıt × 4 kişi = 400 misafir geçer |
| 5 | Kota kontrolünü transaction dışında yapmak | Eşzamanlı istekler kotayı birlikte aşar |
| 6 | `lockForUpdate()` koymamak | Aynı sorun; transaction tek başına yetmez (READ COMMITTED) |
| 7 | `ip_hash`'i `#[Fillable]`'a eklemek | İstemci kendi hash'ini uydurur |
| 8 | Ham IP saklamak | KVKK ihlali |
| 9 | Görünürlük sorgusunu buraya kopyalamak | Kural iki yere düşer (P3/C3) |
| 10 | Modül kapalıyken `403` dönmek | Sahibin yapılandırması ifşa olur (C6) |
| 11 | `env('APP_KEY')` kullanmak | `config:cache` sonrası pepper'sız hash |
| 12 | Kaydedilmemiş modeli `save()` sanmak | Honeypot testi `201` görüp yeşil yanar (T14) |

---

## 10. Kendin dene

### Mutasyon tablosu (kural 14)

Her satır: *"bu korumayı bozarsam hangi test kırılmalı?"* Kırılmıyorsa test
süs demektir.

| Bozulan | Kırılması gereken test |
|---|---|
| `if ($honeypotTripped)` bloğu silinir | `honeypot_submission_is_not_persisted` |
| `silentlyDiscard()` içine `$rsvp->save()` eklenir | aynı test (T14 sayesinde) |
| `show_rsvp` kontrolü silinir | `rsvp_is_rejected_when_module_is_closed` |
| `lessThan(now()->startOfDay())` → `isPast()` | `rsvp_is_accepted_on_the_deadline_day` |
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

## 11. Terim sözlüğü

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

## 12. Sırada ne var?

**5.8 — `RsvpResource` ve `RsvpPolicy`.** Sahibin gördüğü sözleşme ve
`ip_hash`'in oraya **hiç** girmemesi (C1).

| İlgili | Nerede |
|---|---|
| Kota arayüzü | [`../../Contracts/RsvpQuotaResolver.md`](../../Contracts/RsvpQuotaResolver.md) |
| Exception'lar | [`../../Exceptions/RsvpQuotaExceededException.md`](../../Exceptions/RsvpQuotaExceededException.md) · [`../../Exceptions/RsvpDeadlinePassedException.md`](../../Exceptions/RsvpDeadlinePassedException.md) |
| Görünürlük | [`../Invitation/ResolvePublicInvitationAction.md`](../Invitation/ResolvePublicInvitationAction.md) |
| Tablo | [`../../../database/migrations/2026_08_28_120000_create_rsvps_table.md`](../../../database/migrations/2026_08_28_120000_create_rsvps_table.md) |
