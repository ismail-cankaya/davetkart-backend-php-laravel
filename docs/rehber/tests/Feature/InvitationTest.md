# `tests/Feature/InvitationTest.php`

> **Kod dosyası:** `tests/Feature/InvitationTest.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.12 (son)
> **Bağlantılı:** [`AuthTest.md`](AuthTest.md) · [`TestCase.md`](../TestCase.md) (T13)

---

## 1. Bu dosya fazın kanıtı

Önceki 11 dosya bir şeyler **iddia ediyor**. Bu dosya onları **doğruluyor**.

18 test, dört grup:

| Grup | Kaç | Neyi koruyor |
|---|---|---|
| Kimlik | 2 | Token olmadan hiçbir uca girilemez |
| Sahiplik 🔴 | 5 | Başkasının davetiyesine erişilemez |
| Senkronizasyon 🔴 | 4 | Program doğru ekleniyor/güncelleniyor/siliniyor |
| Sözleşme | 7 | Frontend'in beklediği biçim bozulmuyor |

Testin değeri "geçiyor" olmasında değil, **bozulunca kırılmasında**dır. Her test
bir cümleyi savunuyor; o cümle bir gün yanlış olursa test kırmızı yanar.

---

## 2. 🔴 T13 olmadan bu dosyanın yarısı yalan olurdu

Faz 3'ün girişinde `tests/TestCase.php`'e bir yardımcı eklemiştik:

```php
protected function forgetAuthState(): void
{
    $this->app['auth']->forgetGuards();
}
```

Sebebi burada görünüyor. `RequestGuard` çözdüğü kullanıcıyı önbelleğe alır ve
`setRequest()` onu temizlemez (kaynağı `TestCase.md` §2.2'de). Aynı test
metodunda ikinci bir kimlikli istek yaparsan, guard **token'a hiç bakmadan**
önceki kullanıcıyı döndürür.

Bu dosyadaki iki test birden fazla kimlikli istek yapıyor:

```php
$yokOlan = $this->withToken($token)->getJson(...);

$this->forgetAuthState();                        // ← olmazsa ikinci istek
                                                 //   token'i hic okumaz
$baskasinin = $this->withToken($token)->getJson(...);
```

Çağırmasaydık ikinci istek yine geçerdi — ama **hiçbir şey doğrulamamış**
olurdu. Faz 2'nin **T10**'unun kardeşi: *bir testin yeşil yanması, doğrulamak
istediğin şeyi doğruladığı anlamına gelmez.*

### Neden `actingAs()` hiç kullanılmadı?

`actingAs()` guard'ı atlar ve kullanıcıyı doğrudan yerleştirir (T10). Bu dosyada
**token yolunu** test ediyoruz: `auth:sanctum` middleware'i, Policy ve rota
kısıtı birlikte çalışmalı. `actingAs` ile yazsaydık middleware zincirinin bir
kısmı hiç koşmazdı.

---

## 3. Sahiplik testleri — fazın kalbi

### 3.1 Dört ayrı uç, dört ayrı test

```php
owner_cannot_read_another_users_invitation()      → GET    → 404
owner_cannot_update_another_users_invitation()    → PUT    → 404
owner_cannot_delete_another_users_invitation()    → DELETE → 404
index_returns_only_the_owners_invitations()       → GET    → yalnizca kendisininki
```

Neden dördü ayrı? Çünkü **dört farklı mekanizma** koruyor:

| Uç | Koruyan |
|---|---|
| `show` / `update` / `destroy` | `Gate::authorize()` + `InvitationPolicy` |
| `index` | **Sorgunun kendisi** — `$user->invitations()` |

`index` testinin ayrı olmasının sebebi bu: orada Policy `viewAny` her zaman
`true` döner. Biri controller'da `Invitation::query()->get()` yazsa **hiçbir
policy onu durdurmazdı** — yalnızca bu test durdurur.

### 3.2 Yalnızca durum kodu yetmez

```php
->assertNotFound();

