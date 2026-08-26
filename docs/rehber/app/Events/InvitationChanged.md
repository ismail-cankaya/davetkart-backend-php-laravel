# `app/Events/InvitationChanged.php`

> **Kod dosyası:** `app/Events/InvitationChanged.php`
> **Faz:** 4 — Public davetiye, dosya 4.6a
> **Sonra:** [`../Listeners/ClearInvitationCache.md`](../Listeners/ClearInvitationCache.md) (4.6b)

---

## 1. Olay (event) nedir ve neden gerekli?

Elimizdeki sorun somut: davetiye güncellenince cache'teki kopya **bayat**
kalıyor. En basit çözüm şu görünüyor:

```php
// UpdateInvitationAction içinde
$invitation->fill($attributes)->save();
Cache::forget(Invitation::publicCacheKey($invitation->id));   // ❌
```

Çalışır. Ama şuna dikkat: `UpdateInvitationAction` artık **cache'in var
olduğunu biliyor**. Yani "davetiyeyi güncelle" işi ile "önbelleği yönet" işi
aynı sınıfta birleşti. Yarın bir arama indeksi eklersek oraya da bir satır,
webhook eklersek bir satır daha…

**Olay/dinleyici (event/listener)** deseni bu bağı koparır:

```
UpdateInvitationAction ──── "davetiye değişti" ────► ???
                             (kim dinliyorsa)
```

Yazan taraf **ne olduğunu** duyurur; onunla ne yapılacağını bilmez. Dinleyen
taraf kendi işini yapar. Buna **gevşek bağ** (loose coupling) denir ve
`09-TUM-FAZLAR-PLANI.md`'de Faz 4'ün öğrenme hedeflerinden biri olarak yazılı.

### Gözlemci (Observer) deseni

Bu, klasik **Observer Pattern**'dir: bir özne (subject) durum değişikliğini
duyurur, kendisini dinleyen gözlemcilerin kim olduğunu bilmeden. Laravel'in
`Event`/`Listener` çifti bunun framework'e gömülmüş hâli.

Frontend'den tanıdık gelebilir: `addEventListener('click', ...)` de aynı
desendir — düğme, kendisine kimin abone olduğunu bilmez.

---

## 2. 🔴 İsim neden `InvitationChanged`?

Plandaki ad `InvitationPublished`'dı. Değiştirdik ve gerekçesini tartıştık.

### Gerekçe 1: Bugün onu fırlatacak bir olay yok

Yayınlama akışı **Faz 7'de** yazılacak (K42, K43 — plan hakkı ve kota
hesabı ödeme moduluyle geliyor). `InvitationPublished` yazsaydık:

- Dosya üç faz boyunca **hiç fırlamayan** bir sınıf olarak dururdu
- Cache bugün bayat kalmaya devam ederdi
- Faz 4'ün "bitti ölçütü" bozuk bir cache ile karşılanmış sayılırdı

Faz 3'ün **26. dersi** tam olarak bunu yasaklıyor: *çalıştırılmayan kod, doğru
olduğu varsayılan koddur.*

### Gerekçe 2: İsim, olayı anlatmalı — sonucunu değil

Kötü isim adayları ve neden kötü oldukları:

| Aday | Sorun |
|---|---|
| `InvitationPublished` | Yalnızca bir tetikleyiciyi anlatıyor; autosave güncellemesini kapsamıyor |
| `ClearCacheRequested` | Olayı **dinleyicinin işiyle** adlandırıyor; ikinci bir dinleyici geldiğinde ad yalan olur |
| `InvitationSaveCompleted` | Uygulama içi bir ayrıntı; iş dilinde karşılığı yok |
| **`InvitationChanged`** | Ne olduğunu söylüyor, kimin ne yapacağını söylemiyor ✅ |

> **Kalıp:** Bir olayın adı **geçmiş zamanlı bir olgu** olmalı ("şu oldu"), bir
> emir ("şunu yap") ya da bir niyet ("şu istendi") değil. Emir yazarsan, ikinci
> dinleyici eklendiği gün ad yanlışa döner.

### Gerekçe 3: Faz 7 muhtemelen ayrı bir olaya ihtiyaç duymayacak

Yayınlama işlemi `status` kolonunu değiştirecek — yani Eloquent'in `updated`
olayı zaten fırlayacak, yani `InvitationChanged` zaten tetiklenecek. Cache
açısından `InvitationPublished`'a hiç gerek kalmayabilir.

Faz 7 gerçekten ayrı bir olaya ihtiyaç duyarsa (örneğin "yayınlandı" e-postası
göndermek için) o zaman yazılır — **ve o gün gerçekten fırlatılır.**

---

## 3. Kod okuması

```php
final class InvitationChanged
{
    public function __construct(
        public readonly Invitation $invitation,
    ) {}
}
```

Sınıfın tamamı bu. Bir olay sınıfı **davranış taşımaz**, yalnızca "ne oldu"
sorusunun cevabını taşıyan bir veri kabıdır.

### `public readonly` — kurucu özellik yükseltmesi (PHP 8)

```php
public function __construct(
    public readonly Invitation $invitation,
) {}
```

Bu tek satır, eski PHP'de üç satırdı:

```php
public readonly Invitation $invitation;          // 1. özelliği bildir

public function __construct(Invitation $invitation)
{
    $this->invitation = $invitation;             // 2. ata
}
```

Buna **constructor property promotion** denir (PHP 8.0). `readonly` (PHP 8.1)
ise özelliğin **yalnızca kurucuda** atanabilmesini sağlar.

