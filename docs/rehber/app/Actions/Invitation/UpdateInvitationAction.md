# `app/Actions/Invitation/UpdateInvitationAction.php`

> **Kod dosyası:** `app/Actions/Invitation/UpdateInvitationAction.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.10 (3/3) · 🆕 **Faz 10, adım 10.1** (§9)
> **Önce oku:** [`CreateInvitationAction.md`](CreateInvitationAction.md) ·
> Faz 10 için: [`PublishInvitationAction.md`](PublishInvitationAction.md)
> **Kararlar:** **K88** (yayındaki davetiyede modül açma kayıt anında plan kontrolünden geçer)

---

## 1. Oluşturmadan farkı

Yapı neredeyse aynı: transaction, sync, `load()`. İki fark var.

```php
$fresh->fill($attributes);
// … (Faz 10: yayındaysa plan kontrolü, §9)
$fresh->save();
```

`create()` yerine `fill()` + `save()`. Kayıt zaten var; sahibi de belli, dolayısıyla
ilişkiden geçmeye gerek yok.

> Faz 3'te bu satır `$invitation->fill($attributes)->save();` idi. Faz 10'da iki
> şey değişti: nesne artık kilitli bir **yeniden okuma** (`$fresh`, §9.2) ve
> `fill()` ile `save()` arasına paywall kontrolü girdi (§9.4).

🔴 Ama `#[Fillable]` koruması burada da çalışıyor: `fill()` yalnızca beyaz
listedeki alanları yazar. İstemci `status` göndermeyi başarsa bile (3.8 zaten
engelliyor) bu satır onu yok sayardı — **katmanlı savunma**.

---

## 2. Yetki kontrolü neden burada değil?

Bu Action bir davetiyeyi güncelliyor ama "bu kullanıcının mı?" diye **sormuyor**.

Çünkü soru 3.7'de cevaplandı: `InvitationPolicy` controller'da çalışıyor
(`Gate::authorize('update', …)`). Action'a ulaşan bir istek, yetki kapısını
çoktan geçmiştir.

Buraya da koysaydık kural **iki yerde** olurdu — Policy yazmamızın gerekçesinin
tam tersi. Ve iki kopya zamanla ayrışır: biri güncellenir, diğeri unutulur.

> **İlke:** Bir kural iki katmanda da doğruysa, **sorumlu katmanı seç** ve
> diğerinde tekrarlama. Katmanlı savunma, aynı kuralı kopyalamak değil;
> **farklı türden** engelleri üst üste koymaktır.

`#[Fillable]` (mass assignment) ile Policy (sahiplik) farklı türden engellerdir —
o yüzden ikisi birlikte var. Faz 10'un paywall kontrolü (§9) üçüncü tür: sahiplik
değil **hak**.

---

## 3. `$timelineEvents === null` burada kritik

```php
$timelineChanged = $timelineEvents !== null
    && $this->syncTimelineEvents->handle($fresh, $timelineEvents);
```

Oluşturmada `null` ile `[]` aynı sonucu veriyordu. Burada **tamamen farklılar**:

| İstek | Sync | Sonuç |
|---|---|---|
| `timelineEvents` alanı yok | Çağrılmaz | Program **aynen kalır** |
| `timelineEvents: []` | Çağrılır | Program **tamamen silinir** |

Yalnızca başlığı güncelleyen kısmi bir istek düşün. `null`'ı `[]` gibi
davransaydık, o istek kullanıcının **tüm programını silerdi** — ve kullanıcı
neden sildiğini asla anlayamazdı.

Bu ayrım 3.8'deki `array_key_exists` kararının devamı: **"yok" ile "boş" farklı
bilgilerdir.**

### `&&` burada kısa devre yapıyor — sorun değil mi?

`$timelineEvents === null` ise `handle()` **hiç çağrılmaz**. Bu kez kısa devre
tam olarak istediğimiz şey.

`SyncTimelineEventsAction` §7'de kısa devrenin bir hataya yol açtığını görmüştük.
Fark: orada sağ tarafta **yan etkisi olan** bir çağrı vardı ve her durumda
çalışması gerekiyordu. Burada sağ tarafın çalışmaması **kararın kendisi**.

