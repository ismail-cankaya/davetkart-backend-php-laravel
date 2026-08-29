# `app/Policies/RsvpPolicy.php`

> **Kod dosyası:** `app/Policies/RsvpPolicy.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.9
> **Kardeş dosya:** [`InvitationPolicy.md`](InvitationPolicy.md) — **önce onu
> oku**; buradaki her karar oraya devrediyor.

---

## 1. Policy nedir, ne zaman devreye girer?

Policy, *"bu kullanıcı bu kaynağa şunu yapabilir mi?"* sorusunun tek cevap
yeridir. Controller şöyle sorar:

```php
Gate::authorize('delete', $rsvp);
```

Laravel modelin adına bakar (`Rsvp`), konvansiyona göre `RsvpPolicy` sınıfını
bulur (Laravel 11+ otomatik keşif) ve `delete()` metodunu çağırır. `false`
dönerse `AuthorizationException` fırlar.

Zincirdeki yeri Faz 3'te belirlenmişti: **Controller ile Action arasında.**
Model yüklenmiş olmalı — bu yüzden **M4** middleware'de kaynağa bağlı yetki
kararı verilmesini yasaklıyor.

---

## 2. 🔴 Bir LCV yanıtı kime ait?

Cevap: **hiç kimseye.**

- Misafir yazdı ama hesabı yok, kimliği bilinmiyor.
- Sahip yazmadı ama üzerinde söz sahibi.

Sahiplik, yanıtın kendisinde değil **bağlı olduğu davetiyede**:

```
User ──sahip──> Invitation ──içerir──> Rsvp
```

O hâlde soru şu: *"bu kuralı burada tekrar mı yazacağız?"*

```php
// ❌ YAZMIYORUZ
return $user->id === $rsvp->invitation->user_id;
```

**P1** buna hayır diyor:

> Sahiplik kuralı **tek yerde** tanımlanır; her uçta `if` ile tekrarlanmaz.
> Beş kopyanın dördünü doğru yazıp birini unutmak, tek yeri yazmaktan olasıdır.

Bu yüzden `RsvpPolicy` kendi karşılaştırmasını yapmaz; `InvitationPolicy`'ye
**devreder**:

```php
public function delete(User $user, Rsvp $rsvp): bool
{
    return $this->invitations->update($user, $rsvp->invitation);
}
```

Sahiplik hâlâ tek bir yerde tanımlı: `InvitationPolicy::owns()`.

### Neden `Gate::forUser(...)->allows(...)` değil de doğrudan enjeksiyon?

```php
public function __construct(private readonly InvitationPolicy $invitations) {}
```

Constructor injection üç şey kazandırır:

1. **Açıklık** — bağımlılık imzada görünür; `Gate` üzerinden çağırmak onu gizler.
2. **Test edilebilirlik** — policy HTTP ve Gate olmadan doğrudan çağrılabilir.
3. **PHPStan** — yanlış metot adı **yazarken** yakalanır; `Gate::allows('updaet', ...)`
   ise ancak çalışma anında.

Laravel policy'leri konteynerden çözdüğü için bağımlılık otomatik gelir
(autowiring).

---

## 3. Neden `update`, `view` değil?

```php
return $this->invitations->update($user, $rsvp->invitation);
```

Bir misafirin yanıtını silmek, davetiyeyi **okumak** değil **değiştirmektir**.
Bugün `InvitationPolicy::view()` ve `update()` aynı şeyi döndürüyor (ikisi de
`owns()`), yani seçim şu an sonucu değiştirmiyor.

Ama Faz 7'de değiştirecek: `INVITATION_LOCKED` (403) kodu zaten sözleşmede
duruyor ve *"yayınlanmış davetiye kilitlenir"* kuralı gelecek. O gün
`update()` `false` dönmeye başlayacak, `view()` dönmeyecek — ve bu satırın
**hangi soruyu sorduğu** önem kazanacak.

**Ders:** iki ifade bugün aynı sonucu veriyorsa, doğru olanı seç — çünkü
yarın ayrışacaklar ve o gün kimse buraya geri dönüp bakmayacak.

---

## 4. `view()` neden yazıldı? Ölü kod değil mi?

Bugün `Gate::authorize('view', $rsvp)` yazan bir yer yok. Liste ucu
(`GET /api/invitations/{id}/rsvps`) koleksiyonu **sorguyla** koruyor:

```php
$invitation->rsvps()   // zaten yalnızca bu davetiyenin yanıtları
```

Bu **P3**'ün gereği: *koleksiyon uçlarında sahiplik Policy ile değil sorgu ile
korunur* — filtreyi unutmak gözden kaçmaz olmalı.

O hâlde `view()` ölü kod mu? **Hayır** — ve ayrım önemli:

| | Ölü kod | Sözleşme boşluğu |
|---|---|---|
| Ne? | Çağrılmayan **iş mantığı** | Var olmayan **arayüz metodu** |
| Riski | Test edilmeden çürür (ders 26) | Çağıran sessiz bir hata alır |
| Örnek | Faz 4'te fırlatılmayan `InvitationPublished` | Bu metot |

Laravel'de bir policy metodu **yoksa** `Gate::authorize('view', $rsvp)` yetki
reddi (`AuthorizationException`) döndürür — yani `404`. Geliştirici "neden
kendi verimi göremiyorum?" diye saatlerce arar. Üç satırlık bir devretme bunu
önlüyor.

> Sınır nerede? Metot bir **karar** üretiyorsa ve o karar bugün kimseyi
> ilgilendirmiyorsa → ölü koddur, yazma. Metot bir **soruya cevap veriyorsa**
> ve soru her an sorulabilirse → sözleşmedir, yaz.

---

## 5. 🔴 `$rsvp->invitation` ve lazy loading tuzağı

`$rsvp->invitation` bir **ilişki erişimidir**. İlişki önceden yüklenmemişse
Eloquent onu o anda çeker — buna *lazy loading* denir.

Faz 0'da **S2/`preventLazyLoading`** açılmıştı: geliştirmede lazy loading
**exception fırlatır**. Yani bu policy, ilişki yüklenmeden çağrılırsa yerelde
patlar.

Çözüm controller'da (5.10):

```php
Gate::authorize('delete', $rsvp->loadMissing('invitation'));
```

`loadMissing()` **açık** bir yüklemedir — yasak olan örtük olanıdır. Sorumluluk
çağırana bırakıldı, çünkü Faz 3'te aynı karar verilmişti: `with()` çağrısı
controller'ın işidir, Resource'un değil.

> **Neden policy kendisi `loadMissing()` çağırmıyor?** Çağırabilirdi ve
> çalışırdı. Ama o zaman policy sessizce bir sorgu açan bir sınıf olurdu; N+1
> bir listede fark edilmeden çoğalırdı. Yükleme kararını çağıranda tutmak,
> maliyeti **görünür** kılar.

### 🔴 5.1 İlişki `null` dönebilir — ve bu PHPStan level 8'de ortaya çıktı

`loadMissing('invitation')` çağrılsa **bile** `$rsvp->invitation` `null`
olabilir. Sebep: `Invitation` modeli **`SoftDeletes`** kullanıyor.

Sahip davetiyesini sildiğinde satır veritabanından **gitmez**, yalnızca
`deleted_at` dolar. `rsvps` satırları da yerinde kalır (FK `cascadeOnDelete`
gerçek bir `DELETE` beklerdi, soft delete o değildir). Ama Eloquent'in
`SoftDeletingScope`'u **ilişkiye de uygulanır**: silinmiş davetiye ilişkiden
çözülmez, `null` döner.

O hâlde eski kod şuydu:

```php
return $this->invitations->update($user, $rsvp->invitation);   // ← Invitation|null
```

`InvitationPolicy::update()` imzası `Invitation` istiyor. `null` geçince PHP
**`TypeError`** fırlatır → `ApiExceptionRenderer` bunu tanımaz → **500**.

Yani: silinmiş bir davetiyenin LCV'sini silmeye çalışan **sahip**, 404 yerine
*"sunucu hatası"* görürdü. Bir yetki kararı, bir çökmeye dönüşürdü.

Düzeltilmiş hâli:

```php
$invitation = $rsvp->invitation;

