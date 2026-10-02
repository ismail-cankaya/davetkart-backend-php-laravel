# `tests/Unit/IpBucketTest.php`

> **Faz:** 10 — Dilim F, adım 10.60 · **9 vaka** · Laravel'i ayağa kaldırmayan saf birim testi

Bkz. [`IpBucket.md`](../../app/Support/IpBucket.md). Veri sağlayıcı yedi girdiyi
(IPv4, IPv6, aynı `/64`, açık/kısa yazım, gömülü IPv4, boş, geçersiz) beklenen anahtarla
karşılaştırıyor. İki ek test **ayrılığı** iddia ediyor: komşu `/64`'ler ve iki farklı gömülü
IPv4 aynı kovaya düşmemeli. İkincisi olmasaydı gömülü IPv4'leri `::/64`'e toplayan bir hata
yalnızca tek bir girdiyle sınanmış olurdu.