> Aynı dil özelliği bir yerde tuzak, başka yerde araç. Ayırt edici soru:
> *"sağ taraf her durumda çalışmalı mı?"*

---

## 4. `wasChanged()` ve bayat `updated_at`

```php
if ($timelineChanged && ! $fresh->wasChanged()) {
    $fresh->touch();
}
```

İnce ama gerçek bir sorunu çözüyor.

Kullanıcı **yalnızca** bir program adımının başlığını değiştirdi. Ne olur?

```
1. $attributes bos veya degismemis  → save() hicbir sey yazmaz
2. sync program satirini gunceller  → timeline_events degisti
3. invitations.updated_at           → DEGISMEDI  ⚠️
```

Frontend `updatedAt` alanını *"son sunucu güncellemesi"* diye gösteriyor
(`types.ts`). Kullanıcı bir şey değiştirir, "son kaydetme" saati eskide kalır ve
"kaydolmadı mı?" diye endişelenir.

`touch()` yalnızca `updated_at` kolonunu günceller.

### `isDirty()` ile `wasChanged()` farkı

Sık karıştırılır ve **zamanlaması** farklıdır:

| Metot | Sorusu | Ne zaman |
|---|---|---|
| `isDirty()` | Kaydedilmemiş değişiklik var mı? | `save()`'ten **önce** |
| `wasChanged()` | `save()` gerçekten bir şey değiştirdi mi? | `save()`'ten **sonra** |

Burada `save()` zaten çalıştı, dolayısıyla doğru soru `wasChanged()`.
`isDirty()` yazsaydık her zaman `false` dönerdi (kayıt sonrası temizdir) ve
`touch()` **her seferinde** çalışırdı — gereksiz bir yazma daha.

### Neden koşullu, neden her zaman `touch()` değil?

`save()` bir şey değiştirdiyse `updated_at` zaten güncellendi. Üstüne `touch()`
çağırmak ikinci bir `UPDATE` sorgusu demektir — autosave 1,5 saniyede bir
çalışırken bu iki katı yazma yükü olurdu.

---

## 5. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `null` ile `[]`'i aynı saymak | Kısmi güncelleme **programı siler** | Ayır |
| 2 | Action'da yetki kontrolü | İki doğruluk kaynağı | Policy (3.7) |
| 3 | `isDirty()` kullanmak (save sonrası) | Her zaman `false`, gereksiz `touch()` | `wasChanged()` |
| 4 | Koşulsuz `touch()` | Her istekte iki `UPDATE` | Koşullu |
| 5 | `update($attributes)` + ayrı sync, transaction'sız | Yarım durum | `DB::transaction` |
| 6 | `load()` unutmak | Yanıtta bayat program | `load('timelineEvents')` |
| 7 | 🆕 Paywall'ı yalnızca yayın anında sormak | Yayından sonra PUT ile Elit modüller bedava açılır (K-1) | §9 |
| 8 | 🆕 Rota bağlamasından gelen nesneyle karar vermek | Eşzamanlı "yayınla" ile yarış: kontrolsüz galeri | Kilitli yeniden okuma (§9.2) |
| 9 | 🆕 Son hâli sahip olunan planla kıyaslamak | İade ya da fiyat değişikliğinden sonra kullanıcı **tek harf bile** düzeltemez | Farka bak (§9.3) |
| 10 | 🆕 Kontrolü `save()`'ten sonra yapmak | Transaction satırı geri alır ama olay çoktan fırlamıştır | `fill()` → kontrol → `save()` |

---

## 6. Kendin dene

