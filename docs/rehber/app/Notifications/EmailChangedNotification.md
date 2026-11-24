# `app/Notifications/EmailChangedNotification.php`

> **Faz:** 14 (14.10, K144) · **Ana kılavuz:** `ConfirmEmailChangeAction.md` · **Test:** `tests/Feature/EmailChangeTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

**Eski** adrese "e-posta adresiniz değiştirildi, bütün oturumlarınız kapatıldı" uyarısı.

## Neden böyle

Hesap ele geçirildiyse asıl sahibin haberi olsun. Yeni adres **maskeli** yazılır (`y***@gmail.com`): sahibi tanır, eski kutuya erişen üçüncü biri yeni adresi tam öğrenmez.

## Kurallar

- `ShouldQueue`: kuyruktan gider (15 saniye kuralı; yanıt süresi adresin varlığını sızdırmaz).
- Dil `config('davetkart.mail.locale')`; bağlantılar `davetkart.frontend.url`'den, isteğin Host başlığından değil.
- `mask()`: ilk karakter + `***` + `@alan`; `@` yoksa ya da başta ise `***`.

## Sık hata

| Hata | Sonuç |
|---|---|
| Yeni adresi açık yazmak | Eski kutuyu ele geçiren yeni hesabı da hedefler |