return $invitation !== null && $this->invitations->update($user, $invitation);
```

#### Bu bir "kısa devre" — A4'ü ihlal etmiyor mu?

**A4** diyordu ki: *güvenlik kodunda kısa devre değerlendirmesi yasaktır.*
`&&` operatörü sol taraf `false` ise sağ tarafı **hiç çalıştırmaz**.

İhlal değil. **Ders 27**'nin ayırt edici sorusu şuydu: *"sağ taraf her durumda
çalışmalı mı?"*

| Yer | Sağ taraf her durumda çalışmalı mı | Sonuç |
|---|---|---|
| `LoginUserAction` (A4'ün doğduğu yer) | ✅ **Evet** — kullanıcı yoksa bile hash doğrulanmalı (zamanlama savunması, A3) | Kısa devre **yasak** |
| Burada | ❌ **Hayır** — davetiye yoksa policy'yi çağırmanın anlamı yok; çağıramayız da (TypeError) | Kısa devre **doğru araç** |

Ve fail-safe yönü doğru: bilinmeyen durumda cevap **`false`** — yani "hayır".
Bir güvenlik kontrolünün varsayılanı her zaman ret olmalı.

#### Neden ara değişken?

```php
$invitation = $rsvp->invitation;                        // ← neden bu satır?
return $invitation !== null && $this->invitations->update($user, $invitation);
```

`$rsvp->invitation !== null && $this->invitations->update($user, $rsvp->invitation)`
da yazılabilirdi. Yazılmadı, çünkü `$rsvp->invitation` bir **dinamik özellik**
(`__get` sihri) — statik analiz araçları böyle bir erişimin iki çağrı arasında
aynı değeri döndüreceğini **garanti edemez** ve tip daralması korunmayabilir.
Ara değişken daralmayı kesinleştirir.

Bu, Faz 3'ün **29. dersinin** ("tip belirsizliğini sınırda çöz") aynı ailesi.

#### 🔴 Bunu hangi araç yakaladı?

Hiçbir test değil. **PHPStan level 8.** Level 6'da bu hata görünmüyordu;
`composer check` Faz 5'in `5.14` commit'iyle level 8'e çıkınca ortaya çıktı.

Ders 35'in doğrudan uygulaması: *bir aracın çalıştığı, ancak bir şeyi
**yakaladığını gördüğünde** bilinir.* Level yükseltmesi bir tören değildi —
gerçek bir 500'ü önledi.

> ⚠️ **Test borcu:** bu senaryonun (`soft-deleted davetiyenin LCV'si silinmeye
> çalışılır → 404`) testi **henüz yok**. `MediaTest` ile birlikte yazılacak
> testlerde bir satır olarak duruyor.

---

## 6. Ret neden 404, nerede 404'e çevriliyor?

Bu policy `bool` döndürüyor, `Response::denyAsNotFound()` **kullanmıyor**.

Zincir şöyle işliyor:

```
RsvpPolicy::delete() → false
   ↓
