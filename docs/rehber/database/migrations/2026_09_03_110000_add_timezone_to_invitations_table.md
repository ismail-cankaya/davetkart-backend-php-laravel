# `database/migrations/…_add_timezone_to_invitations_table.php`

> **Kod dosyaları:** migration · `config/davetkart.php` (`default_timezone`) ·
> `Invitation` (`#[Fillable]`) · `InvitationRequest` · `InvitationPayloadResource` ·
> `PublicInvitationResource` · `InvitationFactory` ·
> `ResolveOpenRsvpInvitationAction`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.17
> **Karar:** 🔴 **K63** — Faz 4'ten beri **üç kez** ertelendi

---

## 1. Üç fazlık borç

| Faz | Ne oldu |
|---|---|
| 4 | Sorun tespit edildi: *"`event_at` saat dilimi — geri sayım sayacı. Berlin'deki misafirde sayaç kayıyor."* → Faz 5'e |
| 5 | Ertelendi → Faz 6'ya |
| 6 | **K63**: *"Davetiye şemasına ait, medya modülüyle bağı yok"* → **Faz 7'ye** |
| **7** | ✅ Kapandı |

---

## 2. Problem

`invitations.event_at` bir **duvar saati** saklar:

```
2026-08-21 19:00
```

Hangi saat diliminde? **Söylemiyor.** Misafirin geri sayım sayacı bu değeri
kendi cihazının diliminde yorumlar:

| Misafir | Gördüğü düğün saati |
|---|---|
| İstanbul (UTC+3) | 19:00 ✅ |
| Berlin (UTC+2) | 19:00 → aslında 18:00'e sayıyor ❌ |
| Los Angeles (UTC−7) | 10 saat kayma ❌ |

Aynı sorunun ikinci yüzü LCV son tarihiydi: karşılaştırma **sunucunun**
diliminde yapılıyordu ve Faz 6'da **B6** gereği açıkça yazılmıştı:

> *"⚠️ Bu karşılaştırma SUNUCUNUN saat diliminde yapılıyor. `invitations.timezone`
> kolonu Faz 7'ye ertelendi; o güne kadar farklı saat dilimindeki misafir için
> sınır bir gün kayabilir."*

O borç bu adımda kapandı.

---

## 3. 🔴 Neden `timestamptz` değil?

PostgreSQL'in `timestamptz` tipi anı UTC'de saklar ve okurken çevirir. Doğru
çözüm o değil — çünkü bu bir **depolama** sorunu değil, bir **niyet**
sorunudur.

Kullanıcı *"düğün saat 19:00'da"* der. Bu 19:00, **düğünün olduğu yerin**
saatidir. `timestamptz`'e çevirmek için o yerin saat dilimini **zaten bilmemiz
gerekir** — yani kolon her hâlükârda gerekli.

Üstelik duvar saatini saklamak, kural değiştiğinde **doğru davranıştır**:

> Bir ülke yaz saati uygulamasını kaldırırsa, `timestamptz` olarak saklanan
> düğün **18:00'e kayar**. Duvar saati olarak saklanan düğün yine 19:00'dadır —
> ki gerçek dünyada olan da budur.

| Yaklaşım | "19:00" ne demek | Saat dilimi kuralı değişirse |
|---|---|---|
| `timestamptz` | Mutlak bir an | Gösterilen saat **kayar** ❌ |
| Duvar saati + `timezone` ✅ | Yerel takvim saati | Değişmez ✅ |

Bu ayrım takvim uygulamalarının (Google Calendar, iCal `TZID`) da kullandığı
modeldir.

---

## 4. Kolon `nullable` — `null` bir bilgidir (N4)

```php
$table->string('timezone', 64)->nullable();
```

Faz 3'ten beri var olan kayıtların saat dilimi **bilinmiyor** ve uydurmak bir
**veri yalanı** olurdu. `null`, *"sahip henüz seçmedi"* der.

Okuma tarafında iki ayrı karar veriliyor:

| Okuyucu | `null` gelince | Neden |
|---|---|---|
| `InvitationPayloadResource` (sahip) | `''` gönderir | Editör "seçilmemiş" durumunu gösterip tarayıcının dilimini önerebilir |
| `PublicInvitationResource` (misafir) | **config varsayılanı** gönderir | Misafire "bilmiyorum" demek, ona sessizce **yanlış saati** göstermekten kötüdür |

**C7** (Faz 5): misafir sürümünde alan **zorunludur** — sayaç onsuz doğru
hesaplayamaz, dolayısıyla opsiyonel olamaz.

### Varsayılan neden config'te?

```php
'default_timezone' => env('DAVETKART_DEFAULT_TIMEZONE', 'Europe/Istanbul'),
```

Bir **iş tercihidir** (**E6**): pazar değişirse (Almanya'daki Türk toplumuna
açılmak gibi) kod değişmemeli. `config('app.timezone')` kullanılmadı — o,
uygulamanın **iç** zaman dilimidir (log, kuyruk) ve `UTC` kalmalıdır; bu ise
**ürünün** varsayılanıdır. İkisini birleştirmek, altyapı ayarını bir ürün
kararına bağlardı.

---

## 5. Doğrulama: `'timezone'` kuralı

```php
'invitation.timezone' => ['sometimes', 'nullable', 'string', 'timezone', 'max:64'],
```

Laravel'in `timezone` kuralı değeri PHP'nin **kayıtlı IANA listesine** karşı
doğrular. `'TR+3'`, `'GMT+3'` gibi uydurma değerler veritabanına hiç ulaşmaz.

**D6** (Faz 3): kural **adı** sözleşmenin parçasıdır. Bu bir string kuraldır,
kural nesnesi değil — hata zarfına `{"rule": "timezone"}` diye çıkar, sınıf adı
sızmaz.

