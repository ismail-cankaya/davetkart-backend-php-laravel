# `tests/Unit/IbanTest.php`

> **Faz:** 10 — Dilim F, adım 10.62 · **15 vaka** · saf birim testi

Bkz. [`Iban.md`](../../app/Support/Iban.md) → *Testler*.

| Mutasyon (`Iban.php`) | Kırılan |
|---|---|
| Sağlama toplamı hep doğru | kontrol hanesi · tek hane · yer değiştirme |
| TR uzunluk kontrolü silindi | *"TR ama 25 karakter (sağlama toplamı doğru)"* |
| Normalize edilmesin | dörtlü gruplar · küçük harf |
| Biçim kontrolü silindi | *"çok kısa (sağlama toplamı doğru)"* (ilk sürümde **hayatta kaldı**) |
