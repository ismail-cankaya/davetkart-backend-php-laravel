# `app/Services/Payment/Shopier/ShopierAmount.php`

> **Faz:** 12 — adım 12.5 · **Karar:** **K124** (E8) · **Test:** `tests/Unit/ShopierSignatureTest.php` (tutar bölümü)

## 1. `format(int $minor): string` — float yok

```php
sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);   // 39900 -> "399.00"
```

Daha kısa görünen yol:

```php
number_format($minor / 100, 2, '.', '');   // ❌ $minor / 100 bir FLOAT
```

`/` bir float üretir ve float bazı ondalıkları tam temsil edemez
(`0.1 + 0.2 === 0.30000000000000004`). `number_format` bugünkü fiyatlarda doğru
sonucu verir; ama "bugün doğru" ile "her zaman doğru" arasındaki fark burada bir
imzanın tek baytıdır. Tamsayı bölmesi (`intdiv`) ve kalan (`%`) hiçbir zaman yuvarlamaz.

🔴 **Aynı değişken kuralı:** imzaya giren metin ile forma yazılan metin **aynı
değişkenden** gelir (`ShopierGateway::startCheckout`). İki yerde ayrı ayrı biçimlemek,
bir gün ikisinin ayrışmasına (biri `399.00`, öteki `399`) ve Shopier'in "imza
geçersiz" demesine kapı açar.

## 2. `parse(string): ?int` — dar dilbilgisi

OSB'nin `price` alanının biçimi resmi dokümanda görülmedi. Kabul edilenler:
`399.00`, `399,00`, `199.5`, `399`. Reddedilenler (`null`): binlik ayraçlı
`1.399,00`, üç ondalık, eksi, bilimsel gösterim, birimli `399.00 TL`.

**Neden tanınmayanı tahmin etmiyoruz?** `1.399,00` bin üç yüz doksan dokuz mu, bir
virgül üç yüz doksan dokuz mu? Tahmin yanlışsa tutar kontrolü (K126) ya haklı bir
ödemeyi reddeder ya da haksız birini kabul eder. `null` dönmek, kararı çağırana
bırakır: OSB ucu tanınmayan tutarla siparişi **ödenmiş yapmaz** ve `critical` log
yazar. Fail-safe.
