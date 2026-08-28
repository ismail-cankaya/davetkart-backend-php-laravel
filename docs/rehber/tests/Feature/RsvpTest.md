# `tests/Feature/RsvpTest.php`

> **Kod dosyası:** `tests/Feature/RsvpTest.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.13
> **29 test** · 8 tanesi güvenlik regresyonu
> **Kardeş dosyalar:** [`InvitationTest.md`](InvitationTest.md) ·
> [`PublicInvitationTest.md`](PublicInvitationTest.md)

---

## 1. 🔴 Bu dosyanın merkezî fikri: yanıt hiçbir şey kanıtlamaz

Faz 5'in üç savunması **başarılı görünen** ya da **ayırt edilemeyen** yanıtlar
üretiyor:

| Savunma | Yanıt | Yanıt neyi kanıtlar |
|---|---|---|
| Honeypot | `201 Created` | **Hiçbir şey** — gerçek kayıtla aynı |
| Kota reddi | `403` + boş `params` | Sayı sızmadığını, ama kotanın doğru sayıldığını değil |
| IDOR (başkasının LCV'si) | `404` | Kaynağın yokluğunu — silinmediğini değil |

**T14** bu yüzden bu dosyanın omurgası:

> Bir işlemin **yapılmadığını** test ediyorsan yanıtı değil **etkiyi** doğrula.

Somut olarak: `honeypot_submission_is_not_persisted` testinden
`assertDatabaseCount('rsvps', 0)` satırı silinirse, `SubmitRsvpAction`'ın
honeypot bloğu tamamen kaldırılsa bile test **yeşil kalır**. Faz 4'ün 34.
dersinin (üç IDOR testi Policy'den değil eşleşmeyen rotadan 404 alıyordu) bu
fazdaki karşılığı budur.

---

## 2. Testler hangi soruyu soruyor?

### Görünürlük (5 test)

| Test | Soru |
|---|---|
| `guest_can_submit_an_rsvp_to_a_published_invitation` | Mutlu yol çalışıyor mu? |
| `rsvp_to_an_unpublished_invitation_is_rejected` | Taslağa yazılabiliyor mu? |
| `rsvp_is_rejected_when_the_module_is_closed` | Kapalı modüle yazılabiliyor mu? |
| `closed_module_and_missing_invitation_are_indistinguishable` | İkisi ayırt edilebiliyor mu? |
| `a_malformed_invitation_id_never_reaches_the_database` | Çöp kimlik sorgu açtırıyor mu? |

**T6** burada iş başında: bir davranışın hem **varlığı** hem **yokluğu** test
ediliyor. Yalnızca "taslağa yazılamaz" testi olsaydı, LCV ucu tamamen bozulsa
da (hiçbir şey yazamasa da) yeşil kalırdı.

**T11** — `closed_module_and_missing_invitation_are_indistinguishable`
`assertJsonPath` değil **ham gövde** karşılaştırması yapıyor:

```php
$this->assertSame($this->body($a), $this->body($b));
```

`assertJsonPath` yalnızca **baktığın yeri** kontrol eder. Bir gün gövdeye
`"reason": "module_closed"` gibi bir alan eklense, path testi bunu görmezdi.

### Doğrulama (4 test)

`status_must_be_a_known_value` doğrudan **D6**'nın bekçisi:

```php
$this->assertSame('in', $response->json('error.fields.status.0.rule'));
```

Biri `Rule::enum(RsvpStatus::class)` yazarsa kural adı
`illuminate_validation_rules_enum` olur ve bu test kırılır. Faz 3'te
`Password::min(8)` ile yaşanan sızıntının tekrarlanmasını **yapısal olarak**
engelliyor.

`guest_count_is_capped_by_configuration` iki şeyi birden doğruluyor: kuralın adı
(`max`) **ve** parametresinin dışarı verildiği (`params.max === 10`). İkincisi
H9 beyaz listesinin çalıştığının kanıtı.

### Honeypot (3 test) 🔴

Üçü birlikte anlam kazanıyor:

1. `honeypot_submission_looks_successful` → `201` dönüyor (bota "yakalandın"
   demiyoruz)
2. `honeypot_submission_is_not_persisted` → **satır yok** (T14)
3. `honeypot_response_has_the_same_shape_as_a_real_one` → anahtar listeleri
   aynı

Üçüncüsü neden gerekli? Çünkü bot bir **fark** arar. Gerçek yanıtta `createdAt`
varken sahte yanıtta olmasaydı, bot iki gönderim yapıp farkı ölçerek honeypot'un
varlığını öğrenirdi.

> Değerler karşılaştırılmıyor, **anahtarlar** karşılaştırılıyor: `id` ve
> `createdAt` zaten her kayıtta farklı.

### Son tarih (3 test)

`rsvp_is_accepted_on_the_deadline_day` bu fazın en sinsi hatasının bekçisi:

```php
$inv = $this->published(['rsvp_deadline' => now()->toDateString()]);
$this->postJson(...)->assertCreated();
```

`SubmitRsvpAction`'da `lessThan(now()->startOfDay())` yerine `isPast()` yazılsa
bu test **kırılır** — çünkü tarih kolonu günün `00:00`'ına denk gelir ve son gün
boyunca "geçmiş" görünür. Test olmasaydı hata üretimde "bazı kullanıcılar son
gün gönderemiyor" olarak ortaya çıkardı.

### Kota (5 test) 🔴

`quota_counts_guests_not_rows` bu fazın **imza testidir**:

```php
config(['davetkart.tiers.standart.rsvp_limit' => 5]);
Rsvp::factory()->for($inv)->guests(4)->create();
$this->postJson(..., ['guestCount' => 2])->assertForbidden();
```

| Ölçüm | Hesap | Sonuç |
|---|---|---|
| `COUNT(*)` | 1 kayıt + 1 = 2 ≤ 5 | ✅ geçerdi — **test kırılırdı** |
| `SUM(guest_count)` | 4 + 2 = 6 > 5 | ❌ reddedilir — **test geçer** |

Yani sayılar, `COUNT(*)` mutasyonunu **öldürecek** şekilde seçildi. `guests(4)`
yerine `guests(1)` yazsaydık test iki uygulamada da yeşil yanardı ve hiçbir şey
kanıtlamazdı.

`declined_rsvps_do_not_consume_quota` ve `pending_rsvps_consume_quota` ikilisi
K50'nin iki yönünü de kapatıyor — biri olmadan enum'daki `match` kolunu
değiştirmek fark edilmezdi.

`quota_rejection_does_not_leak_counters` bir **sızıntı testidir**:
`assertJsonMissingPath('error.params')` + ham gövdede `remaining`/`limit`
kelimelerinin geçmediğini doğrular.

### Sahibin paneli (5 test)

`ip_hash_is_never_exposed` üç ayrı kontrol yapıyor: hash'in kendisi, `ipHash` ve
`ip_hash` anahtarları. Neden üçü?

- Hash'in kendisi → değer sızmasın.
- `ipHash` → biri Resource'a camelCase alan eklerse.
- `ip_hash` → biri `$this->resource->toArray()` gibi bir kestirme yazarsa.

`an_unchanged_rsvp_list_returns_304` **K46'nın karşılığını** doğruluyor: Faz
4'te ETag'i ayrı bir middleware yapmıştık, gerekçesi "Faz 5'in polling ucu aynı
katmanı yeniden kullanacak" idi. Bu test o sözün tutulduğunu kanıtlıyor.

> **T13** burada zorunlu: aynı test metodunda iki kimlikli istek var. Arada
> `forgetAuthState()` çağrılmazsa `RequestGuard` ilk kullanıcıyı önbellekte
> tutar ve ikinci istek token'a **hiç bakmaz**.

### Silme (3 test)

`another_user_cannot_delete_an_rsvp` T14'ün en net örneği:

```php
->assertNotFound();
$this->assertDatabaseHas('rsvps', ['id' => $rsvp->id]);
```

`404` dönmesi **silinmediğini kanıtlamaz**: policy silmeyi engellemeyip sonra
404 döndürseydi de yanıt aynı olurdu. İkinci satır gerçek kanıttır.

---

## 3. 🔴 Mutasyon tablosu

Kural 14: *"bu korumayı silsem hangi test kırılır?"* Kırılan yoksa test değil
**süs** yazmışsındır.

| # | Bozulan kod | Kırılması gereken test |
|---|---|---|
| 1 | `SubmitRsvpAction`'daki `if ($honeypotTripped)` bloğu silinir | `honeypot_submission_is_not_persisted` |
| 2 | `silentlyDiscard()` içine `$rsvp->save()` eklenir | aynı test |
| 3 | `silentlyDiscard()`'tan `created_at` ataması silinir | `honeypot_response_has_the_same_shape_as_a_real_one` |
| 4 | `if (! $invitation->show_rsvp)` silinir | `rsvp_is_rejected_when_the_module_is_closed` |
| 5 | `ResolvePublicInvitationAction` yerine `Invitation::findOrFail()` | `rsvp_to_an_unpublished_invitation_is_rejected` |
| 6 | `lessThan(now()->startOfDay())` → `isPast()` | `rsvp_is_accepted_on_the_deadline_day` |
| 7 | Son tarih kontrolü tamamen silinir | `rsvp_is_rejected_after_the_deadline` |
| 8 | `sum('guest_count')` → `count()` | `quota_counts_guests_not_rows` |
| 9 | `quotaConsumingValues()` → `values()` | `declined_rsvps_do_not_consume_quota` |
| 10 | `RsvpStatus::Pending`'in `consumesQuota()` değeri `false` yapılır | `pending_rsvps_consume_quota` |
| 11 | `RsvpQuotaExceededException::errorParams()` `['limit' => 5]` döndürür | `quota_rejection_does_not_leak_counters` |
| 12 | `'status' => ['in:...']` → `Rule::enum(...)` | `status_must_be_a_known_value` |
| 13 | `RsvpResource`'a `'ipHash' => $this->ip_hash` eklenir | `ip_hash_is_never_exposed` |
| 14 | `RsvpController::index`'te `$invitation->rsvps()` → `Rsvp::query()` | `another_user_cannot_list_rsvps` |
| 15 | `RsvpPolicy::delete()` `return true` yapılır | `another_user_cannot_delete_an_rsvp` |
| 16 | Rotadan `throttle:rsvp` kaldırılır | `rsvp_submissions_are_rate_limited` |
| 17 | Rotadan `whereUlid('invitation')` kaldırılır | `a_malformed_invitation_id_never_reaches_the_database` |
| 18 | `ApiExceptionRenderer`'dan `HasErrorCode` kolu silinir | son tarih ve kota testleri (403 yerine 500) |

🔴 **Bu tabloyu koşturmak bir öneri değil, kabul ölçütüdür.** Faz 4'te üç IDOR
testinin boş yeşil yandığı ancak bir sonraki faz koda dokunduğunda anlaşılmıştı.

---

## 4. Testin ortamıyla ilgili bilmen gerekenler

### Hız sınırı testleri neden birbirini etkilemiyor?

`phpunit.xml` → `CACHE_STORE=array`. Rate limiter sayaçlarını cache'te tutar;
`array` sürücüsü her testte sıfırdan doğar. `file` sürücüsü olsaydı bir testin
doldurduğu kova diğerini `429` yerdirirdi — klasik bir flaky test kaynağı.

Yine de `rsvp_submissions_are_rate_limited` limiti **3'e düşürüyor**:

```php
config(['davetkart.rsvp.rate_limit.per_ip_per_minute' => 3]);
```

Gerçek limit (10) ile test etmek 11 HTTP isteği demek olurdu — yavaş ve
kırılgan. Test **davranışı** doğruluyor, sayıyı değil (**T5**).

### `config()` ile kotayı değiştirmek neden çalışıyor?

`TierRsvpQuotaResolver::limitFor()` config'i **her çağrıda** okuyor. Değeri
kurucuda okusaydı test içinde değiştirmek işe yaramazdı.

> Alternatif ve daha güçlü yol: arayüzü sahte bir uygulamaya bağlamak.
> `RsvpQuotaResolver` bunu mümkün kılıyor (5.6 §5) — Faz 7'de kota kaynağı
> değişince testler bu yola geçebilir.

### `RefreshDatabase` ve `lockForUpdate`

`SubmitRsvpAction` kota kontrolünü `DB::transaction` + `lockForUpdate()` ile
yapıyor. `RefreshDatabase` zaten her testi bir transaction'a sardığı için bu
**iç içe** bir transaction olur (PostgreSQL'de `SAVEPOINT`).

Çalışır, ama şunu bil: **eşzamanlılık testte doğrulanamaz.** Tek bir test
süreci var; iki isteğin yarışını taklit edemeyiz. Bu, Faz 4'ün **T15**
durumunun aynısı — zincirin bir halkası test edilemiyor, o hâlde:

- Kilit **kodda** var ve gerekçesi yazılı,
- Elle doğrulama betiğinde iki paralel istekle deneme adımı var,
- Ve bu boşluk **açıkça** yazıldı (**B6**).

---

## 5. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Honeypot testinde yalnızca `assertCreated()` | Savunma silinse de yeşil kalır (T14) |
| 2 | Kota testinde `guests(1)` kullanmak | `COUNT(*)` mutasyonu hayatta kalır |
| 3 | İki kimlikli istek arasında `forgetAuthState()` unutmak | Test boş yeşil yanar (T13) |
| 4 | Türkçe hata metni doğrulamak | **T5**: backend metin döndürmez, kod döndürür |
| 5 | `assertJsonPath` ile ayırt edilemezlik testi | Yalnızca baktığın yeri kontrol eder (T11) |
| 6 | Gerçek limitle (10) hız sınırı testi | Yavaş ve kırılgan |
| 7 | `assertDatabaseCount` yerine `assertStatus` | En sık yapılan hata; bu fazda üç savunmayı birden kör eder |

---

## 6. Kendin dene

```powershell
php artisan test --filter=RsvpTest
composer check                     # 🔴 SON satıra bak, ilkine değil
```

`composer check` **fail-fast**: `pint --test` kırılırsa PHPStan hiç koşmaz,
PHPStan kırılırsa **testler hiç koşmaz**. "Yeşil gördüm" demek için zincirin
**tamamının** koşmuş olması gerekir — üç fazda üç kez "kapandı" sanılan faz
kapanmamıştı.

---

## 7. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Feature test** | Uçtan uca, gerçek HTTP isteğiyle koşan test |
| **Mutasyon testi** | Kodu bilerek bozup testin kırılmasını bekleme yöntemi |
| **Sızıntı testi** | Bir bilginin yanıta **girmediğini** doğrulayan test |
| **Boş yeşil** | Hiçbir şey doğrulamadığı hâlde geçen test |
| **Flaky test** | Kod değişmeden bazen geçen bazen kalan test |
| **Fail-fast** | İlk hatada zinciri durduran yapılandırma |
| **`SAVEPOINT`** | Transaction içinde iç içe transaction noktası |

---

## 8. Sırada ne var?

**5.14 — PHPStan level 6 → 8** (K22 takvimi) ve faz kapanış dokümanları.

| İlgili | Nerede |
|---|---|
| İş kuralı | [`../../app/Actions/Rsvp/SubmitRsvpAction.md`](../../app/Actions/Rsvp/SubmitRsvpAction.md) |
| Fabrika | [`../../database/factories/RsvpFactory.md`](../../database/factories/RsvpFactory.md) |
| Kardeş test | [`PublicInvitationTest.md`](PublicInvitationTest.md) |
