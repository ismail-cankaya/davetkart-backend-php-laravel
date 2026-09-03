# `app/Services/Rsvp/SubscriptionRsvpQuotaResolver.php`

> **Kod dosyaları:** `app/Services/Rsvp/SubscriptionRsvpQuotaResolver.php` ·
> `app/Providers/AppServiceProvider.php` (bağlama)
> **Faz:** 7 — Ödeme ve paywall, dosya 7.16
> **Arayüzü:** [`../../Contracts/RsvpQuotaResolver.md`](../../Contracts/RsvpQuotaResolver.md)
> **Yerini aldığı:** `TierRsvpQuotaResolver` (Faz 5) — **silindi**

---

## 1. 🔴 Faz 5'te bırakılan dikiş yeri bugün kapandı

Faz 5'in `RsvpQuotaResolver` kılavuzu §5'te şöyle bir söz vermişti:

> **Faz 7'de ne değişecek?** Tek satır:
> ```diff
> - $this->app->bind(RsvpQuotaResolver::class, TierRsvpQuotaResolver::class);
> + $this->app->bind(RsvpQuotaResolver::class, SubscriptionRsvpQuotaResolver::class);
> ```
> Değişmeyecekler: `SubmitRsvpAction`, `RsvpTest`'teki kota testleri, hata
> sözleşmesi, `RsvpQuotaExceededException`.

**Söz tutuldu.** Faz 7'de değişen dosyalar:

| Dosya | Değişiklik |
|---|---|
| `AppServiceProvider.php` | 🔴 **bir satır** |
| `SubscriptionRsvpQuotaResolver.php` | yeni |
| `TierRsvpQuotaResolver.php` | **silindi** |

Değişmeyenler: `SubmitRsvpAction`, `RsvpTest`, `RsvpQuotaExceededException`,
`docs/08`.

Bu, **Dependency Inversion Principle**'ın somut getirisidir. Faz 5'te
alternatif A (*"Action config'ten okusun"*) seçilseydi bugün `SubmitRsvpAction`
değişirdi — yani kota kuralı doğru yazılmış olsa bile **yeniden test edilmesi
gereken bir dosya** olurdu.

> **Ders:** bir arayüzün değerini, onu yazdığın gün değil, **kaldırdığın**
> uygulamanın maliyetiyle ölçersin.

---

## 2. Kod: üç satır

```php
public function limitFor(Invitation $invitation): ?int
{
    $tier = $this->entitlements->highestTierFor($invitation);

    return ($tier ?? SubscriptionTier::lowest())->rsvpLimit();
}
```

Sınıfın tamamı bu. Kısalığı, **karmaşıklığın doğru yerde durmasının**
sonucudur:

| Soru | Kim cevaplıyor |
|---|---|
| Hangi siparişler hak veriyor? | `OrderStatus::grantsPublishRight()` |
| Tekil mi paket mi? | `OrderEntitlementResolver` (K42) |
| Planın limiti kaç? | `SubscriptionTier::rsvpLimit()` → `config/davetkart.php` |
| `null` ne demek? | Arayüzün sözleşmesi: **sınırsız** |

---

## 3. 🔴 `lowest()` — `FALLBACK_TIER` geri mi geldi?

**Hayır.** Fark koddadır değil, **gerekçededir**:

| | `FALLBACK_TIER` (Faz 5) | `lowest()` (Faz 7) |
|---|---|---|
| Ne diyor | *"Kaynak henüz yok"* | *"Ödeme yok"* |
| Ne kadar sürecek | **Geçici** — Faz 7'ye kadar | **Kalıcı** bir iş kuralı |
| Nerede duruyor | Sınıf sabiti, silinsin diye göze batıyor | Enum metodu, kalıcı |

Faz 5'in **46. dersi** *"geçici olanı geçici görünen bir yere koy"* diyordu ve
`FALLBACK_TIER` bilerek config'e değil bir sınıf sabitine konmuştu — **silinmesi
unutulmasın diye.** Bugün gerçekten silindi. Ders karşılığını verdi.

### Bu kol gerçekten çalışıyor mu?

İlk bakışta ulaşılmaz görünüyor:

```
ödeme yok  →  yayınlanamaz (7.12)  →  ResolveOpenRsvpInvitationAction 404  →  LCV yazılamaz
```

Ama **iade** senaryosunda gerçek olur: yayınlanmış bir davetiyenin siparişi
`refunded` olursa `grantsPublishRight()` artık `false` döner,
`highestTierFor()` `null` verir — ve davetiye hâlâ `published` durumundadır
(7.9 §6: iade var olan yayını geri çekmez).

O anda kota **en dar plandan** hesaplanır. Bilinmeyende dar tarafta kalmak,
geniş tarafta kalmaktan iyidir — `TierRsvpQuotaResolver`'ın yön seçimi
gerekçesi buydu ve hâlâ geçerli.

---

## 4. Sınırsız plan: sorgu bile açılmaz

`config/davetkart.php`:

```php
'standart' => ['rank' => 0, 'price' => 249, 'rsvp_limit' => 100],
'gold'     => ['rank' => 1, 'price' => 399, 'rsvp_limit' => null],   // sınırsız
'elit'     => ['rank' => 2, 'price' => 549, 'rsvp_limit' => null],
```

