# `app/Http/Requests/Account/ConfirmEmailChangeRequest.php`

> **Faz:** 14 (14.10, K144) · **Ana kılavuz:** `RequestEmailChangeAction.md` §4 · **Test:** `tests/Feature/EmailChangeTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

`POST /api/public/email-change/confirm?user=…&email=…&old=…&expires=…&signature=…` bağlantısının doğrulaması.

## Neden böyle

🔴 Kimlik yok (public): bağlantıyı açan oturum açmamış olabilir. Yetkinin kanıtı **imzadır**. Değerler sorgudan okunur, çünkü imza sorguyu kapsar; gövde imzalanmamıştır.

## Kurallar

- `validationData()` sorguyu döner; kurallar gövdeye değil sorguya uygulanır.
- Her hata (eksik parametre dahil) aynı `EMAIL_CHANGE_INVALID`: kurcalayan hangi parçanın yanlış olduğunu öğrenmesin.
- İmza doğrulamadan **sonra** (`passedValidation`): göreli imza, süre + bütünlük.

## Sık hata

| Hata | Sonuç |
|---|---|
| Değeri gövdeden okumak | İmzasız alan: saldırgan adresi değiştirebilir |
| Laravel'in varsayılan 422 alan hatalarını döndürmek | Hangi parametrenin eksik olduğu sızar |