Gate::authorize() → AuthorizationException
   ↓
ApiExceptionRenderer → ErrorCode::ResourceNotFound → 404
```

**P2**: *reddin HTTP karşılığı Policy'de değil, hata katmanında belirlenir.*
Sözleşme kararını (H7: sahiplik yoksa 404) her policy'ye kopyalamak, policy
yazmanın gerekçesinin tersi olurdu.

Sonuç: başkasının LCV'sini silmeye çalışan biri, **var olmayan** bir LCV'yi
silmeye çalışandan ayırt edilemeyen bir cevap alır.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Sahiplik karşılaştırmasını burada tekrarlamak | Kural iki yere düşer (P1) |
| 2 | `denyAsNotFound()` kullanmak | Sözleşme kararı her policy'ye kopyalanır (P2) |
| 3 | `view()` sorarken `update()` demek (veya tersi) | Faz 7'de kilit kuralı geldiğinde yanlış davranır |
| 4 | İlişkiyi yüklemeden `Gate::authorize` çağırmak | `LazyLoadingViolationException` (yerelde) |
| 5 | Liste ucunu policy ile korumaya çalışmak | P3: koleksiyon **sorguyla** korunur; filtreyi unutmak sessiz kalmamalı |
| 6 | Policy'yi `AuthServiceProvider`'a elle kaydetmek | Laravel 11+ konvansiyonla bulur; ikinci bir kayıt yeri kafa karıştırır |
| 7 | Policy içinde sorgu yazmak | Policy karar verir, veri toplamaz |
| 8 | 🔴 `$rsvp->invitation`'ı `null` olamaz saymak | Davetiye **soft-delete** edilmişse ilişki `null` döner → `TypeError` → **500** (§5.1) |

---

## 8. Kendin dene

```php
// php artisan tinker
$sahip   = App\Models\User::factory()->create();
$yabanci = App\Models\User::factory()->create();
$inv     = App\Models\Invitation::factory()->for($sahip)->create(['show_rsvp' => true]);
$rsvp    = $inv->rsvps()->create([
    'guest_name' => 'Can', 'guest_count' => 1,
    'status' => App\Enums\RsvpStatus::Attending, 'ip_hash' => str_repeat('a', 64),
]);