```php
use App\Models\Invitation;
use App\Actions\Invitation\UpdateInvitationAction;

$inv = Invitation::factory()->withTimeline(2)->create(['title' => 'Eski']);
$action = app(UpdateInvitationAction::class);

// 1) Yalnizca baslik degisti, programa DOKUNMA
$action->handle($inv, ['title' => 'Yeni'], null);
$inv->fresh()->title;                          // => "Yeni"
$inv->fresh()->timelineEvents()->count();      // => 2   ✅ program korundu

// 2) 🔴 Bos dizi: kullanici tum adimlari sildi
$action->handle($inv, [], []);
$inv->fresh()->timelineEvents()->count();      // => 0   ✅ hepsi silindi

// 3) updated_at bayat kalmiyor mu?
$inv2 = Invitation::factory()->withTimeline(1)->create();
$once = $inv2->updated_at;
$adimId = (string) $inv2->timelineEvents()->value('id');

sleep(1);
$action->handle($inv2, [], [['id' => $adimId, 'title' => 'Sadece program degisti']]);

$inv2->fresh()->updated_at->greaterThan($once);
// => true   ✅ touch() calisti

Invitation::query()->forceDelete();
```

İkinci ve üçüncü denemeler bu dosyanın iki Faz 3 kararının kanıtı. Faz 10'un
denemesi §9.9'da.

```powershell
composer check
```

---

## 7. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **`fill()`** | Beyaz listedeki alanları modele yazma (kaydetmez) |
| **`isDirty()`** | Kaydedilmemiş değişiklik var mı (save öncesi) |
| **`wasChanged()`** | `save()` gerçekten değişiklik yazdı mı (save sonrası) |
| **`touch()`** | Yalnızca `updated_at`'i güncelleme |
| **Katmanlı savunma** | Farklı türden engelleri üst üste koyma |
| 🆕 **Yeniden okuma (re-read)** | Kararı, kilidi aldıktan sonra veritabanından taze okunan satırla vermek |
| 🆕 **Tutarsız durum** | Yayındaki davetiyenin, sahip olunan planın **üstünde** kalması (iade, fiyat haritası değişikliği) |

---

## 8. Sırada ne vardı? (Faz 3)

**3.11 — `InvitationController` + rotalar.** Katmanları birleştiren yer: Policy
bağlanması, rota sırası, ULID ile route model binding, 3-8 satırlık controller.

---

## 9. 🔴 Faz 10 — Yayındaki davetiyede paywall (10.1 · K88)

### 9.1 Açık: yayından sonra paywall aşılıyordu

Denetim bunu kum havuzunda **yeniden üretti** (K-1):

```
1. Standart (249 ₺) ödendi → galerisiz davetiye yayınlandı            → 200
2. Dashboard → "Düzenle" → editörde Galeri + Hediye/IBAN açıldı
3. Autosave: PUT /api/invitations/{id} {showGallery:true, showGift:true} → 200  ⚠️
4. ClearInvitationCache → misafir sayfası Elit modüllerini gösteriyor
```

Paywall yalnızca `PublishInvitationAction`'da soruluyordu. Bu Action
`TierResolver`'ı hiç çağırmıyordu; `PublicInvitationResource` da modülü yalnızca
`show_*` bayrağına bakarak açıyor (C6). DevTools gerekmiyordu — normal arayüz
akışı yetiyordu. 249 ₺ ödeyen, 549 ₺'lik ürünü alıyordu.

> **Neden hiçbir test görmedi?** Kritik bulguların ikisi de iki ayrı **doğru**
> parçanın birleştiği yerde duruyordu. `PublishInvitationAction` doğru,
> `UpdateInvitationAction` kendi işini doğru yapıyor — hata, *"yayından sonra
> da düzenlenebilir"* gerçeği ile *"paywall yalnızca yayında sorulur"* varsayımı
> arasındaydı. Bir parçayı sınayan test, iki parçanın arasını göremez.

**K88** iki savunmadan ucuzunu seçti: **yazma anında** kontrol. Okuma anında
maskeleme (misafir yanıtında modülü *"bayrak açık ve plan kapsıyor"* koşuluyla
göstermek) bu fazda **yok** — bedeli, public okuma yolunda sipariş sorgusu ve
sipariş değişince cache temizliği.

### 9.2 🔴 Kilitli yeniden okuma — neden şart?

```php
$fresh = Invitation::query()
    ->whereKey($invitation->getKey())
    ->lockForUpdate()
    ->firstOrFail();
```

