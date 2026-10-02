# `database/migrations/2026_10_02_110000_add_edit_code_hash_to_rsvps_table.php`

> **Faz:** 10 — Dilim F, adım 10.59 · **Karar:** **K101**

---

```php
$table->string('edit_code_hash', 64)->nullable()->after('ip_hash');
```

| Karar | Neden |
|---|---|
| `string(64)` | `sha256` özeti onaltılık 64 karakter (`RsvpEditCode.md`) |
| `nullable` | Faz 10'dan önceki yanıtların kodu yok; onlar güncellenemez |
| İndeks **yok** | Yanıt kimliğiyle (birincil anahtar) bulunuyor, özet yalnızca karşılaştırılıyor |
| `#[Fillable]`'da **yok** | `ip_hash` gibi: sunucu yazar, istemci değil (`Rsvp.md`) |

Kolon yanıta hiç girmiyor: `RsvpResource` bir beyaz liste ve onu içermiyor. Sahibin listesinde
ne kod ne özet var (`RsvpEditTest::the_owner_never_sees_the_code`).
