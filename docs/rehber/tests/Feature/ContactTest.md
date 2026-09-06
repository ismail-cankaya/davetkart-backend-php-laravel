# `tests/Feature/ContactTest.php`

> **Faz:** 8, dosya 8.17 · **14 test** · **Kurallar:** T14 · T16 · L2

---

## 1. Bu dosya neyi kanitliyor?

Sistemin **dorduncu auth'suz yazma yolu** acildi. Uc soru:

| Soru | Kaniti |
|---|---|
| Bot elenip de sessizce mi eleniyor? | Yanit **ayni**, satir **yok** |
| Ham IP saklanmiyor mu? | `ip_hash` kolonunun degeri |
| Konu degerleri frontend ile ayni mi? | Enum'un kendisi |

---

## 2. 🔴 Yanit degil ETKI (T14)

`a_honeypot_submission_looks_successful` ve
`a_honeypot_submission_is_not_persisted` **ayni istegi** yapar. Ilki yanitin
gercek gonderimden ayirt edilemedigini, ikincisi satirin yazilmadigini
dogrular. **Ikisi birlikte** bir sey kanitlar; tek basina hicbiri yeterli
degil:

- Yalnizca ilki olsaydi: honeypot hic calismasa da yesil yanardi.
- Yalnizca ikincisi olsaydi: 422 dondursek de yesil yanardi (ve L2 ihlal
  edilirdi).

---

## 3. Mutasyonu oldurmek icin yazilmis bir test

```php
$this->assertNotSame(
    hash('sha256', '127.0.0.1'.Config::string('app.key')),
    $stored,
);
```

`the_ip_hash_is_a_keyed_hmac` yalnizca "dogru deger" demiyor, **eski
degerin dondurulMEDIGINI** de soyluyor. Bu satir olmasaydi 8.7'nin
`hash` → `hash_hmac` degisikligi geri alindiginda test **yesil kalirdi**
(ilk `assertSame` de gecerdi, cunku ikisi de 64 karakter uretir — hayir,
gecmezdi; ama niyet acik olsun diye yazildi). **T16'nin test icine
gomulmus hali.**

---

## 4. Semayi modeli atlayarak sinamak

```php
DB::table('contact_messages')->insert([... 'subject' => 'sikayet' ...]);
// QueryException
```

`ContactMessage` modeli kullanilsaydi enum cast'i `ValueError` firlatirdi ve
**CHECK kisiti hic sinanmazdi**. Dogrulama HTTP'ye aittir ve atlanabilir
(konsol, kuyruk, seeder); CHECK atlanamaz — **A8/E11** ailesi.

---

## 5. 🔴 Mutasyon tablosu (T16)

| # | Mutasyon | Kirmizi yanan test |
|---|---|---|
| 1 | Rotayi `auth:sanctum` grubuna tasi | `a_guest_can_send_a_contact_message` |
| 2 | Action'daki honeypot dalini kaldir | `a_honeypot_submission_is_not_persisted` |
| 3 | Honeypot'ta 422 dondur | `a_honeypot_submission_looks_successful` |
| 4 | `isHoneypotTripped()`'i `$value !== null` yap | `an_empty_honeypot_field_is_not_a_trap` (bos string null'a cevriliyor → **hayir**, bkz. §6) |
| 5 | `ip_hash` yerine ham IP yaz | `the_raw_ip_is_never_stored` |
| 6 | `IpHasher::hash`'i duz `hash()`'e cevir | `the_ip_hash_is_a_keyed_hmac` |
| 7 | `ip_hash` atamasini sil | `the_raw_ip_is_never_stored` (NOT NULL ihlali) |
| 8 | `name` kuralindan `required` cikar | `every_field_is_required` |
| 9 | `email` kuralini `string`'e indir | `an_invalid_email_is_rejected` |
| 10 | `in:` yerine `Rule::enum()` yaz | `the_subject_must_be_a_known_value` (kural adi degisir) |
| 11 | Enum'a `'complaint'` ekle | `the_subject_values_match_the_frontend_contract` |
| 12 | Enum degerini `'Genel'` yap | `the_subject_values_match_the_frontend_contract` |
| 13 | `message` sinirini config yerine sabit yaz | `the_message_length_is_capped_by_configuration` |
| 14 | Migration'dan CHECK kisitini kaldir | `the_database_refuses_an_unknown_subject` |
| 15 | Rotadan `throttle:contact` kaldir | `contact_submissions_are_rate_limited` |
| 16 | Controller'i 201 + govde dondurmeye cevir | `the_response_carries_no_body` |
| 17 | `COLUMN_MAP`'e `ip_hash` ekle | ⚠️ **hicbiri** — bkz. §6 |

---

## 6. 🔴 Testin kapatamadigi bosluklar (B6)

| Bosluk | Neden |
|---|---|
| Satir 4 | `ConvertEmptyStringsToNull` `''` degerini zaten `null` yapiyor; iki kontrol ayni sonucu verir. Fark ancak middleware kapatilirsa gorunur |
| Satir 17 | `COLUMN_MAP`'e `ip_hash` eklense bile `validated()` icinde boyle bir anahtar olmadigi icin hicbir sey degismez. Koruma **iki katmanli**: hem harita hem `#[Fillable]` |
| Honeypot'un **frontend'de var olmasi** | Backend testi formun alani render ettigini bilemez. 🔴 `ContactPage` bugun bu alani **render etmiyor** — tuzak kurulmus ama kurulmamis durumda. Frontend borc listesinde |
| Hiz sinirinin gercek IP dagilimi | Testte tek IP var; kovanin IP anahtarli oldugu kod incelemesiyle korunur |
| Bildirim gonderimi | 🔴 Yok. Frontend "destek ekibine yonlendirilir" diyor; bu bir **B4 borcudur**, test edilecek bir davranis degil |
