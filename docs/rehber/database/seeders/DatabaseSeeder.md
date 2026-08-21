# `database/seeders/DatabaseSeeder.php`

> **Kod dosyası:** `database/seeders/DatabaseSeeder.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.6 (3/3)
> **Bağlantılı:** [`InvitationFactory.md`](../factories/InvitationFactory.md)

---

## 1. Seeder ile fabrika farkı

İkisi de sahte veri üretir ama amaçları ayrıdır:

| | Fabrika | Seeder |
|---|---|---|
| Kim çağırır | Test kodu | Sen, elle (`php artisan db:seed`) |
| Ne zaman | Her testte, otomatik | Geliştirme veritabanını hazırlarken |
| Ömrü | Test bitince silinir (`RefreshDatabase`) | Sen silene kadar durur |
| Amacı | Tek bir senaryoyu kurmak | **Tarayıcıda gezilebilir** bir başlangıç durumu |

Seeder'ın işi: `migrate:fresh` sonrası boş kalan veritabanını, frontend'i açıp
gerçekten deneyebileceğin hâle getirmek.

```powershell
php artisan migrate:fresh --seed
```

---

## 2. 🔴 Bu dosya Faz 2'den beri **bozuktu**

Devraldığımız hâli:

```php
User::factory()->create([
    'name' => 'Test User',           // ❌ boyle bir kolon YOK
    'email' => 'test@example.com',
]);
```

`users` tablosunda `name` kolonu yok — K35 ile `first_name` ve `last_name` olarak
ayrılmıştı. Bu satır çalıştırılsaydı SQL hatası verirdi:

```
column "name" of relation "users" does not exist
```

Neden fark edilmedi? Çünkü **hiç çalıştırılmadı.** `composer check` zinciri
seeder'ı koşturmaz; testler `RefreshDatabase` kullanır ve seeder'a uğramaz.

Bu, Faz 2'de yakaladığımız iki kırık testle aynı sınıf: **çalıştırılmayan kod,
doğru olduğu varsayılan koddur.** Faz 3'te üç ayrı örneğini gördük.

> **Alışkanlık:** Bir dosya hiçbir otomatik kontrolün yolunda değilse, onu elle
> çalıştırmak senin sorumluluğundur. "Yazıldı" ile "çalışıyor" farklı durumlardır.

---

## 3. Idempotans — iki kez çalıştırılabilir olmak

```php
$user = User::query()->where('email', self::DEMO_EMAIL)->first()
    ?? User::factory()->create([...]);