Neden `readonly`? Bir olay, **olmuş bitmiş bir olguyu** temsil eder. Geçmiş
değiştirilemez. Bir dinleyicinin `$event->invitation = $baskaDavetiye` yazması,
sonraki dinleyicilerin farklı bir gerçeklik görmesi demektir — takibi imkânsız
bir hata sınıfı. `readonly` bunu dil seviyesinde engelliyor.

### Neden `Dispatchable` trait'i yok?

`php artisan make:event` üç trait ile üretir: `Dispatchable`,
`InteractsWithSockets`, `SerializesModels`. Üçünü de sildik.

| Trait | Ne yapar | Bize neden gerekmiyor |
|---|---|---|
| `Dispatchable` | `InvitationChanged::dispatch($inv)` kısayolu | Olayı **model** fırlatıyor (`$dispatchesEvents`), elle çağırmıyoruz |
| `InteractsWithSockets` | Broadcast (WebSocket) desteği | **K7**: Reverb/WebSocket kullanmıyoruz |
| `SerializesModels` | Kuyruğa alınırken modeli id'ye indirger | Dinleyicimiz **kuyruğa girmiyor** (4.6b) |

Üçü de "belki lazım olur" diye durabilirdi. Durmuyorlar çünkü bu proje
`Repository Pattern`'i de aynı gerekçeyle reddetti (K4/YAGNI): **kullanılmayan
soyutlama, okuyanın anlamak için harcadığı zamanı çalar.**

Faz 7'de elle `dispatch()` gerekirse `Dispatchable` o gün eklenir — tek satır.

### Neden model taşınıyor, sadece `id` değil?

Dinleyici yalnızca `$invitation->id`'yi kullanıyor. O hâlde `string $id`
taşımak daha yalın olmaz mıydı?

İki sebep:

1. **Seçeneğimiz yok.** `$dispatchesEvents` mekanizması olayı
   `new $eventClass($this)` diye kurar — kaynak:
   `vendor/.../Concerns/HasEvents.php:242`. Kurucuya modeli geçirir, başka bir
   şey geçiremez.
2. **İstesek de istemezdik.** İkinci bir dinleyici geldiğinde (Faz 6: medya
   temizliği, Faz 8: bildirim) muhtemelen modelin başka alanlarına ihtiyaç
   duyacak. Olay, olguyu **tam** taşımalı; kırpma dinleyicinin işi.

> ⚠️ Model taşımanın bir maliyeti var: olay **kuyruğa alınırsa** model
> serileşir. Bizde alınmıyor (4.6b), o yüzden maliyet doğmuyor. Alınsaydı
> `SerializesModels` trait'i gerekirdi.

---

## 4. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | Olayı dinleyicinin işiyle adlandırmak (`ClearCacheRequested`) | İkinci dinleyici eklendiğinde ad yalan olur | Olguyu adlandır (§2) |
| 2 | Olay sınıfına iş mantığı koymak | Olay bir veri kabıdır; mantık dinleyicide | Sadece kurucu |
| 3 | `readonly` yazmamak | Bir dinleyici olayı değiştirir, sonrakiler farklı gerçeklik görür | `readonly` (§3) |
| 4 | Henüz fırlatılamayan bir olay yazmak | Üç faz boyunca ölü kod (ders 26) | Tetikleyicisi olan olayı yaz |
| 5 | `make:event`'in trait'lerini olduğu gibi bırakmak | Kullanılmayan soyutlama, okuyanı yorar | Gerekeni bırak (§3) |
| 6 | Olayı `Invitation`'ın kendi klasörüne koymak | `app/Events/` Laravel'in beklediği yer | Standart klasör |

---

## 5. Kendin dene

Olay şu an **fırlatılmıyor** — model kablolaması 4.6c'de. Yine de sınıfı elle
kurup dinleyicinin çalıştığını görebiliriz (4.6b'nin denemesi bunu yapacak).

```powershell
php artisan tinker
```

```php
use App\Events\InvitationChanged;
use App\Models\Invitation;

$inv = Invitation::factory()->create();
$olay = new InvitationChanged($inv);

$olay->invitation->id;              // ⇒ ULID

// readonly gerçekten koruyor mu?
$olay->invitation = $inv;
// ⇒ Error: Cannot modify readonly property App\Events\InvitationChanged::$invitation

Invitation::withTrashed()->forceDelete();
```

Hata mesajını görmek önemli: `readonly` bir belge değil, **dil tarafından
zorlanan** bir kural.

```powershell
composer check
```

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Event (olay)** | "Şu oldu" bilgisini taşıyan veri kabı |
| **Listener (dinleyici)** | Bir olaya tepki veren sınıf |
| **Observer Pattern** | Öznenin, dinleyicilerini bilmeden durum değişikliği duyurması |
| **Gevşek bağ** | İki bileşenin birbirinin varlığını bilmeden çalışabilmesi |
| **Constructor property promotion** | Kurucu parametresini doğrudan özelliğe çeviren PHP 8 kısayolu |
| **`readonly`** | Yalnızca kurucuda atanabilen özellik (PHP 8.1) |
| **Broadcast** | Olayı WebSocket ile tarayıcıya iletme (bizde kullanılmıyor — K7) |

---

## 7. Sırada ne var?

**4.6b — `ClearInvitationCache` dinleyicisi.** Olay duyuruyu yapıyor; onu
dinleyip cache'i düşürecek sınıfı yazacağız. Orada iki konu var: Laravel'in
dinleyicileri **nasıl kendiliğinden bulduğu**, ve bu dinleyicinin neden
**kuyruğa alınmaması** gerektiği.