$this->assertDatabaseHas('invitations', ['id' => $mehmetinki->id, 'title' => 'Dokunma']);
```

404 dönmüş olması, güncellemenin yapılmadığını **kanıtlamaz** — teoride yazma
gerçekleşip sonra hata dönebilirdi. Veritabanını da kontrol ediyoruz.

> **Kural:** Bir işlemin *yapılmadığını* test ediyorsan, yanıtı değil **etkiyi**
> doğrula.

### 3.3 🔴 T11 — ayırt edilemezlik ham gövdeyle doğrulanır

```php
$this->assertSame($yokOlan->getStatusCode(), $baskasinin->getStatusCode());
$this->assertSame($yokOlan->getContent(), $baskasinin->getContent());
```

3.7'de "404 dönüyoruz çünkü 403 kaynağın varlığını doğrular" demiştik. Bu test
o iddiayı sınıyor — ve `assertJsonPath` ile değil, **ham metin
karşılaştırmasıyla**.

Sebebi Faz 2'nin **T11** kuralı: *`assertJsonPath` yalnızca baktığın yeri kontrol
eder.* İki yanıt `error.code` alanında aynı olup başka bir alanda (bir başlık, bir
sıralama farkı, fazladan bir anahtar) ayrılabilir. Saldırgan da tam olarak o
farkı arar.

`config(['app.debug' => false])` satırı şart: `debug` bloğu exception sınıfını
taşır (`ModelNotFoundException` ile `AuthorizationException` farklıdır) ve
üretimde o blok zaten hiç çalışmaz (H3). Testi üretim koşullarında yapıyoruz.

### 3.4 Alt kaynak IDOR'u

```php
a_timeline_event_of_another_invitation_cannot_be_overwritten()
```

Bu testi kaçırmak kolay. Üst kaynağın sahipliği doğrulanmıştır — Ayşe **kendi**
davetiyesini güncelliyor, Policy sorunsuz geçer. Ama gövdedeki program adımının
`id`'si Mehmet'in davetiyesine ait.

3.10'un `$existing` kapsamı olmasaydı, o satır **ezilirdi**.

Test üç şeyi birden doğruluyor:

```php
$this->assertDatabaseHas('timeline_events', [... 'title' => $kurban->title]);  // kurban duruyor
$this->assertSame(1, $ayseninki->timelineEvents()->count());                   // yeni satir acildi
$this->assertNotSame($kurban->id, $ayseninki->timelineEvents()->value('id'));  // farkli satir
```

Üçüncüsü önemli: yalnızca "kurban değişmedi" deseydik, kodun satırı **taşımış**
olma ihtimalini dışlamazdık.

---

## 4. Senkronizasyon testleri

### 4.1 Üç yol tek istekte

```php
'timelineEvents' => [
    ['id' => (string) $ids[2], 'time' => '20:00', 'title' => 'Yemek'],   // GUNCELLE
    ['id' => null,             'time' => '23:00', 'title' => 'Havai Fisek'], // EKLE
]                                                                        // $ids[0], $ids[1] → SIL
```

Tek istekte üç davranış: eşleşeni güncelle, `null` id'liyi oluştur, listede
olmayanı sil. Ayrı ayrı test etmek de mümkündü ama gerçek autosave isteği zaten
böyle görünür — testin **gerçek kullanıma benzemesi** değerlidir.

Son satır sıranın konumdan geldiğini doğruluyor:

```php
$this->assertDatabaseHas('timeline_events', ['id' => $ids[2], 'sort_order' => 0]);
```

Bu adım eskiden 2. sıradaydı; listede başa geldiği için `sort_order` 0 oldu.

### 4.2 🔴 `null` ile `[]` — iki ayrı test

```php
omitting_timeline_events_leaves_the_program_untouched()   → 3 adim korunur
empty_timeline_events_deletes_the_whole_program()          → 0 adim kalir
```

Bu ikisi 3.8, 3.9 ve 3.10 boyunca tekrarladığımız ayrımın kanıtı. Kod bir gün
`null`'ı `[]` gibi davranırsa **ilk test kırılır** — ve o hata olmadan üretime
gitseydi, kısmi bir güncelleme kullanıcıların programını sessizce silerdi.

T6'nın klasik biçimi: bir davranışın hem **varlığı** hem **yokluğu**.

---

## 5. Sözleşme testleri

### 5.1 Tip kontrolü neden değer kontrolünden önemli?

```php
$this->assertIsString($data['invitation']['timelineEvents'][0]['id']);
$this->assertIsBool($data['invitation']['showGift']);
```

Frontend `id: string` ve `showGift: boolean` bekliyor. Değer doğru ama **tip**
yanlış olursa (örneğin `7` yerine `"7"`, `1` yerine `true`) çoğu şey çalışmaya
devam eder — ta ki biri `=== true` veya `===` karşılaştırması yazana kadar.

Bu testler 3.4'teki model cast'lerinin ve 3.9'daki `(string)` dönüşümünün
bekçisidir. Cast'i kaldıran biri anında kırmızı görür.

### 5.2 Tarih biçimleri

```php
$this->assertSame('2026-09-12T19:00', $data['invitation']['date']);
```

3.9 §3'te anlatılan tuzağın bekçisi. Biri "ISO-8601 daha standart" diye
`toIso8601String()` yazarsa bu test kırılır — ve kırılmasaydı kullanıcıların
etkinlik tarihi sessizce silinirdi.

### 5.3 T6: yokluk da doğrulanır

```php
$this->assertArrayNotHasKey('sortOrder', $data['invitation']['timelineEvents'][0]);
$this->assertArrayNotHasKey('userId', $data['invitation']);
$this->assertArrayNotHasKey('publishedAt', $data['invitation']);
```

Beyaz listenin (C1) testi budur. Biri `$invitation->toArray()` yazarsa **hepsi
birden** kırılır.

`assertJsonStructure` bu işi yapmaz: o "şu anahtarlar var mı?" diye sorar,
"fazlası var mı?" diye sormaz. Sızıntıyı yakalayan soru ikincisidir.

### 5.4 Hata, hangi satırın bozuk olduğunu söylemeli

```php
$this->assertArrayHasKey('invitation.timelineEvents.1.time', $fields);
$this->assertArrayNotHasKey('invitation.timelineEvents.0.time', $fields);
```

İki satırlık bir program gönderiliyor; ikincisinin saati bozuk. Frontend'in
doğru input'u işaretleyebilmesi için hata anahtarının **indeksi taşıması**
gerekiyor.

İkinci satır yine T6: geçerli satır için hata **üretilmemeli**. Yalnızca
birincisini yazsaydık, "tüm satırlara hata basan" bir kod da testi geçerdi.

### `assertJsonPath` neden kullanılmadı?

`error.fields` düz bir haritadır ve anahtarları **nokta içerir**:

```json
"fields": { "invitation.timelineEvents.1.time": [ ... ] }
```

`assertJsonPath` yolu noktalardan böler, dolayısıyla bu anahtara ulaşamaz.
`json()` ile diziyi alıp `assertArrayHasKey` kullanmak doğru araç.

---

## 6. Yardımcı metotlar

```php
private function tokenFor(User $user): string
{
    return $user->createToken('api')->plainTextToken;
}
```

Faz 2'nin `AuthTest`'inde `registerPayload()` vardı; aynı fikir. Testin **konusu
olmayan** kurulum tek satıra iner ve okuyan kişi neyin önemli olduğunu görür.

```php
private function payload(array $overrides = []): array
```

Geçerli bir gövde döndürür; test kendi konusu olan alanı ezer:

```php
$this->payload(['timelineEvents' => [...]])    // bu test program hakkinda
$this->payload(['title' => 'Yeni Baslik'])     // bu test baslik hakkinda
```

`array_merge` sırası önemli: `$overrides` **sonra** geldiği için varsayılanları
ezer.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | İki kimlikli istek arası `forgetAuthState()` yok | İkinci istek token'a bakmaz — **boş yeşil** | T13 |
| 2 | `actingAs()` kullanmak | Middleware zinciri atlanır | `withToken()` (T10) |
| 3 | Yalnızca durum kodu doğrulamak | Yazma gerçekleşmiş olabilir | Veritabanını da kontrol et |
| 4 | Ayırt edilemezliği `assertJsonPath` ile ölçmek | Baktığın yer dışındaki fark kaçar | Ham gövde (T11) |
| 5 | `app.debug` ayarını bırakmak | `debug` bloğu iki yanıtı farklılaştırır | `config(['app.debug' => false])` |
| 6 | `assertJsonStructure` ile sızıntı aramak | "Fazlası var mı?" sorulmaz | `assertArrayNotHasKey` |
| 7 | Yalnızca `null` veya yalnızca `[]` test etmek | Ayrım bozulunca fark edilmez | İkisini de |
| 8 | Fabrikadan gelen rastgele değere assert etmek | Kararsız test | `create([...])` ile açıkça geç |
| 9 | Alt kaynak IDOR'unu atlamak | En sinsi açık test edilmez | Ayrı test |

---

## 8. Çalıştırma

Yalnızca bu dosya:

```powershell
php artisan test --filter=InvitationTest
```

Tek bir test:

```powershell
php artisan test --filter=a_timeline_event_of_another_invitation_cannot_be_overwritten
```

Tam kalite kapısı:

```powershell
composer check
```

### Testlerin gerçekten iş yaptığını nasıl anlarsın?

Bir testin değerini ölçmenin yolu **kodu bilerek bozmaktır**. Dene:

| Bozulacak yer | Kırılması gereken test |
|---|---|
| `InvitationPolicy::owns()` → `return true;` | 3 sahiplik testi |
| `Controller::index` → `Invitation::query()->get()` | `index_returns_only_the_owners_invitations` |
| `SyncTimelineEventsAction` → `TimelineEvent::find($id)` | `a_timeline_event_..._cannot_be_overwritten` |
| `UpdateInvitationAction` → `$timelineEvents ?? []` | `omitting_timeline_events_leaves_the_program_untouched` |
| `InvitationPayloadResource` → `toIso8601String()` | `response_matches_the_frontend_contract` |
| `Invitation` modelinden `'user_id' => 'integer'` sil | 3 sahiplik testi (`===` tutmaz) |
| `TestCase::forgetAuthState()` → boş gövde | `missing_and_forbidden_..._indistinguishable` |

Hiçbiri kırılmıyorsa test değil, süs yazmışsındır.

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **`RefreshDatabase`** | Her testte veritabanını temiz başlatan trait |
| **`withToken()`** | İsteğe `Authorization: Bearer` başlığı ekler |
| **`assertSoftDeleted`** | Satırın `deleted_at` damgası aldığını doğrular |
| **`assertDatabaseMissing`** | Satırın tabloda olmadığını doğrular |
| **Boş yeşil test** | Geçen ama hiçbir şey doğrulamayan test |
| **Kararsız test** (*flaky*) | Kod değişmeden bazen geçen bazen kalan test |
| **Mutasyon denemesi** | Kodu bilerek bozup testin kırılıp kırılmadığına bakmak |

---

## 10. Faz 3 bitti — sırada ne var?

Bu, fazın son kod dosyasıydı. Kalan iki iş:

1. **`docs/rehber/fazlar/FAZ-3.md`** — faz özeti: hedefler, yazılan 12 dosya,
   kurulan kurallar (K37-K44, D6, T13) ve Faz 4'e devir
2. **`docs/rehber/fazlar/FAZ-3-ELLE-DOGRULAMA.md`** — tarayıcı ve PowerShell ile
   uçtan uca doğrulama betiği

Ve fazın **asıl bitiş ölçütü** henüz karşılanmadı: *"Dashboard'da davetiye
listesi gerçek veritabanından geliyor; editörde autosave çalışıyor."*

Bunun için frontend tarafında üç iş var (`claude/Notlar/03-FRONTEND-YAPILACAKLAR.md`):

- `services/invitations.ts` → K37'nin REST koleksiyonuna uyarlanması
- `useDashboardData.ts` → tek kayıt varsayımının kaldırılması
- `TimelineEditor.tsx` → K44: `id: null` + ayrı `localKey`
