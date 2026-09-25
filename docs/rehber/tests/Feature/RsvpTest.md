# `tests/Feature/RsvpTest.php`

> **Kod dosyası:** `tests/Feature/RsvpTest.php`
> **Faz:** 5 — RSVP/LCV dilimi (5.13) · **Yeniden yazım:** Test denetimi, dosya 1/14 (24 Eylül 2026)
> **46 test metodu · 86 vaka** (7'si veri sağlayıcılı) · **86 yeşil · 0 kırmızı**
> ✅ **7 kırmızı vaka 25 Eylül 2026'da kodla yeşile döndü** (§4): her biri bir
> **KOD HATASI**'nı gösteriyordu. Test gevşetilmedi — düzeltilen kod oldu.
> **Kardeş dosyalar:** [`MediaTest.md`](MediaTest.md) (LCV'ye medya iliştirme) ·
> [`PaywallTest.md`](PaywallTest.md) (plan → kota bağlantısı)

---

## 0. Bu sürüm neden yazıldı?

Eski sürüm 29 testti ve **hepsi yeşildi**. Denetimde aynı 34 mutant iki sürüme
de uygulandı (§3). Sonuç:

| | Eski `RsvpTest` | Yeni `RsvpTest` |
|---|---|---|
| Hayatta kalan mutant | **18 / 34** | **0 / 34** |
| Satırı birden çok sütunla doğrulayan test | 1 (ad + kişi sayısı) | 9 |
| Ödenmiş siparişle kurulan davetiye | **0** | hepsi |
| Saat dilimi sınırında test | 0 | 9 vaka |

Hayatta kalanların en tehlikeli sekizi — **eski test paketi bunların hiçbirinde
kırılmıyordu**:

| Mutant | Üretimde ne olurdu |
|---|---|
| Herkes `attending` kaydedilir | "Katılamıyorum" diyen misafir katılıyor görünür, **kotayı da yer** |
| `message` / `menuPreference` yazılmaz | Misafirin çifte yazdığı mesaj **sessizce kaybolur** |
| Ham IP saklanır | **KVKK ihlali** — hiçbir test fark etmez |
| Liste `Rsvp::query()` ile çekilir | Sahip, **başka çiftlerin misafirlerini** görür |
| Silme, davetiyenin tüm yanıtlarını siler | Bir spam silinir, **tüm LCV listesi** gider |
| Kota `>` yerine `>=` | 100 kişilik planda **100. misafir** kapıda kalır |
| Davetiye başına saatlik kova kaldırılır | Botnet tek davetiyeyi çöpe boğar |
| Honeypot davetiyeyi çözdükten sonra | Bot her istekte sorgu açtırır; taslakta 404 alıp **tuzağı öğrenir** |

### Kök neden: tek bir gövde

Eski dosyadaki **her** gönderim aynıydı:

```php
['guestName' => 'Can Dogan', 'guestCount' => 2, 'status' => 'attending']
```

Tek bir gövde, tek bir davranış yolunu dener. `status` her zaman `attending`
olunca "her şeyi `attending` yaz" mutantı **ayırt edilemez**; `message` hiç
gönderilmeyince "mesajı yazma" mutantı **görünmez**. Test ne kadar T14'e uysa da
(veritabanına bakıyordu) **baktığı sütunlar** mutantın dokunduğu sütunlar değildi.

> **Ders:** T14 "veritabanına bak" der; **hangi satıra ve hangi sütuna** baktığını
> söylemez. Bir etki testi, yalnızca test verisinin **çeşitlendiği** eksende
> kanıt üretir.

### Eski mutasyon tablosu yanlıştı

Eski kılavuzun §3'ündeki T16 tablosu iki satırda **olmayan bir öldürmeyi**
vaat ediyordu:

| Satır | Tablonun iddiası | Gerçek |
|---|---|---|
| 3 — `silentlyDiscard()`'tan `created_at` silinir | `..._same_shape_...` kırılır | **Kırılmıyordu:** test yalnızca **anahtarları** karşılaştırıyordu; `createdAt: null` da bir anahtardır |
| 14 — `$invitation->rsvps()` → `Rsvp::query()` | `another_user_cannot_list_rsvps` kırılır | **Kırılmıyordu:** o test Gate'ten 404 alır; sorgu kapsamı hiç sınanmıyordu. Sahip testinde tek davetiye vardı, sızacak başka satır yoktu |

Yani tablo **elle yazılmış bir iddiaydı, koşturulmuş bir ölçüm değil**. Faz 4'ün
34. dersinin (üç IDOR testi Policy'den değil eşleşmeyen rotadan 404 alıyordu)
birebir tekrarı. Bu yüzden §3'teki tablo **koşturuldu** ve her satırın yanında
onu öldüren testin adı yazıyor.

---

## 1. Fikstür tasarımı: neden "gerçek bir düğün"?

### 1.1 Ödenmiş sipariş — en önemli değişiklik

```php
private function dugunDavetiyesi(array $overrides = [], SubscriptionTier $plan = SubscriptionTier::Standart, ?User $sahip = null): Invitation
{
    $davetiye = $this->yayindakiDavetiye($overrides, $sahip);
    $this->planSat($davetiye, $plan);          // 🔴 Standart 249 ₺, ÖDENDİ

    return $davetiye;
}
```

Eski fikstür siparişsiz yayındaki davetiye kuruyordu. Oysa **gerçekte ödemesiz
davetiye yayınlanamaz** (7.12). `SubscriptionRsvpQuotaResolver` siparişi
bulamayınca "en dar plan" koluna düşer — koddaki yorumun deyişiyle bu kol
*"pratikte ulaşılmaz"*. Eski kota testlerinin **tamamı** bu ulaşılmaz kolu
sınıyordu ve `config(['davetkart.tiers.standart.rsvp_limit' => 5])` ile sayıyı
yapay olarak küçültüyordu.

Yeni testler gerçek 100 kişilik sınırla çalışır ve bu sayıyı ayrıca sabitler:

```php
$this->assertSame(100, SubscriptionTier::Standart->rsvpLimit());   // fiyat sayfasındaki söz
```

Config'te biri `100`'ü `150` yaparsa (M34) yedi test kırılır. Bu bir **ticari
sözdür**: kullanıcı 249 ₺ öderken "100 kişi" okudu.

Siparişsiz davetiye yalnızca bir senaryoda kullanılır: **iade** (`planSat(...,
iadeEdildi: true)`). Hak düşer, kota en dar plana iner — ve bu kolun gerçek
olduğu tek durum budur.

### 1.2 Donmuş zaman

```php
protected function setUp(): void
{
    parent::setUp();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'));
}
```

Fikstürdeki son tarih 10 Ekim 2026. Zaman dondurulmasaydı, bu dosyayı **11 Ekim'de**
çalıştıran herkes 40 kırmızı test görürdü — kod hiç değişmeden. **T12**: sonucu
koştuğu güne bağlı olan test, test değildir.

Bir yan kazanç: `createdAt` artık **tam değeriyle** doğrulanabiliyor
(`'2026-09-20T12:00:00+00:00'`). Honeypot testi de bu sayede bot yanıtını gerçek
yanıtla **`id` dışında birebir** karşılaştırabiliyor (§2.4).

### 1.3 Türkçe veri

| Veri | Neden |
|---|---|
| `Şeyma Şen`, `Oğuz Ertürk`, `İsmail Çankaya`, `Zeynep Kılıç` | `ş ğ ı İ ç ö ü` — UTF-8'in çok baytlı yolu |
| Çok satırlı mesaj + `💍👰🤵` + `"Evet"` + `Gülşah'ım` | Satır sonu, 4 baytlık emoji, çift tırnak, kesme işareti **tek alanda** |
| `str_repeat('Ğ', 120)` | 120 **karakter** = 240 **bayt**. Bayt sayan bir sınır burada patlar |
| `Europe/Berlin` düğünü | Gurbetçi düğünü — yaz saati sınırında son tarih |
| `asdasd qweqwe` | Gerçek hayattaki spam böyle görünür |

### 1.4 Gerçekçi IP'ler

```php
$this->withServerVariables(['REMOTE_ADDR' => '78.180.45.12'])->postJson(...);
```

Laravel'in test istekleri varsayılan olarak `127.0.0.1`'den gelir. Tek bir IP'yle
**davetiye başına** kovayı (botnet senaryosu) test etmek imkânsızdır: IP kovası
önce dolar. Farklı IP'ler (IPv4 + IPv6) KVKK testini de gerçekçi kılar.

### 1.5 Aynı çiftin iki davetiyesi

```php
private ?User $gulsah = null;

private function gulsah(): User
{
    return $this->gulsah ??= $this->kullanici('Gülşah', 'Yağız-Öztürk', '...');
}
```

`??=` "soldaki `null` ise sağı ata ve döndür" demektir. Aynı testte `gulsah()` iki
kez çağrılırsa **aynı kullanıcı** döner — düğün ve kına gecesi aynı çiftin olur.
Bu, "sahip yalnızca **bu** davetiyenin yanıtlarını görür" testinin (M4) ön
koşuludur: sızıntı, **aynı sahibin** iki davetiyesi arasında en zor fark edilir.

PHPUnit her test metodu için sınıftan **yeni bir nesne** üretir; `$gulsah`
özelliği testler arasında taşınmaz.

---

## 2. Bölüm bölüm

### 2.1 Mutlu yol (7 test)

| Test | Soru |
|---|---|
| `a_guest_reply_is_stored_and_echoed_exactly_as_submitted` | Gönderilen her alan **hem yanıtta hem satırda** birebir mi? |
| `every_answer_is_recorded_as_the_guest_gave_it` (×3) | `attending` / `pending` / `declined` ayrı ayrı yazılıyor mu? |
| `optional_fields_may_be_omitted_and_the_message_key_disappears` | Boş mesaj `null` olur ve **anahtar** yanıttan düşer mi (C7)? |
| `server_owned_fields_in_the_body_are_ignored` | Gövdeye yazılan `invitation_id`, `ip_hash`, `id` yok sayılıyor mu (N1)? |
| `the_guest_ip_is_stored_only_as_a_keyed_hmac` | Ham IP satırın **hiçbir** kolonunda yok mu? |
| `text_limits_count_characters_not_bytes` | 120 'Ğ' kabul mü? |
| `markup_and_sql_fragments_are_stored_as_inert_text` | `<script>` ve `'; DELETE ...` **bozulmadan** saklanıp tablo sağlam mı? |

`every_answer_...` testinin veri sağlayıcısı enum'u **kendisi** dolaşır:

```php
foreach (RsvpStatus::cases() as $durum) {
    yield $durum->value => [$durum, match ($durum) {
        RsvpStatus::Attending => 'Kına gecesine de geliyoruz, hazır olun! 💃',
        RsvpStatus::Pending   => 'İzin çıkarsa geleceğiz; ...',
        RsvpStatus::Declined  => "Maalesef o hafta Almanya'dayız, ...",
    }];
}
```

Bir gün `RsvpStatus`'a dördüncü bir durum eklenirse `match` **kolu eksik** olduğu
için `UnhandledMatchError` fırlar ve test paketi gürültüyle durur. Yeni durum
sessizce test dışı kalamaz.

IP testi hash'i `IpHasher::hash()`'i **çağırmadan** hesaplar:

```php
private function beklenenIpHash(string $ip): string
{
    return hash_hmac('sha256', $ip, Config::string('app.key'));
}
```

Beklenen değeri üretim koduyla hesaplasaydık, üretim kodu bozulduğunda beklenen
değer de onunla birlikte bozulurdu — iki taraf da yanlış, test yeşil
(**totoloji**). Formül testte ayrıca yazılıdır; M15 (anahtarsız `hash()`) bu
yüzden ölür.

`markup_...` testi bir şeyi **yapmadığını** da doğrular: backend metni temizlemez.
`<script>` olduğu gibi saklanır ve JSON olarak döner; tarayıcıda kaçırma (escape)
React'in işidir. Backend'in "temizlemesi" misafirin gerçek mesajını bozardı
("Ayşe'nin" → "Ayşe&#039;nin").

### 2.2 Görünürlük (2 test, 9 vaka)

`hidden_invitations_are_indistinguishable_from_missing_ones` dört durumu **ham
gövdeyle** karşılaştırır (**T11**): hiç var olmayan ULID, taslak, LCV modülü
kapalı, silinmiş. Dördü de tam olarak şu gövdeyi almalı:

```json
{"error":{"code":"RESOURCE_NOT_FOUND"}}
```

Beklenen gövde bir **sabit** olarak yazılıdır — yalnızca "ikisi aynı mı" diye
değil, "ikisi **bu** mu" diye sorulur. İkisi de `debug` bloğu taşısa, "aynı"
olurlardı ama sözleşmeyi (T4) ihlal ederlerdi.

`a_malformed_invitation_id_is_rejected_before_any_query` sekiz bozuk kimlikle
koşar ve her birinde **sorgu günlüğünün boş** olduğunu doğrular (O6). Listedeki
`81jbz...` ilk karakteri 7'den büyük bir ULID'dir: 128 biti taşar, ULID değildir.

> ⚠️ Bilinçli olarak listede **olmayan**: büyük harfli ULID. `whereUlid` onu
> geçerli sayar (ULID spesifikasyonu harf duyarsızdır) ama `HasUlids` küçük harf
> ürettiği için veritabanında eşleşmez: 404 döner ama **bir sorgu açılır**. Bu bir
> hata değil, bir gözlem — denetim raporunda not edildi.

### 2.3 Doğrulama ve tip bozulması (6 test, 31 vaka)

`an_invalid_value_is_rejected_by_its_rule_and_nothing_is_written` 21 bozuk değeri
dener. Her birinde **dört** şey doğrulanır:

```php
$this->assertSame(['code', 'fields'], array_keys((array) $yanit->json('error')));  // K20: metin yok
$this->assertSame([$alan], array_keys($this->hataAlanlari($yanit)));             // T6: yalnız bozuk alan
$this->assertSame($kural, $yanit->json("error.fields.{$alan}.0.rule"));          // D6: kural ADI
$this->assertDatabaseCount('rsvps', 0);                                          // T14
```

İkinci satır önemli: bozuk olmayan alanlar için hata **üretilmemeli**. Sadece
"422 geldi" demek, formun geçerli alanlarını da reddeden bir mutantı yakalamaz.

Sağlayıcıdaki bazı satırların neden orada olduğu:

| Satır | Gerçek hayattaki karşılığı |
|---|---|
| `isim yerine telefon (sayi)` → `5_329_998_877` | Misafir ad kutusuna telefonunu yazdı; JS bunu sayıya çevirdi |
| `isim gorunmez karakter` → `\u{200B}\u{200B}\u{FEFF}` | Kopyala-yapıştırla gelen görünmez karakterler; Laravel'in `TrimStrings`'i bunları siler → `required` |
| `durum Turkce etiket (K21)` → `'Katılıyor'` | Frontend çeviri etiketini yanlışlıkla değer olarak gönderdi |
| `kisi yaziyla` → `'üç'` | "Kaç kişi?" kutusuna yazıyla cevap |

`5_329_998_877` yazımı PHP 7.4'ten beri geçerli bir **sayı ayıracıdır**: `_`
yalnızca okunurluk içindir, değer `5329998877`'dir.

### 2.4 Honeypot (4 test)

```php
$this->assertSame(
    array_diff_key($gercek, ['id' => true]),
    array_diff_key($bot, ['id' => true]),
);
```

`array_diff_key` birinci diziden, ikincide **anahtarı** bulunan elemanları atar.
Yani "`id` dışındaki her şey, **değerleriyle** aynı mı?" sorusu sorulur. Eski test
yalnızca anahtar listelerini karşılaştırıyordu; bu yüzden bot yanıtında
`createdAt: null` olmasına (M12) izin veriyordu — bir bot iki gönderimle bu farkı
görüp tuzağı öğrenebilirdi (L2).

`the_honeypot_is_the_first_layer_and_costs_no_query` **L1**'in (ucuzdan pahalıya)
kanıtıdır: bot, bir **taslak** davetiyeye gönderse bile `201` alır ve **tek bir
sorgu** açılmaz. İnsan aynı taslağa `404` alır. Neden bu doğru? Bot davetiyeye hiç
ulaşmamalı — ulaşırsa (M13) hem veritabanına yük bindirir hem de taslak/yayında
farkını öğrenir.

`the_honeypot_field_name_is_part_of_the_frontend_contract` tek satırlık bir
sözleşme testidir: frontend'in görünmez input'u `name="website"` ile çizilir.
Sabit yeniden adlandırılırsa (M33) backend yeni adı bekler, frontend eski adı
gönderir ve **tuzak sessizce ölür** — hiçbir istek hata vermez.

### 2.5 Son tarih ve saat dilimi (4 test, 10 vaka)

İki veri sağlayıcı, **aynı sınırın iki yüzü**:

| Vaka | Son tarih | UTC an | Yerel an | Sonuç |
|---|---|---|---|---|
| İstanbul son gün | 10 Eki | 20:59:59 | **23:59:59** | 201 |
| İstanbul gece yarısı | 10 Eki | 21:00:00 | **00:00 (11 Eki)** | 403 |
| Artık yıl | 29 Şub 2028 | 20:59 / 21:00 | 23:59 / 00:00 | 201 / 403 |
| Yılbaşı | 31 Ara | 20:59:59 / 21:00 | 23:59:59 / 00:00 | 201 / 403 |
| Berlin (yaz saati) | 24 Eki | 21:59:59 / 22:00 | 23:59:59 / 00:00 CEST | 201 / 403 |

"İstanbul gece yarısı" satırı **bu bölümün imza vakasıdır**: UTC'de hâlâ 10
Ekim'dir, İstanbul'da 11 Ekim olmuştur. Sunucunun saatine (UTC) bakan bir kod
(M3) bu misafiri **kabul eder** — üç saat boyunca süresi dolmuş davetiyeye LCV
yazılır. Eski dosyada bu sınır hiç sınanmıyordu; `rsvp_is_accepted_on_the_deadline_day`
UTC 09:00'da koşuyordu, iki takvimin **aynı** güne düştüğü saatte.

Berlin satırı Türkiye'nin sabit UTC+3'ünün (2016'dan beri yaz saati yok) testi
gizlemediğini kanıtlar: Berlin 25 Ekim 2026'da yaz saatinden çıkar, 24 Ekim'de
hâlâ UTC+2'dir.

### 2.6 Kota (9 test)

| Test | Kurgu | Öldürdüğü |
|---|---|---|
| `the_plans_promise_the_published_rsvp_limits` | Standart = 100, Gold/Elit = `null` | M34 |
| `..._up_to_the_exact_limit_and_not_one_more` | 97 + 3 = **100 kabul**, 100 + 1 red | M2 (`>=`) |
| `the_quota_counts_guests_not_rows` | 25 aile × 4 kişi | M19 (`COUNT`) |
| `declined_guests_do_not_take_seats` | 100 "katılamıyoruz" + 10 | M20 |
| `undecided_guests_take_seats` | 100 "kararsız" + 1 | K50 |
| `a_gold_plan_has_no_rsvp_quota` | 250 + 10 | M6 |
| `a_refunded_plan_falls_back_to_the_narrowest_quota` | İade edilmiş Gold, 100 + 1 | M32 |
| `a_quota_rejection_reveals_no_counters` | Gövde **tam olarak** `{"error":{"code":"RSVP_QUOTA_EXCEEDED"}}` | M26 |
| `removing_a_spam_reply_frees_its_seats` | 96 + 4 spam → sil → 4 yeni kabul | M2, M14, M19 |

"Tam sınır" testi neden 97 + 3? Çünkü **eşitlik** sınırın tanımıdır. 98 + 1 de
olurdu; önemli olan toplamın **tam 100** olması. 99'da kalsaydık `>=` mutantı
hayatta kalırdı.

`mevcutMisafirler()` kalabalığı `fake('tr_TR')->name()` ile üretir: Faker'ın Türkçe
sağlayıcısı. **Ama** bu isimlere hiçbir testte güvenilmez — Faker rastgele
"Zeynep Kılıç" da üretebilir ve isim üzerinden yapılan bir `assertDatabaseMissing`
yılda bir kez kırmızı yanardı (**T12**). Bu testler sayıya ve toplama bakar.

### 2.7 Hız sınırı (3 test)

`one_invitation_is_protected_against_many_ips_without_affecting_others` Faz 5'in
**ikinci kovasını** ilk kez sınar. Dört istek, dört **farklı** IP: dördüncüsü
yine de `429` alır, çünkü kova davetiyenindir. Aynı IP'den çiftin **kına gecesine**
gönderilen istek geçer (**T7**: kararın sınırı).

`travel(61)->seconds()` kovanın **kalıcı bir yasak olmadığını** kanıtlar. Zaman
donmuş olduğu için bu satır olmadan kova hiç boşalmazdı.

### 2.8 Sahibin listesi (5 test)

`the_owner_sees_only_this_invitations_replies_newest_first` **P3'ün ikinci
katmanının** (sorgu kapsamı) tek kanıtıdır. Kurgu:

- Gülşah'ın düğünü → 3 yanıt (tarihleri **karışık** sırayla yaratılır)
- Gülşah'ın kına gecesi → 1 yanıt
- Mehmet Ali'nin düğünü → 1 yanıt

Beklenen: **yalnızca** düğünün 3 yanıtı, **en yeni üstte**. `created_at` bilerek
karışık sırayla verilir: yaratılma sırasıyla aynı olsaydı "sıralama yok" mutantı
(M5) veritabanının doğal sırasıyla yeşil kalabilirdi.

`polling_gets_304_until_a_new_reply_arrives` eski testin **yokluk yarısını** ekler
(**T6**): 304'ün bir gün **bitmesi** gerekir. Yeni bir LCV geldikten sonra eski
ETag artık `200` + güncel listeyi getirmeli. Yalnızca "304 geliyor" testi, ETag'i
sabit bir değere çeviren bir mutantla (liste sonsuza dek "değişmedi" der) yeşil
kalırdı.

### 2.9 Silme (6 test)

`the_owner_deletes_exactly_one_reply`: bir spam + üç gerçek yanıt. Spam silinir,
**üçü de yerinde** kalmalı. Eski test tek yanıtlı bir davetiyede "sayı 0 oldu"
diye bakıyordu — "tümünü sil" mutantı (M14) aynı sonucu verirdi.

`a_reply_of_a_deleted_invitation_answers_404_not_500` Faz 6'da kalite kapısının
bulduğu gerçek hatanın **regresyon testidir**: davetiye soft-delete edilince
`$rsvp->invitation` `null` döner; `RsvpPolicy` kontrol etmeseydi `TypeError` → 500.

---

## 3. 🔴 Mutasyon tablosu (T16) — koşturuldu

Her satır: üretim kodunda **tek bir değişiklik**, ardından yalnızca
`php artisan test --filter='Tests\\Feature\\RsvpTest'`. "Öldü" = en az bir test
**yeni** kırmızıya döndü (bilerek kırmızı olan 7 vaka hariç tutularak).

| # | Mutant | Eski | Yeni | Öldüren test (örnek) |
|---|---|---|---|---|
| M1 | Ham IP saklanır | 🟢 yaşadı | ☠️ | `the_guest_ip_is_stored_only_as_a_keyed_hmac` |
| M2 | Kota `>` → `>=` | 🟢 | ☠️ | `..._up_to_the_exact_limit_and_not_one_more` |
| M3 | Son tarih UTC'de hesaplanır | 🟢 | ☠️ | `a_reply_after_local_midnight_...` (4 vaka) |
| M4 | Liste `Rsvp::query()` | 🟢 | ☠️ | `the_owner_sees_only_this_invitations_replies_newest_first` |
| M5 | `latest()` silinir | 🟢 | ☠️ | aynı test |
| M6 | Kota planı yok sayar | 🟢 | ☠️ | `a_gold_plan_has_no_rsvp_quota` |
| M7 | Davetiye kovası silinir | 🟢 | ☠️ | `one_invitation_is_protected_against_many_ips_...` |
| M8 | Herkes `attending` | 🟢 | ☠️ | `every_answer_is_recorded_...` (pending, declined) |
| M9 | `message`/`menuPreference` eşlenmez | 🟢 | ☠️ | 6 vaka |
| M10 | Resource `status` sabit | 🟢 | ☠️ | `every_answer_is_recorded_...` |
| M11 | Resource `message` düşer | 🟢 | ☠️ | 6 vaka |
| M12 | Bot yanıtında `createdAt` yok | 🟢 | ☠️ | `a_honeypot_hit_is_answered_like_a_real_reply_...` |
| M13 | Honeypot davetiyeden **sonra** | 🟢 | ☠️ | `the_honeypot_is_the_first_layer_and_costs_no_query` |
| M14 | Silme kardeşleri de siler | 🟢 | ☠️ | `the_owner_deletes_exactly_one_reply` |
| M15 | IP hash anahtarsız `hash()` | 🟢 | ☠️ | `the_guest_ip_is_stored_only_as_a_keyed_hmac` |
| M16 | Honeypot bloğu silinir | ☠️ | ☠️ | honeypot testleri |
| M17 | `show_rsvp` kontrolü silinir | ☠️ | ☠️ | `hidden_invitations_are_indistinguishable_...` |
| M18 | Yayın filtresi silinir | ☠️ | ☠️ | aynı test |
| M19 | `SUM` → `COUNT` | ☠️ | ☠️ | 6 vaka |
| M20 | Reddedenler de sayılır | ☠️ | ☠️ | `declined_guests_do_not_take_seats` |
| M21 | `throttle:rsvp` kaldırılır | ☠️ | ☠️ | iki hız testi |
| M22 | `whereUlid` kaldırılır | ☠️ | ☠️ | 7 bozuk kimlik vakası |
| M23 | `RsvpPolicy::delete()` → `true` | ☠️ | ☠️ | `another_account_cannot_delete_a_reply` |
| M24 | Son tarih kontrolü silinir | ☠️ | ☠️ | 5 vaka |
| M25 | Son gün hariç (`<=`) | ☠️ | ☠️ | `a_reply_on_the_last_local_day_is_accepted` (4 vaka) |
| M26 | Kota reddi sayaç sızdırır | ☠️ | ☠️ | `a_quota_rejection_reveals_no_counters` |
| M27 | Silmede Gate yok | ☠️ | ☠️ | `another_account_cannot_delete_a_reply` |
| M28 | Listede Gate yok | ☠️ | ☠️ | `another_account_gets_the_same_404_...` |
| M29 | Listeden `SetEtag` kalkar | ☠️ | ☠️ | `polling_gets_304_until_a_new_reply_arrives` |
| M30 | Resource `ip_hash` sızdırır | ☠️ | ☠️ | 4 vaka |
| M31 | İsim `min:2` → `min:1` | 🟢 | ☠️ | `an_invalid_value_...` "isim tek harf" |
| M32 | İade sonrası sınırsız | ☠️ | ☠️ | `a_refunded_plan_falls_back_to_the_narrowest_quota` |
| M33 | Honeypot alan adı değişir | 🟢 | ☠️ | `the_honeypot_field_name_is_part_of_the_frontend_contract` |
| M34 | Standart limiti 100 → 150 | 🟢 | ☠️ | 7 vaka |

**Eski: 18 / 34 hayatta · Yeni: 0 / 34 hayatta.**

> **Eşdeğer mutant notu:** `COLUMN_MAP`'e `'invitationId' => 'invitation_id'`
> eklemek **hiçbir testi kırmaz** — ve kırmamalı. O alanın doğrulama kuralı yok,
> dolayısıyla `validated()` onu zaten eler; harita satırı hiç çalışmaz. Davranışı
> değiştirmeyen bir mutant **eşdeğerdir** ve hayatta kalması testin eksikliği
> değildir. Kanıt, `server_owned_fields_in_the_body_are_ignored`'ın uçtan uca
> yeşil kalmasıdır.

> ⚠️ **Ortam uyarısı (B7):** tablo denetimin yalıtılmış kum havuzunda koştu —
> **PHP 8.4.21 + PostgreSQL 16.13**. Proje PHP 8.5 + PostgreSQL 18 hedefliyor.
> Kodda 8.5'e özgü sözdizimi yok ve davranışlar sürüm bağımsız, ama "doğrulandı"
> demek için §7'deki komutların **senin makinende** koşması gerekir.

---

## 4. 🔴 KIRMIZI testler = KOD HATALARI (✅ 25 Eylül 2026'da düzeltildi)

Dört test metodu (7 vaka) bilerek kırmızı bırakılmıştı. **Hiçbiri testin hatası
değildi.** Her biri için üretimde ne olduğunu, kanıtı ve düzeltme seçeneklerini
aşağıda bulacaksın; her alt başlığın sonundaki **✅ Uygulanan** satırı hangi
seçeneğin, hangi dosyada uygulandığını söyler. Testlerin tek bir satırı
değişmedi.

### 4.1 NUL baytı: yanıt ile satır farklı, `min:2` atlatılıyor (4 vaka)

**Kanıt** (kum havuzunda gerçek istek):

```text
POST guestName = "Ali\u0000Veli"
→ 201  {"data":{"guestName":"Ali\u0000Veli", ...}}
→ satır: guest_name = "Ali"

POST guestName = "Z\u0000ZZZZZ"          (6 karakter, min:2 geçer)
→ 201
→ satır: guest_name = "Z"                (1 karakter!)
```

**Neden?** PostgreSQL'in istemci kütüphanesi (libpq) metin parametrelerini C
dizesi olarak gönderir ve C dizesi **ilk `\0`'da biter**. PHP'nin dizesi
uzunluğunu ayrıca bildiği için `\0` taşıyabilir; PostgreSQL'in `text` tipi
taşıyamaz. Doğrulama PHP'de, yazma PostgreSQL'de — ikisi **farklı** dizeyi görür.

**Etki:** auth'suz uçta 201 yanıtı **yalan söyler** (T14'ün üretimdeki hâli);
`min:2` gibi kurallar atlatılır. Aynı sorun **her** metin alanında var —
kum havuzunda doğrulandı: davetiye başlığı (`"Düğün\0ümüz"` → `"Düğün"`),
kayıt formundaki ad, hatta e-posta (`"zeynep\0@gmail.com"` → `"zeynep"`,
`@` işareti olmayan bir e-posta).

**Düzeltme seçenekleri** — karar senin (**D-1**):

| | A — Global middleware (öneri) | B — Alan başına kural |
|---|---|---|
| Nerede | `api` grubuna tek bir `RejectMalformedInput` | Her FormRequest'in her metin alanı |
| Yanıt | `400 MALFORMED_REQUEST` | `422 VALIDATION_FAILED` + `fields` |
| Yeni uç eklenince | **Otomatik korunur** (fail-safe, K12'nin ruhu) | Biri kuralı **hatırlamalı** |
| 4.2 ile birlikte | Aynı middleware yarım JSON'u da çözer | Çözmez |

`\0`'ı bir tarayıcı formuna **yazamazsın** — gönderen ya bir bot ya bozuk bir
istemcidir. Alan bazında kullanıcı dostu hata mesajına ihtiyaç yok. Bu yüzden
öneri A. B'yi seçersen bu testteki iki satır `assertStatus(422)` +
`ValidationFailed` olur; testin geri kalanı değişmez.

**✅ Uygulanan: A.** `app/Http/Middleware/RejectMalformedInput.php`, `api`
grubunda, öncelik listesinde `SubstituteBindings`'in önünde (throttle'lar önce,
rota model bağlama sonra — bozuk istek veritabanına sorgu açtırmaz). `api`
grubunda durduğu için yukarıda sayılan davetiye başlığı, kayıt adı ve e-posta
uçları da aynı kapıdan geçer. Ayrıntı:
[`RejectMalformedInput.md`](../../app/Http/Middleware/RejectMalformedInput.md).

### 4.2 Yarım kalmış JSON "geçersiz" değil "bozuk"tur (1 vaka)

**Kanıt:** kapanış parantezi eksik bir gövde bugün `422` + üç alanda `required`
alıyor. İstemciye "adı göndermedin" deniyor — oysa gönderdi; gövde **yolda
kesildi** (mobil bağlantı koptu, bir proxy gövdeyi kırptı).

**Sözleşme ne diyor?** `docs/08` §4 (durum kodu tablosu): *"**400** — İstek
biçimsel olarak bozuk — `MALFORMED_REQUEST`"*. `ErrorCode::MalformedRequest` katalogda duruyor ama LCV
ucunda hiçbir yol onu üretmiyor. Laravel geçersiz JSON'u sessizce **boş gövde**
sayar; kararı bizim vermemiz gerekir. 4.1'in A seçeneği bunu da kapsar.

**✅ Uygulanan:** 4.1 ile aynı middleware. `Content-Type` JSON ise ve gövde
**boş değilse** `json_validate()` ile sınanır; gövdesiz bir `DELETE` bozuk
sayılmaz.

### 4.3 `true` bir kişi sayısı değildir (1 vaka)

**Kanıt:** `guestCount: true` → `201`, satırda `guest_count = 1`.

**Neden?** Laravel'in `integer` kuralı `filter_var($deger, FILTER_VALIDATE_INT)`
kullanır; PHP `true`'yu önce `"1"`e çevirir. (`false` ise `""` olur ve reddedilir —
tutarsız bir asimetri.)

**Etki:** bugün zararsız (1 kişi), ama **tip sözleşmesi** delik: aynı kusur
davetiyenin `giftOptions` dizisinde `[true, 500]`'ü kabul edip frontend'e
**`[true, 500]` olarak geri döndürüyor** (kum havuzunda doğrulandı) — `types.ts`
`number[]` bekliyor.

**Düzeltme (D-2):** `'integer'` → `'integer:strict'` (projedeki Laravel 13'te
var — `vendor/.../ValidatesAttributes.php::validateInteger()`; yalnızca gerçek
PHP `int`'i kabul eder). Kural **adı** `integer` kalır, D6 bozulmaz. Tek
yan etki: `"3"` gibi **metin** sayılar da reddedilir. Frontend JSON ile sayı
gönderdiği için sorun değil — ama karar senin.

**✅ Uygulanan:** `StoreRsvpRequest`'te `guestCount` → `integer:strict`.
`ApiExceptionRenderer::RULE_PARAM_NAMES`'e `'integer' => []` eklendi: yoksa
`strict` kelimesi hataya `params.values: ["strict"]` olarak sızardı. Yanıt
eskisiyle birebir aynı: `{"rule": "integer"}`.
⚠️ **Açık kalan:** `giftOptions` dizisindeki `[true, 500]` bu düzeltmenin
kapsamında **değil** — `InvitationRequest`'teki kural hâlâ düz `integer`.

### 4.4 `Retry-After` başlığı vaat ediliyor, gönderilmiyor (1 vaka)

**Kanıt:** 429 yanıtında gövdede `params.retryAfter: 60` var, **başlık yok**.

**Sözleşme ne diyor?** `docs/08` §4.1: *"`Retry-After` başlığı 429 ve 503 ile
birlikte gönderilir (RFC 9110 §10.2.3)."* **B4**: dokümanda verilen söz, kodda
karşılığı yoksa yalandır.

**Neden?** `ApiExceptionRenderer::render()` yeni bir `JsonResponse` üretir ve
`ThrottleRequestsException::getHeaders()`'ı **kopyalamaz**. Değeri gövdeye
koymak için okuyor (`params()` metodu), başlığa hiç yazmıyor. Aynı kusur
`throttle:auth`, `throttle:contact`, `throttle:media`, asistan kotası (429) ve
sağlayıcı hatası (503) yanıtlarında da var.

**Düzeltme (D-3):** renderer'da `HttpExceptionInterface::getHeaders()` yanıta
eklenir; `HasErrorCode` exception'ları için `retryAfter` parametresinden başlık
türetilir. Tek dosya.

**✅ Uygulanan (öneriden bir farkla):** başlık **yalnızca** beyaz listeden
geçmiş `retryAfter` parametresinden türetilir (`ApiExceptionRenderer::headers()`).
Exception'ın kendi başlıkları **kopyalanmaz**: 405, **H7** gereği 404 olarak
döner ama `MethodNotAllowedHttpException` bir `Allow` başlığı taşır. Kopyalansaydı
404 yanıtı "bu rota var, şu metotlarla" derdi. `retryAfter`'ı yalnızca 429 ve
503 kodları beyaz listeye aldığı için başlık kendiliğinden doğru durumlarla
sınırlı kalır ve yukarıda sayılan bütün uçları kapsar.

---

## 5. PHP ve PHPUnit — bu dosyada ilk kez görebileceklerin

### 5.1 Veri sağlayıcı (`#[DataProvider]`)

```php
/** @return iterable<string, array{string, mixed, string}> */
public static function gecersizDegerler(): iterable
{
    yield 'isim tek harf' => ['guestName', 'Ş', 'min'];
    // ...
}

#[Test]
#[DataProvider('gecersizDegerler')]
public function an_invalid_value_is_rejected_by_its_rule_and_nothing_is_written(
    string $alan, mixed $deger, string $kural,
): void { ... }
```

- PHPUnit test metodunu sağlayıcının **her satırı için ayrı** çalıştırır. Rapor
  satırı etiketi taşır: `with data set "isim tek harf"`.
- Sağlayıcı **`static`** olmak zorunda (PHPUnit 11'den beri; projede 12). Test
  nesnesi henüz yokken çağrılır; `$this` kullanılamaz.
- `yield`, diziyi baştan kurmadan satırları **birer birer** üreten bir
  *generator*'dır. Dönüş tipi bu yüzden `iterable`.
- `mixed`: "her tip olabilir" demek. Bozuk değerler bilerek dizi, sayı, metin,
  `true` olabildiği için parametre tipi dar tutulamaz.

### 5.2 Tipli sınıf sabitleri (PHP 8.3)

```php
private const string YOK_OLAN_ULID = '01jbz8q4n6r2w3x5y7t9v0kd1m';
private const array YANIT_ANAHTARLARI = ['id', 'guestName', ...];
private const array YANIT_ANAHTARLARI_MESAJLI = [...self::YANIT_ANAHTARLARI, 'message'];
```

`const string` — sabitin tipi yazılır; alt sınıf onu başka tiple ezemez. `...`
(spread) bir diziyi başka dizinin içine açar; sabit ifadelerinde de çalışır.

### 5.3 Adlandırılmış argümanlar ve `??=`

```php
$this->dugunDavetiyesi(plan: SubscriptionTier::Gold);
$this->planSat($dugun, SubscriptionTier::Gold, iadeEdildi: true);
```

`plan:` ile ilk parametreyi (`$overrides`) atlayıp doğrudan ikinciye değer
verirsin. `iadeEdildi: true` ise bir `true`'nun **ne anlama geldiğini** çağrı
yerinde okunur kılar.

### 5.4 İki dizi karşılaştırması

| | Sıra önemli mi? | Kullanım |
|---|---|---|
| `assertSame($a, $b)` | **Evet** + tipler katı | Liste sırası testin konusuysa (M5) |
| `assertEqualsCanonicalizing($a, $b)` | Hayır | Anahtar **kümesi** (JSON'da sıra anlamsız) |

### 5.5 `assertJsonPath` + closure

```php
->assertJsonPath('data.id', fn (string $id): bool => preg_match('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/', $id) === 1)
```

Beklenen değer yerine bir fonksiyon verilirse Laravel değeri ona geçirir ve
`true` bekler. Değer metin değilse `string` parametre tipi `TypeError` fırlatır —
test yine kırmızı yanar, sessizce geçmez.

### 5.6 Zaman, IP ve başlıklar

| Yardımcı | Ne yapar |
|---|---|
| `travelTo($an)` / `travel(61)->seconds()` | `now()`'ı dondurur / ilerletir. Laravel test sonunda kendisi sıfırlar |
| `withServerVariables(['REMOTE_ADDR' => ...])` | İsteğin IP'sini değiştirir — `$request->ip()` bunu okur |
| `withToken()` / `withHeader()` | **Kalıcıdır**: sonraki TÜM isteklere eklenir |
| `flushHeaders()` | Kalıcı başlıkları temizler — sahibin token'ı misafirin isteğine taşınmasın |
| `forgetAuthState()` | **T13** — guard önbelleğini boşaltır (`tests/TestCase.php`) |
| `DB::enableQueryLog()` | Sonraki sorguları kaydeder; `getQueryLog()` boşsa hiç sorgu açılmamıştır |
| `call(..., server: [...], content: '...')` | Ham gövde göndermek için (yarım JSON) — `postJson` gövdeyi kendisi JSON'a çevirir, bozamazsın |

### 5.7 `model-property<Invitation>` (PHPStan)

```php
/** @param array<model-property<Invitation>, mixed> $overrides */
```

Larastan'ın tipi: "yalnızca `Invitation` modelinin gerçek kolon adları". Fabrikanın
`create()` metodu bunu bekler. `array<string, mixed>` yazsaydık PHPStan level 8
"her metin kolon adı değildir" diye itiraz ederdi — ve haklıdır: `'titel'` diye bir
yazım hatası o zaman analizde yakalanır.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Her testte **aynı** gövde | O gövdenin dokunmadığı her davranış mutasyona açık kalır (M8, M9) |
| 2 | 🔴 Türkçe karakterli bir sızıntı işaretini **ham gövdede** aramak | Laravel JSON'u `İ` diye kaçırır: `'İsmail'` ham gövdede **hiç geçmez** → `assertStringNotContainsString` her zaman yeşil. Çözülmüş JSON'a (`->json(...)`) bak |
| 3 | Beklenen değeri üretim koduyla hesaplamak (`IpHasher::hash()`) | Kod bozulunca beklenti de bozulur — totoloji |
| 4 | Son tarih testini "şimdi"ye göre kurmak | Takvim ilerleyince kırmızı; saat 21:00 UTC'yi geçince başka sonuç (T12) |
| 5 | Kota testini sınırın **altında** kurmak | `>` / `>=` farkı görünmez (M2) |
| 6 | Faker ismine `assertDatabaseMissing` ile güvenmek | Rastgele isim bir gün tutar → yılda bir kırmızı |
| 7 | `withToken()` sonrası misafir isteği atmak | Sahibin token'ı misafir isteğinde de gider; sonuç bugün aynı, yarın değil |
| 8 | Mutasyon tablosunu **elle** yazmak | Eski tablonun iki satırı yanlıştı (§0) |
| 9 | 🔴 Mutasyon koşucusunu doğrulamamak | Denetimde başıma geldi: filtre `Tests\Feature\RsvpTest` tek ters bölüyle verilince PHPUnit onu regex sanıp (`\R`) **0 test** koştu ve 34 mutantın **hepsi** "yaşadı" göründü. Koşucu artık vaka sayısı tabana eşit değilse sonucu reddediyor |

---

## 7. Kendin dene

```powershell
php artisan test --filter=RsvpTest
```

Beklenen: **86 vaka, 86 yeşil**. Bir kırmızı görürsen ortam farkıdır, bana gönder.
(25 Eylül 2026'dan önce bu dört test bilerek kırmızıydı — §4.)

```text
a_boolean_is_not_a_party_size
a_nul_byte_is_rejected_as_malformed_instead_of_being_truncated  (×4)
a_truncated_json_body_is_malformed_not_invalid
a_rate_limited_reply_carries_the_retry_after_header
```

```powershell
composer check
```

✅ Bu komut **yeşil biter**: `pint`, `phpstan`, `errors:export --check` ve
testlerin tamamı. Zincir fail-fast olduğu için bir şey kırılırsa **SON** satıra bak.

**Elle mutasyon (kural 14):** `app/Http/Controllers/Api/V1/RsvpController.php`'de
`$invitation->rsvps()` yerine `\App\Models\Rsvp::query()` yaz, testi koş.
`the_owner_sees_only_this_invitations_replies_newest_first` kırılmalı. Eski
dosyada bu mutant **yaşıyordu**. Sonra geri al (`git checkout -- app/`).

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Mutant** | Üretim kodunun bilerek bozulmuş kopyası |
| **Mutantı öldürmek** | Bozulmuş kodla en az bir testin kırmızıya dönmesi |
| **Eşdeğer mutant** | Kodu değiştiren ama davranışı değiştirmeyen mutant — öldürülemez, öldürülmemeli |
| **Totoloji (test)** | Beklenen değeri test edilen kodla hesaplayan, dolayısıyla hiçbir şey kanıtlamayan test |
| **Veri sağlayıcı** | Bir testi farklı girdilerle tekrar koşturan statik metot |
| **Generator / `yield`** | Değerleri tek tek üreten fonksiyon |
| **NUL baytı (`\0`)** | Kodu 0 olan karakter; C dizelerinde "dize burada bitti" demek |
| **libpq** | PostgreSQL'in C istemci kütüphanesi; PHP'nin `pdo_pgsql`'i onu kullanır |
| **Fikstür (fixture)** | Testin üzerinde koştuğu hazır veri kurgusu |
| **Duvar saati** | Saat dilimi bilgisi olmayan yerel saat ("19:30") |

---

## 9. Sıradaki

**Kararlar** (✅ 25 Eylül 2026'da öneriler uygulandı, ayrıntı §4):

| # | Karar | Uygulanan |
|---|---|---|
| D-1 | NUL baytı: 400 (global) mı, 422 (alan başına) mı? | ✅ 400 — tek middleware (`RejectMalformedInput`) |
| D-2 | `integer` → `integer:strict` | ✅ `guestCount` için · `giftOptions` açık |
| D-3 | Renderer 429/503'te `Retry-After` başlığını göndersin | ✅ `retryAfter` parametresinden türetilerek |

Denetimin sıradaki test dosyası
**`InvitationTest.php`** — orada bu dosyadakilerden daha ağır bir bulgu var
(yayından sonra paywall aşılıyor); ayrıntı denetim raporunda.
