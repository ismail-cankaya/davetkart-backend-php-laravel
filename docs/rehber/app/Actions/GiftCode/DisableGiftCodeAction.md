# `app/Actions/GiftCode/DisableGiftCodeAction.php`

> **Faz:** 11, adım 11.10 · Komut: `gift-codes:disable`

---

`handle(GiftCode $code): bool` → `true` kapatıldı, `false` zaten kapalıydı.

Tek bir koşullu `UPDATE` (`WHERE disabled_at IS NULL`). Koşul veritabanında, yazma anında
doğrulandığı için eşzamanlı iki kapatma ilk damgayı ezemez (E2). Sonra model `refresh()`
edilir: komut "zaten kapalı (tarih)" mesajı için güncel damgayı gösterebilsin.

Neden Action? Komut ince kalsın ve kapatma kuralı (silme yok, ilk damga kalır) ileride bir
admin paneli ya da toplu kapatma (`--batch`) aynı yerden geçebilsin.

Ayrıntılı anlatım ve testler: [`../../Console/Commands/DisableGiftCode.md`](../../Console/Commands/DisableGiftCode.md).