`SubmitRsvpAction`:

```php
if ($limit === null) {
    return;   // sınırsız plan: SUM() sorgusu HİÇ ÇALIŞMAZ
}
```

Faz 5'te yazılan bu satır bugün **gerçek** bir kazanca dönüştü: Gold ve Elit
davetiyelerde her LCV gönderiminde bir `SUM()` toplaması açılmıyor. O gün
"yan kazanç" diye not düşülmüştü; bugün trafiğin çoğunu oluşturuyor.

> **Not:** `?int`'in `null` = sınırsız anlamı, arayüzün sözleşmesi (Faz 5, K51).
> `0`, `-1` veya `PHP_INT_MAX` reddedilmişti — ders 45.

---

## 5. Bir bağımlılık daha: neden `PublishEntitlementResolver`?

```php
public function __construct(private readonly PublishEntitlementResolver $entitlements) {}
```

`OrderEntitlementResolver`'ı doğrudan tip olarak yazmak da çalışırdı. Yazılmadı:
bu sınıf **hangi kaynaktan** okunduğunu bilmemeli. K42'nin iki kolu tek yerde
kalır (`OrderEntitlementResolver`), buradaki kod yalnızca cevabı kullanır.

Somut sonuç: yarın hak bir "kampanya kuralından" da doğarsa bu dosya
değişmez.

### Zincir

```
SubmitRsvpAction
   → RsvpQuotaResolver          (arayüz, Faz 5)
      → SubscriptionRsvpQuotaResolver
         → PublishEntitlementResolver   (arayüz, Faz 7)
            → OrderEntitlementResolver
               → orders tablosu
```

Dört halka fazla mı? Her halka **değişebilir bir kararı** saklıyor: kota
kaynağı, hak kaynağı. K15'in soyutlama bütçesi burada bilerek harcandı —
ikisinin de **değiştiği görüldü** (Faz 5 → Faz 7 geçişi kanıt).

---

## 6. `TierRsvpQuotaResolver` neden silindi, "deprecated" bırakılmadı?

Kullanılmayan bir uygulama:

- Testlerde yanlışlıkla bağlanabilir ve **kotayı sessizce sabitleyebilir**
- Yeni gelen okuyucuya "iki yol var, hangisi doğru?" sorusunu sordurur (**C3**)
- `FALLBACK_TIER` sabiti hâlâ *"herkes standart"* diyerek yanıltır

Kılavuzu da silindi ve dikiş yeri dersi bu dosyaya taşındı — **B8**: *"bir
kural çıkarıldığında, kılavuzundaki anlatımı da taşınır; iki yerde kalmaz."*

> `docs/rehber/app/Contracts/RsvpQuotaResolver.md` §5'teki söz de bu dosyaya
> işaret edecek şekilde güncellendi.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `null` yerine `0` döndürmek | "Kota yok" ile "kota sıfır" karışır (ders 45) |
| 2 | Ödeme yokken sınırsız (`null`) dönmek | İadeden sonra kota tamamen kalkar |
| 3 | `OrderEntitlementResolver`'ı doğrudan tip olarak yazmak | K42'nin kolları bu dosyaya sızar |
| 4 | Eski uygulamayı silmeyip "deprecated" bırakmak | İki yol ayrışır (C3) |
| 5 | Bağlamayı `boot()`'a yazmak | Çalışır ama yanlış yerdedir |
| 6 | Kotayı `singleton` bağlamak | İstek içinde bayat hak bilgisi |

---

## 8. Kendin dene

```php
// php artisan tinker
use App\Contracts\RsvpQuotaResolver;
use App\Models\{Invitation, Order};
use App\Enums\SubscriptionTier;

get_class(app(RsvpQuotaResolver::class));
// App\Services\Rsvp\SubscriptionRsvpQuotaResolver   ← bağlama değişti

$r = app(RsvpQuotaResolver::class);
$inv = Invitation::factory()->create();

$r->limitFor($inv);            // 100 — ödeme yok, en dar plan

Order::factory()->paid()->tier(SubscriptionTier::Gold)->forInvitation($inv)->create();
$r->limitFor($inv);            // null — SINIRSIZ

// 🔴 İade: hak düşer
Order::query()->update(['status' => 'refunded']);
$r->limitFor($inv->refresh()); // 100 — yeniden en dar plan
```

**Mutasyon denemesi (kural 14):** `($tier ?? SubscriptionTier::lowest())`
yerine `$tier?->rsvpLimit()` yaz (yani ödeme yoksa `null` = sınırsız).
`php artisan test --filter=PaywallTest` çalıştır.
`an_unpaid_invitation_falls_back_to_the_narrowest_quota` kırılmalı.

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Seam (dikiş yeri)** | İleride değişeceği bilinen yerde bilerek bırakılan ayrılma çizgisi |
| **DIP** | Dependency Inversion — somuta değil soyutlamaya bağımlı olmak |
| **Binding** | "Bu arayüz istendiğinde şu sınıfı ver" kaydı |
| **Fail-safe** | Bilinmeyende güvenli/dar tarafta kalmak |

---

## 10. Sırada ne var?

**7.17 — `invitations.timezone` (K63).** Faz 4'ten beri üç kez ertelenen
kolon: misafirin geri sayım sayacı artık davetiyenin saat dilimini biliyor.
