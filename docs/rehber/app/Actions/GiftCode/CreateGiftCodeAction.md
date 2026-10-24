# `app/Actions/GiftCode/CreateGiftCodeAction.php` — elle belirlenen kod

> **Faz:** 11, adım 11.9 · **Komut:** `gift-codes:create`

---

## 1. Ne yapar?

`DUGUN2026` gibi akılda kalan, `max_redemptions` kez kullanılabilen bir kod yazar.

1. `GiftCodeFormat::isValidCustom()`: 4–32 karakter, `A-Z0-9` (normalize sonrası)
2. `fill()`: alanları **açıkça** atar (`#[Fillable]` boş, E7)
3. `save()`: UNIQUE ihlali olursa açık bir mesaj

## 2. 🔴 "Zaten var mı?" sorgusu yok, kısıt var (E2)

```php
try {
    DB::transaction(static fn (): bool => $giftCode->save());
} catch (UniqueConstraintViolationException) {
    throw new InvalidArgumentException('Bu kod zaten var ...');
}
```

Önce `exists()` sorup sonra `save()` etmek iki komut aynı anda koşarsa yarışa girerdi. UNIQUE
kısıt girmez. `dugun 2026` ile `DUGUN2026` **aynı** kod sayılır çünkü ikisi aynı özete
iner. Mesaj bunu operatöre söylüyor.

### Neden `DB::transaction` içinde?

PostgreSQL'de bir transaction içindeki hata **bütün transaction'ı zehirler**: sonraki her
sorgu `current transaction is aborted` alır. Komut normalde transaction dışında koşar, ama
testlerde `RefreshDatabase` her şeyi bir transaction'a sarar. İç `DB::transaction` bir
**savepoint** açar. İhlal yalnızca savepoint'i geri alır, dıştaki transaction sağlam kalır.
Bu sorun ilk test koşusunda yakalandı (`an_existing_code_in_any_spelling_is_rejected`).

## 3. `fill()` neden public static?

`GenerateGiftCodeBatchAction` de aynı alanları atıyor. İki ayrı atama listesi olsaydı biri
yeni bir kolonu (örneğin ileride `campaign_id`) unuturdu. `@internal` işaretli: dışarıdan
model doldurmak için kullanılmamalı.

## 4. Düz kod nereye gider?

Hiçbir yere. `code_hash` ve `code_hint` yazılır. Komut çıktısı da kodu yeniden basmaz:
operatör onu zaten yazdı ve terminal geçmişinde bir kopya yeter.

> ⚠️ Canlıda `php artisan gift-codes:create DUGUN2026 ...` komutu sunucunun shell
> geçmişine (`~/.bash_history`) düşer. Elle girilen kodlar kamuya dağıtılan kodlar olduğu için
> bu kabul edilebilir. Tek kullanımlık kodlar için `generate` kullanılır ve onlar komut
> satırında hiç görünmez.
