# `app/Console/Commands/DisableGiftCode.php` — `gift-codes:disable`

> **Faz:** 11, adım 11.10 · Action: `app/Actions/GiftCode/DisableGiftCodeAction.php`

---

## 1. Kullanım

```powershell
php artisan gift-codes:list                                  # kimliği bul
php artisan gift-codes:disable 01m5m333m57b7mnh6cegg6e0bm
#  INFO Kod kapatildi: …2026 (Instagram cekilisi)
php artisan gift-codes:disable 01m5m333m57b7mnh6cegg6e0bm
#  INFO Kod zaten kapali (2026-10-23 14:05).
```

| Durum | Çıkış kodu |
|---|---|
| Kapatıldı | 0 |
| Zaten kapalıydı | 0 (bilgi verir, damgaya dokunmaz) |
| Kimlik yok ya da ULID değil | 1 |

## 2. Neden kodla değil kimlikle?

`gift-codes:disable DUGUN2026` daha rahat olurdu. Ama düz kod komut satırına (ve
`~/.bash_history`'ye) bir kez daha yazılırdı. Tek kullanımlık kodlarda bu, CSV'nin dışında
ikinci bir kopya demek. Kimlik ise bir sır değil.

## 3. Ne değişir, ne değişmez?

| | Kapatınca |
|---|---|
| Yeni kullanım | ❌ `GIFT_CODE_INVALID` (yok/kapalı/süresi dolmuş/tükenmiş ile aynı yanıt) |
| Ödenmiş hediye siparişi | Yerinde; yayın hakkı devam eder |
| Bekleyen (`pending`) sipariş | Ödeme tamamlanabilir; indirim zaten siparişe yazıldı |
| Satır | **Silinmez**: kullanımlar koda bağlı (`restrictOnDelete`) |

Kapatma bir **sözü geri almak** değil, yeni söz vermeyi durdurmak.

## 4. `DisableGiftCodeAction`: koşul UPDATE'in içinde (E2)

```php
GiftCode::query()
    ->whereKey($id)
    ->whereNull('disabled_at')          // koşul yazma anında doğrulanır
    ->update(['disabled_at' => now(), ...]);
```

`if ($code->disabled_at === null) { $code->disabled_at = now(); $code->save(); }` yazılsaydı
iki operatör aynı anda kapattığında ikinci `save()` ilk damgayı ezerdi. Koşul `UPDATE`'in
içinde olunca veritabanı onu yazma anında doğrular: ikinci komut 0 satır günceller ve "zaten
kapalı" der. `ExpireStaleOrders`'ın toplu UPDATE deseni.

Test: `disabling_twice_keeps_the_first_timestamp`, ertesi gün ikinci kez kapatır, damga
değişmez.

## 5. Geri açma yok

Bu fazda `enable` komutu yok. Yanlışlıkla kapatılan bir kod için yeni bir kod oluşturulur.
Gerekirse tek satırlık bir komut: `disabled_at = null`.