Kilit olmasaydı (Faz 10 tuzağı #1):

```
İstek A (PUT galeri aç)          İstek B (POST yayınla)
─────────────────────────        ─────────────────────────
oku: status = saved  ✅
                                  oku: show_gallery = false → Standart yeter
taslak → kontrol YOK
                                  yayınla: status = published
yaz: show_gallery = true
                                  ─► Standart ile yayında, galeri AÇIK  🔴
```

İki istek de **eski** satırı gördü ve ikisi de kendi açısından doğru karar
verdi. Bu, projenin tanıdık **check-then-act** yarışı (**E9**).

`PublishInvitationAction` aynı satırı zaten kilitliyordu (7.12). Bu Action da
kilitleyince ikisi **sıraya girer**:

| Önce kilidi alan | Sonra gelen ne görür | Sonuç |
|---|---|---|
| PUT (galeri aç, taslakta) | Yayın, `show_gallery = true` görür → Elit gerekir | **402** (yayın reddi) |
| Yayın | PUT, `status = published` görür → kontrol çalışır | **402** (güncelleme reddi) |

Her iki sırada da yanlış bir durum oluşmuyor. Kilit **tek satırlık** ve
transaction kısa; autosave'in 1,5 saniyelik temposunda bekleme görülmez.

> ⚠️ Bu koruma **test edilemez** (**T15**): eşzamanlılık tek süreçli bir
> PHPUnit koşusunda kurulamaz. Mutasyon denemesinde `lockForUpdate()`'i silmek
> hiçbir testi kırmıyor (§9.7, M6). Koruma kod incelemesiyle yaşar — yayın
> Action'ındaki kilidin kaderiyle aynı.

Taslaklar da kilitleniyor, oysa taslakta kontrol yok. Neden? Çünkü **taslak
olduğunu** ancak kilitli okuma söyleyebilir. Rota bağlamasındaki `status`'a
bakıp "taslak, kilide gerek yok" demek, yukarıdaki yarışın ta kendisidir.

### 9.3 🔴 Kural bir FARKA bakar, son hâle değil

Plan metni kısaydı: *"`fill()`'den sonra `requiredFor()` ↔ `highestTierFor()`,
yetmiyorsa 402"*. Üç okuması var:

| Seçenek | Kural | Normal durumda | **Tutarsız** durumda (iade · fiyat haritası değişti) |
|---|---|---|---|
| (a) Son hâl | Sahip olunan plan, değişiklik **sonrası** gereksinimi kapsıyor mu? | Doğru | 🔴 **Her** düzenleme 402: tarih düzeltmek, modül **kapatmak** bile |
| (b) **Fark** ✅ | Değişiklik gereksinimi **yükseltiyorsa**, yenisi kapsanıyor mu? | Doğru | Metin ve kapatma serbest; aynı plandaki bir modül açılabilir (§9.8) |
| (c) Modül modül | Açılan **her** modül kapsanıyor mu? | Doğru | En sıkı; ama `TierResolver`'a yeni bir genel metot ister |

Normal durumda (sahip olunan ≥ yayındaki gereksinim) üçü **aynı** cevabı verir.
Ayrım yalnızca tutarsız durumda görünür ve o durum iki yoldan doğar:

1. **İade.** Sipariş `refunded` olur, hak düşer, ama davetiye yayında kalır
   (açık karar 10.61). Kullanıcı iade edilmiş galeriyi **kapatmak** istiyor.
2. **Fiyat haritası değişir.** `config('davetkart.module_tiers')` bir iş
   tercihidir (E6): yarın *"zaman çizelgesi Elit'e"* denebilir. Gold ile
   yayınlanmış yüzlerce davetiye bir gecede *"plan dışı"* görünür.

(a) ile ikisinde de kullanıcı davetiyesine **kilitlenirdi**: bir fiyatlandırma
kararı, müşterinin düğün tarihini düzeltmesini engellerdi. Planın kendi cümlesi
de bunu yasaklıyor: *"Modül kapatmak her zaman serbest."* (a) bu cümleyi
tutarsız durumda çiğner.

```php
$requiredBefore = $this->tiers->requiredFor($fresh);   // fill()'den ÖNCE
$fresh->fill($attributes);
// …
$after = $this->tiers->requiredFor($invitation);

if ($before->covers($after)) {
    return;                    // hiçbir şey yükselmedi
}
```

`$before->covers($after)` bir **gereksinimi** bir gereksinimle kıyaslıyor;
`covers()` normalde "plan, gereksinimi karşılar mı" diye okunur. Burada da aynı
aritmetik (rank ≥ rank), okuması *"önceki gereksinim yenisini zaten
kapsıyor"*. Kıyası elle `rank() <=` diye yazmamak **C3**: sıralama kuralı tek
yerde, `SubscriptionTier::covers()`'ta.

> **Sıra kritik.** `$requiredBefore` `fill()`'den **sonra** ölçülseydi önce ile
> sonra hep eşit olur, kural hiçbir şeyi reddetmezdi. Mutasyon M4 bunu kanıtlıyor.

(c)'yi neden seçmedik? Tek fark tutarsız durumdaki *"aynı plandan bir modül
daha"* açığı ve o açık zaten daha büyük bir açığın içinde duruyor: iade edilmiş
modüller **hâlâ yayında**. İkisini birlikte kapatacak olan okuma anı
kontrolüdür (10.61). O karara kadar (b) yeterli ve yeni bir API gerektirmiyor.

### 9.4 Kontrol `save()`'ten ÖNCE

```php
$fresh->fill($attributes);

if ($fresh->status === InvitationStatus::Published) {
    $this->ensureRaisedTierIsOwned($fresh, $requiredBefore);
}

$fresh->save();
```

`fill()` modeli **bellekte** değiştirir; `requiredFor()` bayrakları modelden
okuduğu için *"değişiklik sonrası"* gereksinimi veritabanına yazmadan hesaplar.

Kontrol `save()`'ten sonra olsaydı da veri bozulmazdı — istisna transaction'ı
geri alır. Ama `save()` çoktan `updated` olayını fırlatmış, `ClearInvitationCache`
misafir cache'ini çoktan silmiş olurdu: **reddedilmiş** bir istek için bir yan
etki. Önce sor, sonra yaz; reddedilen istek iz bırakmaz.

> Bu fark bir testle görülmüyor (§9.7, M7) — etkisi yalnızca gereksiz bir cache
> temizliği. Kural kodun sırasında ve bu kılavuzda yaşıyor.

### 9.5 İki red, iki kod — yayın ucuyla aynı

| Durum | İstisna | Kod | Frontend |
|---|---|---|---|
| Hiç hak yok (iade) | `noPurchase($after)` | `PAYMENT_REQUIRED` 402 | *"Önce bir plan al"* |
| Hak var, yetmiyor | `insufficientTier($after, $owned)` | `PAYWALL_TIER_INSUFFICIENT` 402 | *"Planını yükselt"* |

K88 *"402 `PAYWALL_TIER_INSUFFICIENT`"* diyor ve normal akışta gelen **hep**
budur: yayındaki bir davetiyenin bir hakkı vardır. `PAYMENT_REQUIRED` yalnızca
iadeden sonra görülür. Kodu `PublishInvitationAction`'dan farklı seçseydik
frontend aynı 402'yi iki ayrı sözlükle okumak zorunda kalırdı.

`requiredTier` parametresi **değişiklik sonrası** gereksinimdir (`$after`) —
kullanıcının satın alması gereken plan budur.

> **Bilinen küçük pürüz:** `PaywallViolationException`'ın mesajı *"Publish
> rejected: …"* diye başlıyor ve bu yolda yanıltıcı. Mesaj istemciye gitmiyor
> (K20: yalnızca `code` + `params`), yalnızca log'a ve yerel `debug` bloğuna
> düşüyor; 10.16'dan sonra 4xx iş istisnaları log'a da düşmeyecek. Ayrı bir
> adlandırılmış kurucu açmaya değmedi.

### 9.6 Taslak serbest (K43'ün ruhu)

`status = saved` iken hiçbir kontrol yok. Kullanıcı Elit modülleri açıp
kapatarak tasarımını deneyebilmeli; bedeli **yayında** ödenir, çünkü misafirin
gördüğü şey orada doğar. Taslakta 402 atmak, fiyat kartını görmeden önce ürünü
denemeyi imkânsızlaştırırdı.

### 9.7 Mutasyon tablosu (T16 — gerçekten koşturuldu)

Her satır kum havuzunda uygulandı, `PaywallTest` koştu, dosya geri alındı:

| # | Mutasyon | Kırılan test(ler) |
|---|---|---|
| M1 | `status === Published` kontrolünü kaldır (her zaman sor) | `a_draft_invitation_can_enable_any_module_without_an_order` |
| M2 | `covers()` reddini kaldır | `a_published_invitation_cannot_enable_a_module_above_its_tier` · `upgrading_the_order_…` |
| M3 | Fark kuralını kaldır (son hâle bak — seçenek a) | `disabling_a_module_needs_no_covering_order` · `a_published_invitation_stays_editable_when_the_price_map_changes` |
| M4 | `$requiredBefore`'u `fill()`'den **sonra** ölç | `a_published_invitation_cannot_enable_…` (+2) |
| M5 | `noPurchase()` → `insufficientTier(…, lowest())` | `enabling_a_module_after_a_refund_asks_for_a_purchase` |
| M6 | `lockForUpdate()`'i sil | ⚠️ **Hiçbiri** — T15, §9.2 |
| M7 | Kontrolü `save()`'ten sonraya al | ⚠️ **Hiçbiri** — transaction geri alıyor, §9.4 |

İki boşluk **bilinerek** kabul edildi (**B6**).

### 9.8 Bu değişikliğin YAPMADIKLARI (B6)

| Yapmaz | Neden / nerede |
|---|---|
| Misafir yanıtında modülü maskelemek | Okuma anı savunması; bu fazda yok (K88) |
| İadede yayını geri çekmek | Açık karar **10.61** |
| Tutarsız durumda aynı plandan modül açmayı engellemek | §9.3 (b)'nin bilinen açığı; 10.61 ile birlikte kapanır |
| Galeri **dosyası** yüklemeyi engellemek | Yükleme ayrı uç (`POST /invitations/{id}/media`). Dosya yüklenir ama `show_gallery` açılamadığı için misafire görünmez |
| Frontend'in açılan anahtarı geri alması | Frontend 10.8 |

### 9.9 Kendin dene

```php
// php artisan tinker
use App\Actions\Invitation\UpdateInvitationAction;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;

$inv = Invitation::factory()->published()->create();
Order::factory()->paid()->tier(SubscriptionTier::Standart)->forInvitation($inv)->create();
$action = app(UpdateInvitationAction::class);

$action->handle($inv, ['show_gallery' => true], null);
// => PaywallViolationException  ("owned tier 'standart' does not cover 'elit'")
$inv->fresh()->show_gallery;                              // => false  ✅ yazılmadı

$action->handle($inv, ['title' => 'Nikâhımıza Davetlisiniz'], null)->title;
// => "Nikâhımıza Davetlisiniz"  ✅ gereksinimi yükseltmeyen düzenleme serbest

Invitation::query()->forceDelete();
```

```powershell
php artisan test --filter=PaywallTest
composer check
```

| İlgili | Nerede |
|---|---|
| Aynı kilidin yayın tarafı | [`PublishInvitationAction.md`](PublishInvitationAction.md) |
| Gereksinim hesabı | [`../../Services/Pricing/TierResolver.md`](../../Services/Pricing/TierResolver.md) |
| Testler | [`../../../tests/Feature/PaywallTest.md`](../../../tests/Feature/PaywallTest.md) §11 |
| Kaynak bulgu | `claude/GOZDEN-GECIRME-RAPORU.md` §1.1 · `claude/TEST-DENETIMI-2026-09-24.md` K-1 |
