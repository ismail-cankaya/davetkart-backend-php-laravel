# `database/migrations/..._create_assistant_usages_table.php`

> **Faz:** 8, dosya 8.8 · **Kurallar:** K40 · E2 · E8 · E11 ailesi

---

## 1. Tablo ne saklıyor, ne saklamıyor

```
id · user_id · usage_date · message_count · timestamps
```

Kullanicinin **mesaji yok**. Kotanin sorusu "kac mesaj", "hangi mesaj"
degil; bir sinir icin gereken **en az veri** saklanir. Bir sohbet gecmisi
tablosu sistemdeki en hassas metin deposu olurdu (KVKK).

---

## 2. `bigint` id — K40'in ayirt edici sorusu

*Bu kimlik bir URL'de geciyor mu?* Hayir. Satiri disaridan kimse
adresleyemez; yalnizca `(user_id, usage_date)` ciftiyle bulunur.

| Tablo | Anahtar | Neden |
|---|---|---|
| `invitations` | ULID | `/invite/{id}` — paylasilan linkin kendisi |
| `rsvps` | ULID | `DELETE /api/rsvps/{id}` |
| `timeline_events` | bigint | Hicbir URL'de gecmiyor |
| **`assistant_usages`** | **bigint** | Ayni sebeple |

---

## 3. 🔴 `UNIQUE(user_id, usage_date)` kotanin asil korumasi

Kisit olmasaydi, es zamanli iki "ilk mesaj" **iki ayri sayac** satiri acar
ve kullanici kotayi ikiye katlardi. `insertOrIgnore` bu kisite dayanir:
catisma bir **hata** degil, "zaten var" anlamina gelir.

**E2:** benzersizlik `if` ile degil **veritabani kisitiyla** korunur.

Ek fayda bedava: ayni kisit, `WHERE user_id = ? AND usage_date = ?`
sorgusunun **indeksidir**. Ayrica bir indeks tanimlanmadi.

---

## 4. `date`, `timestamp` degil (E8)

Kota "bugun kac mesaj" sorusudur. Saat tasimak gun sinirini
bulaniklastirirdi ve Faz 5'in 43. dersini (`isPast()` bir tarih kolonunda
bir gun kaydirir) davet ederdi.

> Gun sinirinin **hangi saat diliminde** oldugu ayri bir karar:
> [`AskAssistantAction.md`](../../app/Actions/Assistant/AskAssistantAction.md) §5.

---

## 5. 🔴 PostgreSQL'de UNSIGNED yoktur

```php
$table->unsignedInteger('message_count')->default(0);
```

PostgreSQL'de bu **`integer`**'a duser ve `-5` kabul eder. Negatif bir
sayac, kotayi sessizce genisletirdi (`message_count < 30` her zaman dogru).
`rsvps.guest_count`'ta ogrenilen ayni ders:

```sql
ALTER TABLE assistant_usages
  ADD CONSTRAINT assistant_usages_message_count_check CHECK (message_count >= 0);
```

Kisit **semada**, `if`'te degil: bir `if` konsola, kuyruga ve seeder'a
atlanabilir (**A8/E11** ailesi).

---

## 6. `cascadeOnDelete`

Sayac **tamamen** kullaniciya ait. Hesap silinince gitmeli (KVKK: unutulma
hakki). `rsvps` ile ayni gerekce.
