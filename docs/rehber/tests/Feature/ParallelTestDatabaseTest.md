# `tests/Feature/ParallelTestDatabaseTest.php`

> Faz 21.10 · `TST-06`.

`composer test:parallel`'de (paratest) her süreç kendi veritabanında koşmalı; yoksa `RefreshDatabase`'in tablo silme ve
geri alma işleri birbirine karışır. Laravel bunu `ParallelTesting` ile yapar: süreç başına `<ad>_test_<token>`
(`davetkart_test_test_7`). Test iki şeyi sınar:

1. Config'teki veritabanı adı: sıralı koşuda `davetkart_test` (K182 son ekiyle `davetkart_test_<ek>`), paralelde
   `davetkart_test_test_<token>`.
2. Bağlantının gerçekten oraya gittiği: `select current_database()`.

🔴 **Tuzak:** Laravel adı yalnızca veritabanı trait'i (`RefreshDatabase`, `DatabaseTransactions`, `DatabaseMigrations`,
`DatabaseTruncation`) kullanan sınıflarda değiştirir. Bu testin ilk hâli trait'siz `TestSuiteIsolationTest`'e yazılmıştı ve
paralelde kırmızı yandı (`'davetkart_test_test_19'` beklenirken `'davetkart_test'`): o sınıf ortak tabanda kalıyordu. Bugün
trait'siz üç sınıf (`HardeningTest`, `HealthTest`, `MaintenanceModeTest`) veritabanına yazmıyor; yazacak yeni bir test
trait kullanmalı.