`max:64` kolonla aynı: doğrulama ile şema **aynı şeyi** söylemeli, yoksa
veritabanı hatası bir 500'e dönüşürdü.

---

## 6. 🔴 Son tarih karşılaştırması: tarih dizesi üzerinden

```php
$timezone = $invitation->timezone ?? Config::string('davetkart.default_timezone');
$today = CarbonImmutable::now($timezone)->toDateString();

if ($deadline->toDateString() < $today) {
    throw new RsvpDeadlinePassedException;
}
```

### Neden `Carbon` nesnelerini karşılaştırmıyoruz?

**Bir tarihin saat dilimi yoktur.** "21 Ağustos" her yerde 21 Ağustos'tur;
değişen şey **o anda hangi günde olunduğudur**.

`$deadline` bir `date` kolonundan geliyor ve Carbon onu `00:00`'a
yerleştiriyor. O nesneyi bir saat dilimine çevirmek (`setTimezone`) tarihi
**bir gün kaydırabilir** — tam olarak kaçınmaya çalıştığımız hata.

Doğru soru şu: *"Davetiyenin bulunduğu yerde bugün hangi gün?"* Cevap bir
**takvim günüdür** ve `Y-m-d` dizeleri sözlük sırasında karşılaştırıldığında
takvim sırasıyla aynıdır (ISO 8601'in tasarım amacı).

> **E8 hâlâ geçerli ve güçlendi:** `isPast()` yasağı Faz 5'te tarih/zaman
> damgası farkı içindi; şimdi araya bir de saat dilimi girdi. Kural değişmedi,
> **kapsamı** genişledi.

---

## 7. Frontend'e düşen (⚠️ sözleşme değişikliği)

`types.ts` → `Invitation` bugün `timezone` alanı taşımıyor. Backend her iki
Resource'ta da göndermeye başladı:

| Uç | Yeni alan | Frontend'in yapması gereken |
|---|---|---|
| `GET /api/invitations` · `{id}` | `invitation.timezone: string` (`''` olabilir) | Editöre saat dilimi seçici; boşsa `Intl.DateTimeFormat().resolvedOptions().timeZone` öner |
| `PUT /api/invitations/{id}` | `invitation.timezone` gönder | Autosave gövdesine ekle |
| `GET /api/public/invitations/{id}` | `invitation.timezone: string` (**her zaman dolu**) | 🔴 Geri sayımı bu dilimde hesapla |

Geri sayım hesabı (öneri):

```ts
// Duvar saatini davetiyenin diliminde bir ana çevir
const target = new Date(`${invitation.date}:00`);   // ⚠️ cihazın dilimini varsayar
// Doğrusu: Intl API veya date-fns-tz ile `zonedTimeToUtc(invitation.date, invitation.timezone)`
```

Ayrıntı: `FAZ-7.md` §8.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `timestamptz`'e geçmek | Saat dilimi kuralı değişince düğün saati kayar |
| 2 | Kolonu `NOT NULL DEFAULT 'Europe/Istanbul'` yapmak | Eski kayıtlara uydurma veri yazılır |
| 3 | Misafire boş string göndermek | Sayaç cihazın dilimini varsayar — sorun geri gelir |
| 4 | `config('app.timezone')` kullanmak | Altyapı ayarı ürün kararına bağlanır |
| 5 | `$deadline->setTimezone($tz)` yazmak | Tarih bir gün kayabilir |
| 6 | `'timezone'` doğrulama kuralını atlamak | `'GMT+3'` veritabanına girer, sayaç sessizce bozulur |
| 7 | `max:64`'ü atlamak | Uzun değer veritabanı hatası → 500 |

---

## 9. Kendin dene

```powershell
php artisan migrate
```

```php
// php artisan tinker
use App\Models\Invitation;
use App\Actions\Rsvp\ResolveOpenRsvpInvitationAction;

$inv = Invitation::factory()->published()->create([
    'show_rsvp' => true,
    'timezone' => 'Pacific/Kiritimati',        // UTC+14 — dünyanın en ilerisi
    'rsvp_deadline' => now()->toDateString(),  // bugün
]);

// 🔴 Kiritimati'de yarın olduğunda sınır İstanbul'dan ÖNCE dolar
app(ResolveOpenRsvpInvitationAction::class)->handle($inv->id);

$inv->update(['timezone' => 'Pacific/Niue']);  // UTC−11 — en gerisi
// Niue'de hâlâ dün → sınır dolmamış
```

**Mutasyon denemesi (kural 14):** `CarbonImmutable::now($timezone)` yerine
`CarbonImmutable::now()` yaz. `php artisan test --filter=PaywallTest`
çalıştır. `the_rsvp_deadline_is_evaluated_in_the_invitation_timezone`
kırılmalı.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Duvar saati (wall clock)** | Takvimde/saatte okunan yerel zaman; mutlak an değil |
| **IANA saat dilimi** | `Europe/Istanbul` gibi, kurallarıyla birlikte tanımlı bölge |
| **`timestamptz`** | PostgreSQL'in UTC'de saklayıp çeviren zaman tipi |
| **ISO 8601** | `Y-m-d` sıralaması takvim sırasıyla aynı olan tarih biçimi |
| **`TZID`** | iCal standardında etkinliğin saat dilimini taşıyan alan |

---

## 11. Sırada ne var?

**7.18 — `tests/Feature/PaywallTest.php`.** Fazın kanıtı: yetersiz plan
reddediliyor mu, aynı webhook iki kez gelince tek order kalıyor mu, fiyat
gerçekten sunucudan mı geliyor.
