# `app/Http/Requests/Account/ConfirmEmailVerificationRequest.php`

> **Faz:** 19.14 (`SEC-09`, K191) · **Ana kılavuz:** `ConfirmEmailChangeRequest.md` (aynı desen) · **Test:** `tests/Feature/EmailVerificationTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Doğrulama bağlantısının sorgusu (`user`, `address` parmak izi) ve göreli imzası.

## Neden böyle

`ConfirmEmailChangeRequest` deseni: değerler sorguda, imza onları kapsar; her bozukluk aynı 422'ye düşer (H6).

## Kurallar

- `address` 64 karakterlik parmak izi; adresin kendisi bağlantıda yok.
- İmza doğrulaması kurallardan sonra.

## Sık hata

| Hata | Sonuç |
|---|---|
| Adresi bağlantıya açık yazmak | Posta kutusuna erişen herkes adresi görür; parmak izi yeter |
