# `app/Rules/PasswordPolicy.php`

> **Faz:** 19.9 (`SEC-05`) · **Karar:** K258 · kayıt `app/Providers/ValidationServiceProvider.php`
> **Test:** [`tests/Feature/PasswordPolicyTest.md`](../../tests/Feature/PasswordPolicyTest.md)

## Neden

Kayıt, sıfırlama ve değiştirme `12345678`, `password` gibi parolaları kabul ediyordu (`SEC-05`). Üç istek kuralı
ayrı ayrı yazıyordu; biri unutulsaydı açık orada kalırdı. Artık üçü de `...PasswordPolicy::rules()` kullanıyor.

## Kural

`min:8` · `max:255` · `uncommon_password`. Uzunluk 8 kaldı (İsmail'in kararı; ASVS 2.1.1 12 önerir).
Karmaşıklık kuralı (büyük harf, rakam) **yok**: ASVS bunu istemez, kullanıcıyı `Parola1!` gibi tahmin edilebilir
kalıplara iter. `uncommon_password` iki şeyi reddeder:

- Yaygın parola listesindeki her şey, büyük/küçük harf ayrımı olmadan (`QWERTY123456` da düşer).
- Tek karakterin tekrarı (`aaaaaaaaaaaa`): listede yok ama aynı zayıflık.

## Neden dize adlı kural (D6)

Kural **nesnesi** başarısız olunca Laravel onu sınıf adıyla raporlar; ad API sözleşmesine girer
(`RegisterRequest.md` "Neden `Password::min(8)` değil"). Bu yüzden mantık bu sınıfta, kayıt
`Validator::extend('uncommon_password', …)` ile. Yanıt: `{"rule": "uncommon_password"}`; frontend
`validation.rules.uncommon_password` çevirisini gösterir. Yeni hata kodu gerekmedi (`VALIDATION_FAILED`).

`AppServiceProvider` yerine ayrı sağlayıcı: Faz 19'da o dosya Hat A'nındı.

## Liste

- Dosya: `resources/security/common-passwords.txt`, düz metin, satır başına bir parola, küçük harf, tekrarsız (9.916).
- Kaynak: SecLists `Passwords/Common-Credentials/xato-net-10-million-passwords-10000.txt` (Daniel Miessler,
  **MIT**; lisans metni `resources/security/common-passwords.LICENSE`). İndirme 23 Kasım 2026; kaynak dosyanın SHA-256'sı `c63d5e4ccc31344d…`. Dönüşüm: küçük harf + tekrar silme.
- `10k-most-common.txt` yerine bu: bulgudaki `qwerty123456` öbüründe yok.
- Süreç başına bir kez okunur (statik dizi, `array_fill_keys` → O(1) arama). Laravel Cache'e konmaz:
  10.000 satırı her istekte Redis'ten çekmek dosyadan okumaktan pahalı.
- Neden Have I Been Pwned (`uncompromised()`) değil: dış istek yok, servis düşünce kayıt kilitlenmez, KVKK
  açısından parolanın parçası bile dışarı çıkmaz (§A-2 varsayılanı).

## `max:255` ve hash

Hash sürücüsü Argon2id (`HASH_DRIVER`, `.env.example`); bcrypt'in 72 bayt kesmesi yok. Sürücü bcrypt'e dönerse
72 bayttan uzun parolanın fazlası yok sayılır: kural yine 255 der, kılavuz bu notu taşır.

## Kapsam dışı

Mevcut hesaplar zorla değiştirilmez (§A-3): kural yalnızca yeni parola belirlenirken. Giriş hız sınırı `SEC-16`.
