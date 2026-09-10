# `app/Enums/OrderScope.php`

> **Faz:** 9 — Üretim hazırlığı, dosya A2.1
> **İlgili:** [`OrderStatus.md`](OrderStatus.md) · [`../Models/Order.md`](../Models/Order.md) ·
> [`../Contracts/PublishEntitlementResolver.md`](../Contracts/PublishEntitlementResolver.md)
> **Kurallar:** **N4** (`null` ile `[]` farklı bilgilerdir) · **E1** (türetilebilen
> veri saklanmaz) · **E7** (sunucunun sahibi olduğu alanı sunucu kodu yazar) ·
> **K39** (CHECK değerleri enum'dan) · **K70** (sessiz varsayılan yok)

---

## 1. Problem: bir kolon, iki gerçek

Faz 7'de `orders` tablosu şöyle kuruldu (**K42**):

```php
$table->foreignUlid('invitation_id')->nullable()->constrained()->nullOnDelete();
```

Ve yayın hakkı şu sorguyla okundu (`OrderEntitlementResolver`):

```php
$query->whereNull('invitation_id')                 // "paket alımı"
    ->orWhere('invitation_id', $invitation->getKey());
```

Yani **`invitation_id IS NULL` = paket alımı** anlamına geliyordu. Bu, tablo ilk
yazıldığı gün doğruydu: `NULL` olmanın tek yolu paket satın almaktı.

Faz 9'da bir ikinci yol doğdu — **davetiyenin silinmesi**. `nullOnDelete` tam
olarak bunu yapar: davetiye kalıcı olarak silinince sipariş satırındaki
`invitation_id` `NULL`'a düşer. Ve o an sipariş, hiç kimse bir şey yazmadan,
**paket alımına dönüşür**:

```
Kullanıcı 249 ₺ Standart alır      →  orders(invitation_id = X, status = paid)
Davetiye X kalıcı olarak silinir    →  nullOnDelete → invitation_id = NULL
Aynı satır artık "paket" görünür     →  hesabın TÜM davetiyeleri bedava yayınlanır
```

🔴 Kimse hata yapmadı. Şema tutarlı, FK doğru, sorgu doğru. Yanlış olan tek şey
**`NULL`'un iki farklı gerçeği anlatması**: *"bu bir paket alımıydı"* ve *"bu
tekil siparişin davetiyesi artık yok"*. Bu, Faz 3'ün **N4** kuralının
(*`null` ile `[]` farklı bilgilerdir*) para katmanındaki hâli.

> **Genel ders:** bir kolonun anlamı, o kolona yazan **tüm** yolların
> toplamıdır. Bugün tek yol varsa anlam nettir; yarın ikinci bir yol açıldığında
> anlam, kimse dosyaya dokunmadan değişir. Bu yüzden kritik bir ayrım, bir
> alanın *yokluğuna* değil **varlığına** yazılır.

---

## 2. Çözüm: değişmeyeni değişenden ayır

| Soru | Kolon | Değişir mi? |
|---|---|---|
| Bu sipariş **ne satın aldı**? | `scope` | 🔴 **Hayır.** Satın alma anında yazılır, ölene kadar aynı kalır |
| Bu hak **şu an nerede duruyor**? | `invitation_id` | Evet. Serbest bırakılınca `NULL`, yeni davetiye bağlanınca o davetiye |

Dört kombinasyonun anlamı:

| `scope` | `invitation_id` | Anlamı | Yayın hakkı |
|---|---|---|---|
| `account` | `NULL` | Paket alımı | Sahibinin **her** davetiyesine |
| `invitation` | dolu | Tekil sipariş, bir davetiyeye bağlı | **Yalnızca** o davetiyeye |
| `invitation` | `NULL` | 🔴 **Serbest bırakılmış tekil sipariş** | **Hiçbirine** — bağlanana kadar |
| `account` | dolu | ❌ Anlamsız | — (CHECK kısıtıyla engellenecek, A2.2) |

Üçüncü satır bu enum'un varlık sebebi. `scope` olmasaydı o satır ikinci satırla
(paket) aynı görünürdü.

### Neden `released_at` gibi üçüncü bir kolon yok?

Çünkü türetilebilir: `scope = 'invitation'` **ve** `invitation_id IS NULL` zaten
"serbest" demek. **E1** — türetilebilen veri saklanmaz. Üçüncü kolon eklenseydi
iki doğruluk kaynağı doğardı ve ikisi bir gün ayrışırdı.

---

## 3. `grantsAcrossAccount()` — kural SQL'de değil enum'da

```php
public function grantsAcrossAccount(): bool
{
    return $this === self::Account;
}
```

`OrderStatus::grantsPublishRight()` ile **birebir aynı desen**. Sorguya doğrudan
`where('scope', 'account')` yazmak da çalışırdı; ama o zaman kural bir *string*
hâlinde sorgunun içine gömülürdü. Yarın üçüncü bir kapsam eklenirse (kampanya
kodu, hediye çeki) o string'in kopyalarını üç dosyada aramak gerekirdi.

Resolver bunun yerine yüklemi kullanarak kapsamları süzer:

```php
$across = array_values(array_filter(
    OrderScope::cases(),
    static fn (OrderScope $s): bool => $s->grantsAcrossAccount(),
));

$query->whereIn('scope', array_column($across, 'value'))
    ->orWhere('invitation_id', $invitation->getKey());
```

Yeni kapsam eklendiğinde **sorgu hiç değişmez** — Open/Closed'ın enum'daki hâli.

---

## 4. `isReleasable()` — silme akışının ilk sorusu

Bir paket siparişi hiçbir davetiyeye bağlı değildir; "serbest bırakılacak" bir
bağı da yoktur. Silme akışı (A2.5, `DeleteInvitationAction`) sırayla sorar:

```
1. Bu siparişin kapsamı serbest bırakılabilir mi?   → isReleasable()
2. Davetiye hiç yayınlandı mı?                       → published_at IS NULL ise evet, serbest
3. Yayından 3 gün geçti mi?                          → geçmediyse serbest, geçtiyse hak yanar
```

**L1** (ucuzdan pahalıya): en ucuz ve en çok eleyen soru başta. Bir tip
karşılaştırması, bir tarih aritmetiğinden ucuzdur.

---

## 5. 🔴 `default()` neden yok?

`OrderStatus` ve `InvitationStatus` enum'larının `default()` metodu var; bunun
**yok** ve bu bilinçli.

Bir siparişin durumu tahmin edilebilir: her sipariş `pending` doğar. Ama
**kapsamı** tahmin edilemez — onu satın alma yolunun kendisi bilir:

| Uç (K64) | Kapsam |
|---|---|
| `POST /api/invitations/{id}/checkout` | `invitation` |
| `POST /api/payments/checkout` | `account` |

İki yol da kapsamı **kesin olarak** biliyor. Varsayılan koyulsaydı, kapsamı
yazmayı unutan bir çağrı yolu sessizce ona düşerdi ve iki yönde de bozardı:

| Varsayılan | Unutulduğunda ne olur |
|---|---|
| `account` | 🔴 Tekil sipariş **hesap geneline** yayın hakkı verir — bedava sınırsız yayın |
| `invitation` | Paket alımı **hiçbir şey** açmaz; ödeme yapan kullanıcı yayınlayamaz |

**K70**'in refleksinin aynısı: *bilinmeyen sürücüde sessiz varsayılana düşme.*
Yanlış bir varsayılan, bir yapılandırma/kodlama hatasını bir **para** hatasına
çevirir. **E7** de aynı yöne bakar: sunucunun sahibi olduğu bir alanın değerini,
veritabanı varsayılanı değil **sunucu kodu** söyler.

> A2.2'deki migration mevcut satırları geri doldurmak için geçici bir varsayılan
> kullanacak, sonra onu **düşürecek**. Geçici varsayılan bir göç aracıdır;
> kolonun kalıcı özelliği değil.

---

## 6. Bu enum'un YAPMADIKLARI (B6)

| Yapmaz | Nerede yapılır |
|---|---|
| Kaç yayın hakkı verdiğini söylemek | 🔴 **Hiçbir yerde** — K43 hâlâ açık. Paket bugün sınırsız |
| 3 günlük pencereyi bilmek | `config/davetkart.php` (A2.5). Ticari bir dial, bir tip değil |
| Siparişi serbest bırakmak | `DeleteInvitationAction` (A2.5) |
| Serbest siparişi yeni davetiyeye bağlamak | `PublishInvitationAction` (A2.6) |
| İade etmek | 🔴 Faz 9'da **yok** — sağlayıcı anahtarları yok. `OrderStatus::Refunded` hazır bekliyor |

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `scope`'u sonradan değiştirmek | Değişmez olduğu varsayımı kırılır; tekil bir sipariş pakete dönüşür |
| 2 | `default()` eklemek | Kapsamı yazmayı unutan yol sessizce yanlış hak üretir (§5) |
| 3 | Resolver'da `whereNull('invitation_id')`'ı bırakmak | Serbest bırakılmış tekil sipariş paket gibi davranır — düzeltilen delik geri gelir |
| 4 | `released_at` kolonu eklemek | İki doğruluk kaynağı; **E1** ihlali |
| 5 | CHECK kısıtını elle yazmak | Enum değişince kısıt sessizce eskir (**K39**) |
| 6 | `scope = 'account'` + dolu `invitation_id` yazmak | Anlamsız satır; A2.2'nin CHECK'i bunu reddedecek |

---

## 8. Kendin dene

```php
// php artisan tinker
use App\Enums\OrderScope;

OrderScope::values();                        // ['invitation', 'account']
OrderScope::Account->grantsAcrossAccount();  // true
OrderScope::Invitation->grantsAcrossAccount();// false
OrderScope::Invitation->isReleasable();      // true
OrderScope::Account->isReleasable();         // false

OrderScope::from('paket');                   // 🔴 ValueError — enum sınırı tutuyor
OrderScope::default();                       // 🔴 Error: undefined method — BİLEREK yok
```

**Mutasyon denemesi (T16):** `grantsAcrossAccount()`'u `return true;` yap.
A2.7'deki *"başkasının tekil siparişi bu davetiyeyi açamaz"* testi **kırmızıya
dönmeli**. Dönmüyorsa test kapsamı, kuralın kendisini değil yalnızca mutlu
yolu sınıyor demektir.

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Backed enum** | Her case'in bir ham değeri (burada `string`) olan PHP 8.1 enum'u |
| **Entitlement (hak)** | Bir kullanıcının bir eylemi yapmaya yetkili olması — burada "yayınlayabilir" |
| **Scope (kapsam)** | Bir hakkın kimi/neyi kapsadığı |
| **Serbest bırakma (release)** | Bir hakkın bağlı olduğu kayıttan koparılıp yeniden kullanılabilir hâle gelmesi |
| **Open/Closed** | Genişlemeye açık, değişikliğe kapalı olma ilkesi |

---

## 10. Sırada ne var?

**A2.2 — `database/migrations/..._add_scope_to_orders_table.php`.** Kolon,
enum'dan beslenen CHECK kısıtı, `scope='account'` iken `invitation_id`'nin
`NULL` olmasını zorlayan ikinci CHECK (**E11** — çok kolonlu değişmez kısıta
yazılır) ve mevcut satırların geri doldurulması.
