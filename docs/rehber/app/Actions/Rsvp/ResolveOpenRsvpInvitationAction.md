# `app/Actions/Rsvp/ResolveOpenRsvpInvitationAction.php`

> **Kod dosyası:** `app/Actions/Rsvp/ResolveOpenRsvpInvitationAction.php`
> **Faz:** 6 — Medya dilimi, dosya 6.12
> **Doğduğu yer:** `SubmitRsvpAction`'ın gövdesi (Faz 5). Faz 6'da **çıkarıldı**.
> **Kardeş dosyalar:** [`SubmitRsvpAction.md`](SubmitRsvpAction.md) ·
> [`../Invitation/ResolvePublicInvitationAction.md`](../Invitation/ResolvePublicInvitationAction.md)

---

## 1. Bu dosya neden var? (bir refactor'ün anatomisi)

Faz 5'te `SubmitRsvpAction` şu üç kontrolü **kendi gövdesinde** yapıyordu:

```php
$invitation = $this->resolveInvitation->handle($invitationId);   // yayında mı?

if (! $invitation->show_rsvp) {                                   // modül açık mı?
    throw (new ModelNotFoundException)->setModel(Invitation::class, [$invitationId]);
}

$this->assertDeadlineNotPassed($invitation);                      // süre doldu mu?
```

Ve bu **doğruydu** — o zaman bu üç soruyu soran tek bir uç vardı.

Faz 6 ikinci bir uç getirdi: **misafirin LCV foto/videosu yüklediği uç.** O uç
da aynı üç soruyu sormak zorunda. Yani ortada artık iki çağıran var, ve
**C3** devreye giriyor:

> Aynı sözleşmeyi üreten iki uç **tek yerden** üretir. DRY'ın amacı satır
> tasarrufu değil, **tek doğruluk kaynağıdır**.

### Kopyalasaydık ne olurdu?

İki somut sonuç:

**1. Güvenlik deliği.** Kuralı kopyalarken en kolay unutulan üçüncüsü — son
tarih. Süresi dolmuş bir davetiyeye LCV **gönderilemiyor** ama medya
yüklenebilseydi:

```
rsvp_photo:  200 dosya × 2 MB  =  400 MB
rsvp_video:  100 dosya × 20 MB = 2000 MB
                                 ─────────
                       davetiye başına ≈ 2.4 GB, SÜRESİZ
```

Ve bu bir "unutulmuş özellik" değil, **açık bir disk doldurma yolu** olurdu.

**2. Kaymaya davetiye.** Bugün iki kopya birebir aynı yazılsa bile, yarın
biri değişir. Faz 3'ün **P1** kuralı tam olarak bunu söylüyordu: *beş kopyanın
dördünü doğru yazıp birini unutmak, tek yeri yazmaktan daha olasıdır.*

---

## 2. 🔴 "Controller'da `if` yazılabilir" kuralı gevşedi — bu dosya yine de doğru

İsmail bu adımda `CLAUDE.md` §1'in *"controller'da `if` bulunamaz"* kuralını
gevşetti: gerektiğinde kullanılabilir.

O hâlde bu üç kontrolü `PublicMediaController`'a yazamaz mıydık? Yazabilirdik —
ve **yine yanlış olurdu**.

Çünkü bu Action'ın gerekçesi *"controller'da `if` yasak"* değil:

| Gerekçe | Kural gevşedi mi | Hâlâ geçerli mi |
|---|---|---|
| Controller'da `if` olmaz | ✅ Gevşedi | — |
| **Aynı kural iki yerde durmamalı** (C3) | ❌ Gevşemedi | ✅ **Evet** |
| **Sahiplik/erişim kuralı tek yerde** (P1) | ❌ Gevşemedi | ✅ **Evet** |

🔴 Bu, **ders 42**'nin doğrudan bir örneği: *bir kuralı uygulamak, gerekçesini
kontrol etmeden kopyalamak değildir.* Kural değişti, **karar değişmedi** —
çünkü kararı taşıyan gerekçe başkaydı.

Tersi de doğru: yarın gerçekten tek çağıranı olan basit bir dal çıkarsa, artık
onu controller'da yazmak serbest. Gevşetilen kural **gerçekten** gevşedi; bu
dosya onun kapsamına girmiyor.

