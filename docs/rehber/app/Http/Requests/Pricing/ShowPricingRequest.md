# `app/Http/Requests/Pricing/ShowPricingRequest.php` — kataloğun hedefi

> **Faz:** 18.3 (`SEC-01`), adım 18.3.2 · **Karar:** K163 (yanıt aynı, istek hedef alır)
> **Kullanan:** `PricingController::forUser()` → `GET /api/pricing`
> **Test:** `tests/Feature/PricingTest.php`

---

## 1. Ne doğrular?

Tek, isteğe bağlı bir sorgu parametresi:

```
GET /api/pricing                      -> davetiyesiz (paket) fiyatı
GET /api/pricing?invitation=01J…      -> o davetiyenin fiyatı
```

```php
'invitation' => ['sometimes', 'nullable', 'string', 'ulid'],
```

`GET` isteğinde de `FormRequest` çalışır: Laravel sorgu dizesini ve gövdeyi aynı girdi torbasında
doğrular. Kural string olarak yazıldı (D6: kural **adı** hata sözleşmesinin parçasıdır; bozuk
kimlik `error.fields.invitation[0].rule = "ulid"` döner).

## 2. Neden gerekli oldu?

İlk yayın indirimi artık tek açık siparişe veriliyor (K158). "Bu kullanıcı uygun mu?" sorusunun
cevabı **hangi hedef için sorulduğuna** bağlı: indirimi tutan siparişin kendi davetiyesi için
evet, başka her hedef için hayır (K159). Katalog hedefi bilmezse iki kötü seçenek kalır:

- hep "hayır" → ödeme sayfasına dönen kullanıcı kendi indirimini kaybolmuş görür;
- hep "evet" → başka davetiyenin sayfası 99,60 gösterir, checkout 249,00 keser.

Yanıt biçimi değişmedi (K163); yalnızca istek bir bilgi daha taşıyabiliyor.

## 3. 🔴 Sahiplik neden sınanmıyor?

Projede kimlikler URL'nin yapısında durur ve Policy ile korunur (N1). Burada bilerek öyle değil:

- Kimlik hiçbir kaydı **açmaz**: yalnızca kullanıcının **kendi** siparişlerinin `invitation_id`'siyle
  karşılaştırılır. Başkasının davetiye kimliği hiçbir siparişiyle eşleşmez; sonuç "başka hedef"tir
  ve en sıkı cevabı (indirim yok) verir.
- Cevap ne olursa olsun bir **gösterimdir**; tahsilatı checkout belirler ve o uç davetiyeyi rota
  bağlaması + `Gate::authorize('publish')` ile doğrular.
- Var olmayan bir kimlik de aynı cevabı alır: varlık sızmaz (H7'nin ruhu).

`PricingTest::someone_elses_invitation_in_the_catalog_query_unlocks_nothing` bunu kilitler.

## 4. Sık hatalar

| Hata | Sonuç |
|---|---|
| `exists:invitations,id` eklemek | Sorgu başına bir SELECT daha ve davetiyenin varlığını sızdıran bir 422 |
| Parametreyi controller'da `$request->query('invitation')` ile okumak | Doğrulanmamış girdi Action'a iner (CLAUDE.md §1) |
| Misafir ucuna (`/public/plans`) aynı parametreyi eklemek | O yanıt herkes için aynıdır ve ETag'le önbelleklenir; hedef anlamsız |

## 5. Kendin dene

```powershell
php artisan test --filter=PricingTest
```
