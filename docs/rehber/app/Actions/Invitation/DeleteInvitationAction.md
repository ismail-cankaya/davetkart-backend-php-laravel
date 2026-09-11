# `app/Actions/Invitation/DeleteInvitationAction.php`

> **Faz:** 9 — Üretim hazırlığı, dosya A2.6
> **İlgili:** [`PublishInvitationAction.md`](PublishInvitationAction.md) ·
> [`../../Enums/OrderScope.md`](../../Enums/OrderScope.md) ·
> [`../../Contracts/PublishEntitlementResolver.md`](../../Contracts/PublishEntitlementResolver.md)
> **Kurallar:** **K3** (iş kuralı Action'da) · **K15** (soyutlama bütçesi) ·
> **E9** (check-then-act → kilit) · **K48** (olay modelden yapısal fırlar) ·
> **L1** (ucuzdan pahalıya)

---

## 1. Neden üç faz ertelendi ve neden bugün yazıldı?

`DeleteInvitationAction` Faz 6'da, Faz 8'de ve bu fazın başında planda vardı.
Üç kez ertelendi ve **üçü de doğruydu**: o günlerin hiçbirinde silmenin bir iş
kuralı yoktu. Gövde şuydu:

```php
public function destroy(Invitation $invitation): Response
{
    Gate::authorize('delete', $invitation);
    $invitation->delete();          // ← sarılacak bir kural yok
    return response()->noContent();
}
```

Bunu bir Action'a sarmak **tören** olurdu (**K15**: soyutlama bütçesi). Bugün
bir kural doğdu — *"yayından 3 gün geçmediyse hak geri alınabilir"* — ve sınıf
onunla birlikte geliyor.

> **Ders:** bir Action, sardığı kadar değil **taşıdığı kural** kadar
> değerlidir. Kuralsız bir Action, bir dosya daha ve bir dolaylılık daha
> demektir; kuralın doğduğu gün yazılan Action ise controller'ı ince tutan
> şeydir.

---

## 2. Kural

```
davetiye siliniyor
 ├─ published_at IS NULL            → hak hiç harcanmadı  → SERBEST
 ├─ published_at + N gün  gelecekte → pencere açık        → SERBEST
 └─ published_at + N gün  geçmiş    → pencere kapalı      → hak YANAR
```

`N` = `config('davetkart.orders.release_window_days')` = **3**.

**"Serbest" ne demek?** `orders.invitation_id = NULL`. **`scope` değişmez** —
`'invitation'` olarak kalır. Bu ayrım bu dilimin tamamının sebebi:

| `scope` | `invitation_id` | Resolver ne der |
|---|---|---|
| `invitation` | dolu | "bu davetiyeye hak var" |
| 🔴 `invitation` | `NULL` | **"hiçbir davetiyeye hak yok"** |
| `account` | `NULL` | "her davetiyeye hak var" |

Faz 9 öncesinde ikinci ve üçüncü satır **aynı** görünüyordu; silinen bir
davetiyenin 249 ₺'lik siparişi hesap geneline sınırsız yayın hakkı veriyordu.
Şimdi serbest bırakılmış sipariş **hiçbir şey** açmıyor — sahibi onu yeni bir
davetiyeye bağlayana kadar bekliyor (A2.7).

### "Hak yanar" ne demek, ne yapılır?

Hiçbir şey. Sipariş olduğu gibi kalır: `invitation_id` hâlâ silinmiş
davetiyeyi gösterir, `status` hâlâ `paid`. Muhasebe kaydı **silinmez** —
`orders.invitation_id`'nin `nullOnDelete` olmasının gerekçesi de buydu (K60
ailesi): ödeme kaydı kullanıcının bir tıkıyla yok olamaz.

---

## 3. 🔴 Bu bir SÜRE hesabı, bir TAKVİM hesabı değil

```php
$invitation->published_at->addDays(3)->isFuture()
```