$rsvp->loadMissing('invitation');

$sahip->can('delete', $rsvp);      // true
$yabanci->can('delete', $rsvp);    // false
```

**Mutasyon denemesi (kural 14):** `delete()` gövdesini `return true;` yap ve
`php artisan test --filter=RsvpTest` koş. `another_user_cannot_delete_an_rsvp`
testi kırılmalı.

🔴 Kırılmıyorsa **dur ve testi incele.** Faz 4'ün 34. dersi tam olarak buydu:
üç IDOR testi `404` bekliyordu ve `404` alıyordu — ama Policy'den değil,
eşleşmeyen rotadan. `InvitationPolicy.php` silinseydi de geçerlerdi.

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Policy** | Bir model için yetki kararlarını toplayan sınıf |
| **Gate** | Yetki sorularının sorulduğu Laravel cephesi |
| **IDOR** | Başkasının kimliğini deneyerek verisine erişme açığı |
| **Delegasyon** | Kararı, kuralın sahibi olan başka bir nesneye devretme |
| **Constructor injection** | Bağımlılığın kurucudan verilmesi |
| **Lazy loading** | İlişkinin erişim anında sorguyla çekilmesi |
| **`loadMissing()`** | Yüklenmemiş ilişkiyi açıkça yükleme |
| **Sözleşme boşluğu** | Var olmayan arayüz metodunun sessiz hataya dönüşmesi |

---

## 10. Sırada ne var?

**5.10 — Controller'lar, rotalar ve hız sınırı.** İki controller (misafirin
gönderimi ve sahibin listesi), `/api/public/` grubuna eklenen yeni uç ve
`throttle` kayıtları.

| İlgili | Nerede |
|---|---|
| Devredilen policy | [`InvitationPolicy.md`](InvitationPolicy.md) |
| Model | [`../Models/Rsvp.md`](../Models/Rsvp.md) |
| Hata zarfı | [`../Exceptions/ApiExceptionRenderer.md`](../Exceptions/ApiExceptionRenderer.md) |