---

## 3. Composition — Action, Action'ı çağırıyor

```php
public function __construct(
    private readonly ResolvePublicInvitationAction $resolvePublic,
) {}
```

Bu **kompozisyon** (composition): bir Action, işinin bir parçasını başka bir
Action'a devrediyor. Kalıtım (`extends`) değil.

### PHP temeli: `readonly` ve constructor property promotion

```php
public function __construct(
    private readonly ResolvePublicInvitationAction $resolvePublic,
) {}
```

Bu tek satır PHP 8'de **üç iş** yapıyor:

1. `private $resolvePublic` özelliğini **tanımlar** (promotion)
2. Kurucuya gelen değeri ona **atar**
3. `readonly` ile **bir daha değiştirilemez** yapar

Eski PHP'de aynı şey şuydu:

```php
private ResolvePublicInvitationAction $resolvePublic;

public function __construct(ResolvePublicInvitationAction $resolvePublic)
{
    $this->resolvePublic = $resolvePublic;
}
```

`readonly` neden önemli? Bir bağımlılık **çalışma anında değişmemeli**. Değişse
bir metot çağrısı sırasında Action'ın altındaki zemin kayardı; `readonly` bunu
dil seviyesinde imkânsızlaştırır.

### Neden kurucu enjeksiyonu (controller'da metot enjeksiyonuydu)?

| | Controller | Action |
|---|---|---|
| Kaç metodu var | Birden çok uç noktası olabilir | Genelde tek `handle()` |
| Bağımlılık kime ait | O **metoda** | **Sınıfın kendisine** |
| Seçim | Metot enjeksiyonu | **Kurucu enjeksiyonu** |