Saat dilimi **hiç girmiyor** ve girmemeli. K71'de LCV son tarihi davetiyenin
saat dilimine taşınmıştı, çünkü *"15 Ağustos"* bir **yerin takvim günüdür**:
Berlin'deki misafirle İstanbul'daki misafir aynı anda farklı günlerdedir.

Burada ise "yayından 3 gün sonrası" bir **andan itibaren geçen süredir**. İki
`published_at` anı arasındaki fark, dünyanın neresinden bakılırsa bakılsın
aynıdır.

> Ders 58 (*"bir test saatin kaçında koştuğuna göre yeşil/kırmızı yanıyorsa
> yanlış olan testtir"*) burada **tersine** işliyor: orada saat dilimi
> unutulmuştu, burada eklenmesi hata olurdu. Ayırt edici soru hep aynı:
> **ölçtüğüm şey bir an mı, bir takvim günü mü?**

---

## 4. Kilit neden var? (E9)

```php
$fresh = Invitation::query()->whereKey(...)->lockForUpdate()->firstOrFail();
```

`PublishInvitationAction` ile birebir aynı desen. Kilitsiz senaryo:

```
İstek A (yayınla)                 İstek B (sil)
─────────────────                 ─────────────
published_at okur → NULL
                                  published_at okur → NULL
                                  "hiç yayınlanmamış" → hakkı SERBEST bırakır
published_at = now() yazar
                                  davetiyeyi siler
```

Sonuç: davetiye yayınlanmış **ve** hak serbest bırakılmış. Kullanıcı hem
yayınını yapmış hem de bir yayın hakkı kazanmış olurdu. İki akışın ortak
kilitlenebilir nesnesi **üst kayıttır** — Faz 5'in LCV kotası ve Faz 6'nın
medya kotasıyla aynı çözüm.

`firstOrFail()` ayrıca bir yan fayda verir: aynı davetiye iki kez silinirse
ikincisi **404** alır (soft-delete edilmiş satır varsayılan sorguda yoktur).

---

## 5. Neden `releasable()` süzgeci? CHECK zaten garanti etmiyor mu?

`orders_account_scope_has_no_invitation_check` gereği, `invitation_id` dolu
olan **her** siparişin kapsamı zaten `'invitation'`. Yani şu da çalışırdı:

```php
$fresh->orders()->update(['invitation_id' => null]);   // süzgeçsiz
```

Yazılmadı. Sebep: doğruluk bir **kısıtın yan etkisine** dayanmamalı. Bugün
kapsam listesine üçüncü bir değer eklenirse (kampanya kodu, hediye çeki) ve o
kapsam bir davetiyeye bağlanabiliyorsa, süzgeçsiz sorgu onu da sessizce
serbest bırakırdı. `OrderScope::isReleasable()` o soruyu **açıkça** soruyor ve
cevabı tek yerde tutuyor (**C3**).

---

## 6. Sıra: önce serbest bırak, sonra sil

Soft delete yabancı anahtarı tetiklemez (`nullOnDelete` yalnızca **kalıcı**
silmede çalışır), dolayısıyla ters sıra da bugün çalışırdı. Yine de siparişler
**önce** serbest bırakılıyor: yarın kalıcı silmeye geçilirse (bir saklama
süresi işi, A3+) sıra zaten doğru olur ve o gün kimsenin bunu hatırlaması
gerekmez.

> Faz 7'nin **L7**'siyle aynı refleks: *geri alınamayan işi en sona koy.*

---

## 7. Bu Action'ın YAPMADIKLARI (B6)

| Yapmaz | Nerede / neden |
|---|---|
| Yetki sorusu | Controller'da `Gate::authorize('delete', ...)` (P1) |
| Kalıcı silme | 🔴 Hiçbir yerde — kod tabanında `forceDelete()` yok |
| Diskteki dosyaları silmek | Medya satırları duruyor; soft delete onları koparmaz. Yetim dosya temizliği **A3** |
| İade işlemi | 🔴 Faz 9'da **yok** — sağlayıcı anahtarları yok. Pencere yalnızca *"iade uygun mu"* sorusunu **cevaplanabilir** kılıyor |
| Serbest bırakılan hakkı yeni davetiyeye bağlamak | **A2.7** — `PublishInvitationAction` |
| Kullanıcıya "hakkın serbest bırakıldı" demek | 🔴 **Hiçbir yerde.** Uç hâlâ `204` döner; §8'e bak |

---

## 8. 🔴 Açık uç: kullanıcı ne olduğunu öğrenemiyor

`DELETE /api/invitations/{id}` → **204, gövde yok**. Yani kullanıcı silme
sonrası hakkının serbest bırakılıp bırakılmadığını **bilmiyor**.

Bunu bilerek çözmedim. 204'ü gövdeli bir yanıta çevirmek frontend
sözleşmesini değiştirir ve K20 gereği yanıt metin taşıyamaz; doğru çözüm ya
`params` taşıyan bir zarf ya da bir *"siparişlerim"* ucudur — ikisi de bu
dilimin dışında. **B4** gereği kayda geçiyorum: bugün backend doğru davranıyor
ama kullanıcıya **anlatmıyor**. `FAZ-9.md` §açık kararlar'a girecek.

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Serbest bırakırken `scope`'u da `'account'` yapmak | 🔴 Tam kapattığımız delik geri açılır: tekil sipariş pakete döner |
| 2 | Kilitsiz okumak | Eşzamanlı yayınla+sil, hem yayın hem serbest hak üretir (§4) |
| 3 | Pencereyi saat dilimine çevirmek | Süre hesabı takvim hesabına dönüşür; gereksiz karmaşıklık ve hata (§3) |
| 4 | `published_at === null` kolunu unutmak | Hiç yayınlanmamış davetiyenin ödemesi de yanardı |
| 5 | Siparişi silmek / `status`'ü değiştirmek | Muhasebe kaydı kaybolur; iade izi kalmaz |
| 6 | Action'ı kuralsızken yazmak | Tören; K15 |

---

## 10. Kendin dene

```php
// php artisan tinker
use App\Models\{Invitation, Order};
use App\Actions\Invitation\DeleteInvitationAction;

$inv = Invitation::first();
$inv->published_at = now()->subDays(1);   // pencere AÇIK
$inv->save();

$order = Order::factory()->paid()->forInvitation($inv)->create();
$order->invitation_id;                     // $inv->id

app(DeleteInvitationAction::class)->handle($inv);

$order->refresh();
$order->invitation_id;                     // 🔴 null   — serbest
$order->scope;                             // OrderScope::Invitation  — DEĞİŞMEDİ
```

Pencere **kapalıyken** hak yanmalı:

```php
$inv2 = Invitation::factory()->create(['published_at' => now()->subDays(5)]);
$o2 = Order::factory()->paid()->forInvitation($inv2)->create();

app(DeleteInvitationAction::class)->handle($inv2);

$o2->refresh()->invitation_id;             // 🔴 hâlâ $inv2->id — yandı
```

**Mutasyon denemesi (T16):** `releaseWindowIsOpen()`'ı `return true;` yap.
A2.7'deki *"3 gün geçmiş davetiyenin hakkı yanar"* testi **kırmızıya
dönmeli**.

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Soft delete** | Satırı silmeyip `deleted_at` damgalamak |
| **`lockForUpdate()`** | Satırı transaction sonuna kadar başka yazıcılara kapatan kilit |
| **Check-then-act** | Okuyup karar verip yazma — arada durum değişirse bozulan desen |
| **Serbest bırakma (release)** | Hakkın bağlı olduğu kayıttan koparılıp yeniden kullanılabilir olması |
| **Saklama penceresi** | Bir işlemin geri alınabildiği süre |

---

## 12. Sırada ne var?

**A2.7 — `PublishInvitationAction` serbest siparişi bağlar.** Bugün serbest
bırakılan hak kimsenin işine yaramıyor; onu yeni bir davetiyeye bağlayan kod
yayın anında çalışacak. Ve asıl önemlisi: deliğin gerçekten kapandığını
kanıtlayan testler ile ilk `tests/Unit/OrderScopeTest.php` orada yazılacak.
