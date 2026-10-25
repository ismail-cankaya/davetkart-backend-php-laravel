# `tests/Feature/PriceCampaignCommandsTest.php`

> **Faz:** 13, adım 13.3

Saat 25 Ekim 2026 10:00 (İstanbul) olarak sabitlenir. Böylece "bugün", "geçmiş" ve
"günün sonu" sınanabilir. Kapsanan durumlar: açma (plansız/planlı, ileri tarihli),
toplanmama uyarısı, sekiz geçersiz girdi (hiçbiri satır yazmaz), durdurmanın idempotent
olması, bilinmeyen kimlik, listedeki dört durum ve yalnızca **ödenmiş** siparişlerin
sayılması, `--active` süzgeci.
