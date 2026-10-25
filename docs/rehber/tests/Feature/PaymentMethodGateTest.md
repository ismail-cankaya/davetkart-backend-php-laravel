# `tests/Feature/PaymentMethodGateTest.php`

> **Faz:** 13, adım 13.5 · **Karar:** K134, K136

Frontend kapalı yöntemin düğmesini gizler. Bu testler backend'in de **reddettiğini** kanıtlar.

- **Kart kapalı:** kodsuz checkout 403 ve satır yazılmaz. Kısmi kod 403 alır ve kodun hakkı
  yanmaz. %100 kod çalışır, sağlayıcı yapılandırılmamış olsa bile (K136).
- **Kart açık + sağlayıcı eksik:** hâlâ gürültülü bir 503.
- **Kod kapalı:** kodlu checkout ve önizleme 403. Kodsuz kart checkout'u çalışır.
- **Yanlış yazılmış ayar:** 503, kelime yanıta sızmaz.
