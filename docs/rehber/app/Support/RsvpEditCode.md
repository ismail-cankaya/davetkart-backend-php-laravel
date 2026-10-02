# `app/Support/RsvpEditCode.php`

> **Faz:** 10 — Dilim F, adım 10.59 · **Karar:** **K101**
> **Kullananlar:** `SubmitRsvpAction` (üretir) · `UpdateRsvpAction` (karşılaştırır) ·
> `UpdateRsvpRequest` (uzunluk)

---

## Ne yapıyor?

| Metot | İş |
|---|---|
| `generate()` | 40 karakterlik rastgele kod (`Str::random`, harf ve rakam) |
| `hash($code)` | Veritabanına yazılacak özet: `sha256` |
| `matches($storedHash, $code)` | Gelen kod özetle eşleşiyor mu? `hash_equals` ile |

## Kararlar

### Kod neden saklanmıyor, özeti saklanıyor?

Kod bir **parola** gibi: bilen, yanıtı değiştirebilir. Veritabanı bir gün sızarsa düz kodlar
herkesin LCV'sini değiştirmeye yeter; özetler yetmez. Kod misafire yalnızca ilk gönderimin
yanıtında, bir kez ulaşıyor.

### Neden `bcrypt` değil de `sha256`?

`bcrypt` (`Hash::make`), insanların seçtiği **tahmin edilebilir** parolalar için yavaş olacak
şekilde tasarlanmış. Bu kod insan seçimi değil: 40 karakter, harf ve rakam, kriptografik
rastgele (`random_bytes`). Yaklaşık 238 bitlik bir uzay kaba kuvvete zaten kapalı; yavaş bir
özet bir şey kazandırmaz, her güncellemede gereksiz CPU harcar. `IpHasher`'dan farklı olarak
uygulama anahtarı da karıştırılmıyor: IP'nin uzayı küçük (tahmin edilebilir), bu kodunki değil.

### Neden `hash_equals`?

Düz `===` karşılaştırması ilk farklı baytta durur. Yanıt süresinden kodun kaç baytının doğru
olduğu ölçülebilir (zamanlama saldırısı). `hash_equals` her zaman bütün baytları karşılaştırır.
Burada uzay zaten büyük, ama doğru araç ucuz.

### Neden 40?

`UpdateRsvpRequest` uzunluğu `size:40` ile doğruluyor: biçimsiz kod veritabanına gitmeden 422
alıyor. Sabit (`LENGTH`) iki yerden okunuyor; biri değişirse diğeri de değişiyor.
