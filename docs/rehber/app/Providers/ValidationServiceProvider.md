# `app/Providers/ValidationServiceProvider.php`

> **Faz:** 19.9 (`SEC-05`, K258) · **Ana kılavuz:** `app/Rules/PasswordPolicy.md` · **Test:** `tests/Feature/PasswordPolicyTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Uygulamanın kendi dize adlı doğrulama kurallarını kaydeder. Bugün tek kural: `uncommon_password` (`Validator::extend`).

## Neden böyle

Hata sözleşmesi başarısız kuralı adıyla raporlar (`Str::snake($rule)`); kural nesnesi sınıf adıyla raporlanır ve sözleşmeye sızar (D6). Dize adlı kural `validation.rules.uncommon_password` çevirisini bulur. `AppServiceProvider` ayrı bir hattın dosyasıydı; kural kaydı kendi sağlayıcısında.

## Kurallar

- `bootstrap/providers.php`'de `MonitoringServiceProvider`'dan sonra.
- Kural mantığı burada değil, `PasswordPolicy::isCommon()`'da.

## Sık hata

| Hata | Sonuç |
|---|---|
| Kuralı `new PasswordPolicy` nesnesi olarak yazmak | API yanıtına sınıf adı sızar, frontend çeviriyi bulamaz |
