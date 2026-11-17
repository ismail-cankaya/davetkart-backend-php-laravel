# `tests/Feature/PricingTest.php`

> **Faz:** 13, adım 13.6

Misafir kataloğu birebir biçimde sınanır (`assertExactJson`: sözleşmenin kendisi). Ayrıca
şunlar kanıtlanır: çalışan kampanya bitiş tarihiyle görünür ama kimliği ve notu sızmaz;
public uç token gönderilse bile kişisel olmaz; yeni kullanıcı üç planda da %60'lı fiyatı
görür, ödenmiş siparişi olan görmez; kişisel uç token ister; havale hesabı yalnızca yöntem
açıkken gelir; ETag 304 döner ve kampanya açılınca tazelenir; yanlış yazılmış ayar yalnızca
kataloğu 503 yapar, `/api/ping` çalışmaya devam eder.

---

## 🆕 Faz 18.3 (`SEC-01` · K159, K163)

Üç test eklendi: katalog hedefi biliyor (`the_personal_catalog_prices_the_target_it_is_asked_for`),
bozuk kimlik 422 (`a_malformed_invitation_in_the_catalog_query_is_a_validation_error`), başkasının
davetiye kimliği hiçbir şey açmıyor (`someone_elses_invitation_in_the_catalog_query_unlocks_nothing`).
Sonuncusu bir güvenlik testidir: sahipliğin bilerek sınanmadığı bir parametrenin zararsız olduğunu
kilitler.
