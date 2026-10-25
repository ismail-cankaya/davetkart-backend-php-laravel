# `tests/Feature/PricingTest.php`

> **Faz:** 13, adım 13.6

Misafir kataloğu birebir biçimde sınanır (`assertExactJson`: sözleşmenin kendisi). Ayrıca
şunlar kanıtlanır: çalışan kampanya bitiş tarihiyle görünür ama kimliği ve notu sızmaz;
public uç token gönderilse bile kişisel olmaz; yeni kullanıcı üç planda da %60'lı fiyatı
görür, ödenmiş siparişi olan görmez; kişisel uç token ister; havale hesabı yalnızca yöntem
açıkken gelir; ETag 304 döner ve kampanya açılınca tazelenir; yanlış yazılmış ayar yalnızca
kataloğu 503 yapar, `/api/ping` çalışmaya devam eder.