if ($user->invitations()->exists()) {
    $this->command?->info('Tohumlama atlandi: demo veri zaten var.');

    return;
}
```

**Idempotans** = aynı işlemi bir kez de yapsan on kez de yapsan sonucun aynı
olması.

Bu koruma olmadan `php artisan db:seed`'i ikinci kez çalıştırmak:

```
SQLSTATE[23505]: Unique violation: duplicate key value violates
unique constraint "users_email_unique"
```

Ya da (e-posta rastgele olsaydı) her çalıştırmada iki davetiye daha eklerdi.
İkisi de can sıkıcı; birincisi seni durdurur, ikincisi sessizce çöp biriktirir.

### `??` burada ne yapıyor?

```php
User::query()->where(...)->first() ?? User::factory()->create([...])
```

`first()` kayıt bulamazsa `null` döner; `??` o durumda sağ tarafı çalıştırır.
Yani "varsa onu al, yoksa üret".

🔴 Sağ taraf **yalnızca gerektiğinde** çalışır — buna kısa devre denir. Faz 2'de
`LoginUserAction`'da kısa devrenin bir savunmayı **çökerttiğini** görmüştük
(A4). Burada ise tam istediğimiz şey: kullanıcı varsa gereksiz yere yenisini
üretme.

> Aynı dil özelliği bir yerde tuzak, başka yerde araç. Fark, o satırda **her
> zaman çalışması gereken bir şey** olup olmamasında.

### `$this->command?->info(...)` — bu `?->` neden var?

Seeder programatik olarak da çağrılabilir (test içinden `$this->seed()` gibi). O
durumda `$this->command` `null`'dır ve `->info()` çağrısı ölümcül hata verirdi.

Burada `?->` gerçekten gerekli — çünkü sonucu `??` ile yakalamıyoruz, doğrudan
bir **metot çağırıyoruz** (`LoginUserAction.md` §3.1'deki ayrımın diğer yüzü).

---

## 4. Üretilen başlangıç durumu

```php
Invitation::factory()->for($user)->withTimeline()->create(['title' => 'Taslak Davetiye']);
Invitation::factory()->for($user)->published()->withTimeline()->create(['title' => 'Yayindaki Davetiye']);
```

İki davetiye, **bilerek farklı durumlarda**:

| Davetiye | `status` | Neyi denemeni sağlar |
|---|---|---|
| Taslak Davetiye | `saved` | Dashboard'un "Kayıtlı" sekmesi, editörde açma |
| Yayındaki Davetiye | `published` | "Yayında" sekmesi, `/invite/{id}` bağlantısı |

Tek davetiye üretseydik, dashboard'un iki sekmeli yapısını elle deneyemezdik.
**Seeder verisi, denemek istediğin ekranın gerektirdiği çeşitliliği taşımalıdır.**

Başlıkları `create([...])` ile açıkça veriyoruz ki pgAdmin'de veya ekranda
hangisinin hangisi olduğu belli olsun — fabrikanın sabit başlığı ikisinde de aynı
olurdu.

---

## 5. `WithoutModelEvents` ne işe yarıyor?

```php
use WithoutModelEvents;
```

Laravel'de bir model kaydedildiğinde olaylar (`created`, `saved`) tetiklenir.
Faz 4'te `InvitationPublished` olayına bağlı bir dinleyici (cache temizleme)
gelecek.

Tohumlama sırasında bu olayların çalışmasını istemeyiz: cache temizlemek,
bildirim göndermek, kuyruk işi açmak — hiçbiri bir geliştirme veritabanını
doldururken anlamlı değil.

Bu trait olayları o çağrı boyunca susturur. Laravel'in varsayılan seeder'ında
zaten vardı; kaldırmadık.

---

## 6. Demo hesap

```php
$this->command?->info(sprintf('Demo hesap: %s / %s', self::DEMO_EMAIL, UserFactory::PASSWORD));
```

Çıktı:

```
Demo hesap: test@ornek.test / password
```

Parolayı `UserFactory::PASSWORD` sabitinden okuyoruz, elle yazmıyoruz. Fabrika
parolayı bir gün değiştirirse bu mesaj kendiliğinden doğru kalır — **tek doğruluk
kaynağı** (C3).

⚠️ Bu hesap yalnızca geliştirme içindir. Seeder üretimde çalıştırılmaz;
`AppServiceProvider` Faz 0'da yıkıcı komutları üretimde zaten engelliyor.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | Var olmayan kolona yazmak | SQL hatası — ama ancak çalıştırınca görürsün | Seeder'ı elle çalıştır |
| 2 | Idempotans düşünmemek | İkinci `db:seed` patlar veya çöp biriktirir | Varlık kontrolü |
| 3 | Parolayı elle yazmak | Fabrika değişince mesaj yalan olur | `UserFactory::PASSWORD` |
| 4 | Tek durumda veri üretmek | Ekranın diğer hâlini deneyemezsin | Farklı durumlar üret |
| 5 | `$this->command->info()` (`?->` yok) | Programatik çağrıda ölümcül hata | `?->` |
| 6 | Seeder'a iş kuralı yazmak | İki doğruluk kaynağı doğar | Fabrika + Action kullan |

---

## 8. Kendin dene

```powershell
php artisan migrate:fresh --seed
```

Beklenen çıktı:

```
INFO  Seeding database.
Demo hesap: test@ornek.test / password
```

Doğrulama:

```powershell
php artisan tinker
```

```php
use App\Models\User;
use App\Models\Invitation;

User::query()->count();                     // => 1
Invitation::query()->count();               // => 2
Invitation::query()->pluck('status');       // => ["saved", "published"]

$inv = Invitation::query()->where('status', 'published')->first();
$inv->timelineEvents->count();              // => 3
$inv->id;                                   // => paylasilabilir ULID
```

Idempotans sınaması — ikinci kez çalıştır:

```powershell
php artisan db:seed
```

```
Tohumlama atlandi: demo veri zaten var.       ✅ hata yok, kopya yok
```

Ardından:

```powershell
composer check
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Seeder** | Veritabanını başlangıç verisiyle dolduran sınıf |
| **Idempotans** | Aynı işlemin tekrarının sonucu değiştirmemesi |
| **Model olayı** | Kayıt oluşturulunca/güncellenince tetiklenen kanca |
| **`migrate:fresh --seed`** | Tüm tabloları düşür, yeniden kur, tohumla |
| **Kısa devre** | `??` ve `\|\|` gibi operatörlerin sağ tarafı gerektiğinde çalıştırması |

---

## 10. Sırada ne var?

**3.7 — `app/Policies/InvitationPolicy.php`**

Fazın en kritik güvenlik dosyası:

- "Bu davetiye senin mi?" sorusunun tek cevap yeri
- 🔴 Reddin neden **403 değil 404** olduğu (K20 §3.2 — 403 kaynağın varlığını
  doğrular)
- Policy'nin controller'a nasıl bağlandığı ve `authorizeResource` kısayolu
