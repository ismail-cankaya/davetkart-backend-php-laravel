# `database/migrations/..._create_contact_messages_table.php`

> **Faz:** 8, dosya 8.13 · **Kurallar:** K39 · K40 · K14 · E1

---

## 1. Kolonlar

| Kolon | Tip | Not |
|---|---|---|
| `id` | bigint | K40: hicbir URL'de gecmiyor |
| `name` | varchar(120) | `rsvps.guest_name` ile ayni genislik |
| `email` | varchar(255) | RFC 5321 ust siniri. 🔴 **UNIQUE degil** |
| `subject` | varchar(20) + CHECK | Enum'dan beslenen kisit (K39) |
| `message` | text | Ust sinir **dogrulamada**, kolonda degil (E6) |
| `ip_hash` | char/varchar(64) | 🔴 Ham IP degil (K14) |
| `created_at` | timestamp + index | Destek ekibinin tek sorgu deseni |

---

## 2. `email` neden UNIQUE degil?

Ayni kisi birden fazla kez yazabilir ve yazmali. UNIQUE koymak ikinci
mesaji bir **veritabani hatasina** cevirirdi. `users.email`'deki UNIQUE bir
**kimlik** kuralidir; burada kimlik yok, **beyan** var.

---

## 3. CHECK kisiti enum'dan beslenir

```php
$allowed = "'".implode("', '", ContactSubject::values())."'";
```

Elle yazilsaydi enum'a altinci bir konu eklendigi gun kisit **sessizce
eskirdi** (**K39**). Kaynak bir derleme zamani sabiti oldugu icin string
birlestirme burada guvenlidir — kullanici girdisi degil.

`ContactTest::the_database_refuses_an_unknown_subject` bu kisiti **modeli
atlayarak** dogruluyor: dogrulama HTTP'ye aittir ve atlanabilir (konsol,
kuyruk, seeder), CHECK atlanamaz.

---

## 4. Neden `subject` icin indeks yok?

Bugun konu bazli bir sorgu **yok** — listeleme ucu bile yok. Ihtiyac
dogmadan indeks eklemek, yazma maliyetini bugunden odemektir (**E1**
ailesi). `created_at` indeksi ise destek ekibinin kesin olarak yapacagi tek
siralamayi (en yeniden en eskiye) karsiliyor.

---

## 5. `ip_hash` neden 64 karakter?

`hash_hmac('sha256', ...)` 256 bit = 32 bayt = **64 onaltilik karakter**.
`rsvps.ip_hash` ile ayni genislik — ayni fonksiyon, ayni sinif
(`App\Support\IpHasher`, 8.7).
