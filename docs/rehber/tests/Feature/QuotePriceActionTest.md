# `tests/Feature/QuotePriceActionTest.php`

> **Faz:** 13, adım 13.4

Fiyatlar 249/399/549 ve ilk yayın %60 olarak sabitlenir, böylece beklenen sayılar
testin içinden okunabilir. Gruplar:

- **İlk yayın:** yeni kullanıcı alır. Ödenmiş, iade edilmiş ve hediye (bedava) siparişi
  olan almaz. Bekleyen, başarısız ve süresi dolmuş sipariş indirimi bitirmez. Misafir
  hiç almaz. `0` kapatır, `100` gürültülü bir hata verir.
- **Kampanya:** geri dönen müşteriye ve misafire uygulanır. Başlamamış, bitmiş ve durmuş
  kampanyalar yok sayılır. Plana özel kampanya yalnız o plana uygulanır. Kampanyaların en
  büyüğü seçilir, eşitlikte en geç biten.
- **İkisi birden:** toplanmazlar, büyük olan kazanır; eşitlikte ilk yayın.
- **Performans:** üç plan iki sorgu.
- **Hediye kodu:** kalan fiyata uygulanır. Ücretsiz ve büyük sabit kod tam olarak sıfıra
  indirir. Liste fiyatı üzerinden hesaplanmış kod reddedilir.