Bir Action tek bir eylemdir (`CLAUDE.md` §1: *"her sınıf sadece TEK bir eylemi
gerçekleştirir"*), yani bağımlılığı sınıfa aittir. `SubmitRsvpAction` da aynı
deseni kullanıyor.

---

## 4. Üç katman, üç farklı HTTP yanıtı

```php
$invitation = $this->resolvePublic->handle($invitationId);   // → 404
if (! $invitation->show_rsvp) { throw ...; }                 // → 404
$this->assertDeadlineNotPassed($invitation);                 // → 403
```

Neden ilk ikisi 404, üçüncüsü 403?

| Katman | Kod | Gerekçe |
|---|---|---|
| Yayında değil | **404** | Yayınlanmamış davetiyenin **varlığı** gizli. 403 deseydik "var ama kapalı" derdik ve ULID uzayı taranabilir hâle gelirdi (`docs/08` §3.2) |
| Modül kapalı | **404** | Aynı: kapalı modülün varlığı da bir bilgidir ve misafir onu zaten görmedi (**C6**) |
| Son tarih geçti | **403** | 🔴 Burada **gizlenecek bir şey yok** — davetiye zaten herkese açık, misafir onu görüyor. "Süre doldu" demek bilgi sızdırmaz, aksine kullanıcıya gereken cevabı verir |

🔴 Bu ayrım **ders 42**'nin Faz 5'teki örneğiydi: **H7** (*sahiplik yoksa 404*)
kuralı burada uygulanmıyor, çünkü ortada bir **sahiplik** sorusu yok. Kuralın
sonucunu taşımak, kuralı taşımak değildir.

### `ModelNotFoundException` neden elle fırlatılıyor?

```php
throw (new ModelNotFoundException)->setModel(Invitation::class, [$invitationId]);
```

`ResolvePublicInvitationAction` bunu `firstOrFail()` ile **otomatik** üretiyor.
Modül kontrolü ise bir sorgu değil, o yüzden aynı exception elle kuruluyor.

Amaç: **iki ret yolu ayırt edilemez olsun.** Farklı bir exception fırlatsaydık
farklı bir hata kodu üretilir ve saldırgan *"bu davetiye var ama LCV'si kapalı"*
sonucunu çıkarabilirdi. `setModel()` çağrısı yalnızca log/`debug` içindir —
yanıta çıkmaz (**H8**).

---

## 5. 🔴 `isPast()` neden yazılamaz? (E8 / ders 43)

```php
if ($deadline->lessThan(now()->startOfDay())) {
    throw new RsvpDeadlinePassedException;
}
```

Sezgi `$deadline->isPast()` yazmayı söyler. **Yanlış olur** ve hatanın türü
sinsi.

`rsvp_deadline` bir `immutable_date` cast'i taşıyor — yani **tarih**, zaman
damgası değil. Veritabanından okunduğunda o günün **00:00**'ına denk gelir:

```
rsvp_deadline = 2026-09-15        → bellekte: 2026-09-15 00:00:00
şu an          = 2026-09-15 14:30

$deadline->isPast()   →  true     ← 🔴 son gün boyunca herkes reddedilir
```

Yani son tarihi 15 Eylül olan bir davetiyede, **15 Eylül günü hiç kimse LCV
gönderemezdi**. Kullanıcılar bir gün erken kapıda kalırdı.

Doğrusu: her iki tarafı da **günün başlangıcına** indirip karşılaştırmak.

```
15 Eylül 00:00  <  15 Eylül 00:00   →  false  ✅ son gün geçerli
14 Eylül 00:00  <  15 Eylül 00:00   →  true   ✅ süre dolmuş
```

Bu tür hatalar üretimde *"bazı kullanıcılar şikâyet ediyor"* olarak görünür ve
**loglarda hiçbir iz bırakmaz**. Kural **E8** olarak kayda geçti: *`date`
kolonu, zaman damgası metotlarıyla sorgulanmaz.*

### ⚠️ Bunun kapatmadığı şey (B6)

Karşılaştırma **sunucunun saat diliminde** yapılıyor. `invitations.timezone`
kolonu Faz 7'ye ertelendi. O güne kadar Berlin'deki bir misafir için sınır bir
gün kayabilir — aynı borç geri sayım sayacında da var.

---

## 6. `null` deadline: yokluk bir değer değildir

```php
if ($deadline === null) {
    return;      // sınır yok
}
```

`rsvp_deadline` boş bırakılmışsa sahip **bilerek** sınırsız bırakmıştır.

**Ders 45**: *bir değerin yokluğunu, o değerin uzayındaki bir sayıyla temsil
etme.* Faz 5'te kota için `0`, `-1` ve `PHP_INT_MAX` reddedilmiş, `null`
seçilmişti. Burada da aynı: "son tarih yok" bir tarih değildir.

Yan kazanç: karşılaştırma **hiç yapılmıyor**.

---

## 7. Bu Action'ın YAPMADIKLARI

| Yapmadığı | Neden | Kim yapıyor |
|---|---|---|
| Honeypot kontrolü | Yalnızca LCV formuna özgü; medya yüklemesinde görünmez alan yok | `SubmitRsvpAction` |
| Kota kontrolü | 🔴 Her ucun **metriği farklı**: LCV `SUM(guest_count)`, medya `COUNT(*)` | Her uç kendi Action'ında |
| Yazma | Bu bir **çözümleyici** (resolver), yazıcı değil | `SubmitRsvpAction` / `StoreGuestMediaAction` |
| Hız sınırı | Rota katmanı (L1: en ucuz katman en başta) | `throttle` middleware |
| IP hash'leme | LCV'ye özgü KVKK adımı | `SubmitRsvpAction` |

🔴 Kota neden **ortaklaşmadı**? Çünkü ortaklaşacak bir şey yok: iki sınırın
**tanımı farklı** (kaç misafir / kaç dosya), dolayısıyla metriği de farklı.
Ortak bir "kota kontrolü" soyutlaması, iki farklı şeyi tek isimle çağırırdı.
**C3 aynı şeyin iki kopyasını yasaklar, farklı şeylerin birleştirilmesini
emretmez.**

---

## 8. ⚠️ Kabul edilen küçük maliyet (B6)

`ResolvePublicInvitationAction` sorguyu `->with('timelineEvents')` ile açıyor —
Faz 4'te Resource ilişkiye doğrudan eriştiği için gerekliydi.

Medya yükleme ucunda program adımlarına **ihtiyaç yok**. Yani her misafir medya
yüklemesinde gereksiz bir ek sorgu açılıyor.

Buna rağmen yeniden kullanıyoruz:

- Maliyet **bir sorgu**; aynı istek zaten disk I/O ve MIME analizi yapıyor —
  yanında ölçülemez
- Alternatif, görünürlük kuralının **ikinci bir kopyası** olurdu — yani C3'ü
  bir mikro-optimizasyon için kırmak

🔴 Ölçmeden optimize etmiyoruz, ama **bildiğimizi yazıyoruz**. Bir gün profiler
burayı gösterirse, `ResolvePublicInvitationAction`'a bir "ilişkisiz" varyantı
eklemek doğru hamle olur — kuralı kopyalamak değil.

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `$deadline->isPast()` yazmak | Son gün boyunca **herkes** reddedilir; logda iz yok (**E8**) |
| 2 | Son tarih reddine 404 vermek | Kullanıcı "davetiye yok" sanır; oysa görüyor. Gizlenecek bir şey yokken gizlemek |
| 3 | Modül kapalı reddine 403 vermek | Kapalı modülün **varlığı** ifşa olur (**C6**) |
| 4 | Farklı bir exception ile modül kontrolü yapmak | İki ret yolu **ayırt edilebilir** hâle gelir; bilgi sızar |
| 5 | Kota kontrolünü de buraya taşımak | İki farklı metrik tek isimle çağrılır; SRP ihlali |
| 6 | `null` deadline'ı `now()` ile değiştirmek | Sınırsız davetiye bugün kapanır (**ders 45**) |
| 7 | Bu Action'ı sahibin ucunda da kullanmak | Sahip **taslağına** da dosya yükleyebilmeli; görünürlük onun sorusu değil |
| 8 | `SubmitRsvpAction`'daki eski kodu silmeyi unutmak | İki doğruluk kaynağı geri gelir — refactor'ün amacı buydu (6.13) |

---

## 10. Kendin dene

```php
// php artisan tinker
use App\Actions\Rsvp\ResolveOpenRsvpInvitationAction;
use App\Models\Invitation;

$action = app(ResolveOpenRsvpInvitationAction::class);

$inv = Invitation::factory()->published()->create([
    'show_rsvp' => true,
    'rsvp_deadline' => null,
]);

$action->handle($inv->id);            // ✅ Invitation döner

// 1) Modül kapalı → 404
$inv->update(['show_rsvp' => false]);
$action->handle($inv->id);            // ModelNotFoundException

// 2) Süre dolmuş → 403
$inv->update(['show_rsvp' => true, 'rsvp_deadline' => now()->subDay()]);
$action->handle($inv->id);            // RsvpDeadlinePassedException

// 3) 🔴 SON GÜN hâlâ geçerli mi? (E8'in kanıtı)
$inv->update(['rsvp_deadline' => now()]);
$action->handle($inv->id);            // ✅ Invitation döner — reddetmemeli

// 4) Yayında değil → 404
$inv->update(['status' => App\Enums\InvitationStatus::Saved]);
$action->handle($inv->id);            // ModelNotFoundException
```

### Mutasyon tablosu (kural 14)

| # | Mutasyon | Kırılması gereken test |
|---|---|---|
| 1 | `show_rsvp` kontrolünü sil | "LCV kapalıyken misafir medya yükleyemez" · **T14**: `media` tablosunda satır oluşmamalı |
| 2 | `assertDeadlineNotPassed()` çağrısını sil | "süresi dolmuş davetiyeye misafir medya yükleyemez" |
| 3 | `lessThan(now()->startOfDay())` → `isPast()` | 🔴 "**son gün** hâlâ kabul edilir" — bu testi yazmak zorunlu, yoksa mutasyon hayatta kalır |
| 4 | `$deadline === null` dalını sil | "son tarihi olmayan davetiye her zaman açıktır" |
| 5 | Modül reddini `RsvpDeadlinePassedException` yap | "kapalı modül ile var olmayan davetiye **ayırt edilemez**" (ham gövde karşılaştırması, **T11**) |

3 numaralı satır bu tablonun sebebi: `isPast()` mutasyonu **testlerin çoğunda
görünmez**, çünkü çoğu test son tarihi ya `null` ya çok uzak bırakır. Sınırı
test etmeyen bir test, sınır hatasını yakalayamaz (**T7**).

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Composition** | Bir sınıfın işini başka bir sınıfa devretmesi (kalıtım değil) |
| **Constructor promotion** | Kurucu parametresinden doğrudan sınıf özelliği üreten PHP 8 kısayolu |
| **`readonly`** | Bir kez atandıktan sonra değiştirilemeyen özellik (PHP 8.1) |
| **Resolver** | Bir kimlikten kaynağa çözen, yazma yapmayan bileşen |
| **Tek doğruluk kaynağı** | Bir bilginin yalnızca tek yerde tanımlı olması |
| **`immutable_date`** | Saat taşımayan, değiştirilemez tarih cast'i (K23) |
| **Mutasyon testi** | Koda kasten bozukluk ekleyip hangi testin kırıldığına bakma yöntemi |

---

## 12. Sırada ne var?

**6.13 — `SubmitRsvpAction` refactor.** Bu Action doğdu ama henüz **kimse
kullanmıyor** — ve `SubmitRsvpAction` hâlâ eski kopyasını taşıyor. Yani şu an
tam olarak C3'ün yasakladığı durumdayız: **iki doğruluk kaynağı**.

Bir sonraki adım o üç bloğu `SubmitRsvpAction`'dan silip bu Action'a
devretmek. 🔴 Ve bu **Faz 5 koduna dokunan ilk değişiklik** — güvenliği
`RsvpTest`'in 29 testi sağlıyor. Testler yazıldıkları amaca ilk kez hizmet
edecek: *değişimi güvenli kılmak.*

| İlgili | Nerede |
|---|---|
| Kaynak Action | [`SubmitRsvpAction.md`](SubmitRsvpAction.md) |
| Görünürlük | [`../Invitation/ResolvePublicInvitationAction.md`](../Invitation/ResolvePublicInvitationAction.md) |
| Son tarih exception'ı | [`../../Exceptions/RsvpDeadlinePassedException.md`](../../Exceptions/RsvpDeadlinePassedException.md) |
| Hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` §3.2 |
| Faz özeti | [`../../../fazlar/FAZ-6.md`](../../../fazlar/FAZ-6.md) |

---

## 🆕 Faz 7 — K63 borcu kapandı

Faz 6'da bu dosyanın kılavuzunda **B6** gereği bir uyarı vardı:

> *"⚠️ Bu karşılaştırma SUNUCUNUN saat diliminde yapılıyor.
> `invitations.timezone` kolonu Faz 7'ye ertelendi; o güne kadar farklı saat
> dilimindeki misafir için sınır bir gün kayabilir."*

Kapandı.

### Önce / sonra

```php
// Faz 5-6
if ($deadline->lessThan(now()->startOfDay())) { … }

// Faz 7
$timezone = $invitation->timezone ?? Config::string('davetkart.default_timezone');
$today = CarbonImmutable::now($timezone)->toDateString();

if ($deadline->toDateString() < $today) { … }
```

### 🔴 Neden tarih **dizesi** karşılaştırılıyor?

**Bir tarihin saat dilimi yoktur.** "21 Ağustos" her yerde 21 Ağustos'tur;
değişen şey **o anda hangi günde olunduğudur**.

`$deadline` bir `date` kolonundan geliyor ve Carbon onu `00:00`'a
yerleştiriyor. O nesneyi bir saat dilimine çevirmek (`setTimezone()`) tarihi
**bir gün kaydırabilir** — tam olarak kaçınmaya çalıştığımız hata.

Doğru soru: *"Davetiyenin bulunduğu yerde bugün hangi gün?"* Cevap bir **takvim
günüdür**; `Y-m-d` dizeleri sözlük sırasında karşılaştırıldığında takvim
sırasıyla aynıdır (ISO 8601'in tasarım amacı).

### E8 değişmedi, kapsamı genişledi

**E8** (Faz 5): *"`date` kolonu, zaman damgası metotlarıyla sorgulanmaz."*
`isPast()` yasağı tarih/zaman damgası farkı içindi; şimdi araya bir de saat
dilimi girdi. Kural aynı, **kapsam** büyük.

### Kanıt

`PaywallTest::the_rsvp_deadline_is_evaluated_in_the_invitation_timezone`:
zaman donduruluyor, aynı son tarih iki farklı dilimde iki farklı sonuç
veriyor (UTC+14 → 403, UTC−11 → 201).
