# `2026_11_25_100000_mark_existing_accounts_as_verified`

> **Faz:** 19 ortak düzeltme — Hat A `E1` · **Karar:** K260 (K191'in "mevcut hesaplara göç yok" maddesini geçersiz kılar)
> **Test:** `tests/Feature/MarkExistingAccountsVerifiedMigrationTest.php`

## Neden

19.14 (SEC-09, K191) ödeme ve yayını doğrulanmış e-postaya bağladı. Doğrulama postası yalnızca **kayıtta** gider; 19.14'ten
önce açılmış hesaplar posta hiç almadı ve `email_verified_at` boş kaldı. Göç olmadan bu hesaplar canlıya çıkıldığı gün ödeme
başlatamaz, yayınlayamaz ve ilk yayın indirimini alamaz; tek çıkış yolu banttaki "yeniden gönder"di. İsmail'in kararı (25 Kasım
2026): eski hesaplar doğrulanmış sayılır.

## Ne yapar

```sql
update users set email_verified_at = created_at
where email_verified_at is null and created_at <= now()
```

- Damga `created_at`'tir, `now()` değil: kayıt "bu adres doğrulandı" demez, "hesap doğrulama kuralından önce vardı" der.
  Destekte ayırt etmek için: `email_verified_at = created_at` olan satırlar bu göçten gelir (gerçek doğrulama kayıttan
  sonraki bir an yazar).
- `created_at <= now()`: göç koşarken açılan hesap doğrulama postasını beklemeye devam eder.
- Zaten doğrulanmış hesaba dokunmaz.

## Sınırlar

- `down()` boş: göçün işaretlediği satır gerçekten doğrulanmış olandan ayırt edilemez (yukarıdaki eşitlik bir ipucu, kanıt değil).
- Göç bir kez koşar. 19.14 ile aynı sürümde (`v1.2.0`) dağıtılır; dağıtımdan sonra açılan hesaplar normal doğrulama yolundadır.
- Adresi hiç kanıtlanmamış eski hesaplar ödeme/yayın yapabilir: bilinen ve kabul edilen bedel (K260).

## Nasıl denenir

`php artisan test --filter=MarkExistingAccountsVerifiedMigrationTest` · mutantlar: `whereNull` kaldırıldı (doğrulanmışın damgası
ezilir) → kırmızı; `created_at` koşulu kaldırıldı (sonradan açılan hesap işaretlenir) → kırmızı.
