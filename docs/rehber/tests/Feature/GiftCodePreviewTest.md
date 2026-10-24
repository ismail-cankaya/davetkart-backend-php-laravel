# `tests/Feature/GiftCodePreviewTest.php` — önizleme ve hız sınırı

> **Faz:** 11, adım 11.8 · 12 test

---

| Grup | Test |
|---|---|
| Yanıt | yüzde (tam JSON, `assertExactJson`) · fiyatı aşan sabit → toplam 0 · `free` → toplam 0 |
| Yan etkisizlik | iki kişi max 1 kodu önizler → ikisi de 200, hiç sipariş ve kullanım yok |
| Sızıntı | ham gövdede kodun kimliği, özeti, ipucu, etiketi, `max`, `remaining`, `expires` yok |
| Kurallar | bilinmeyen = kapalı (**aynı gövde**), plan uyuşmazlığı, kişi başı |
| Doğrulama | bilinmeyen plan, eksik kod → `VALIDATION_FAILED` · misafir → 401 |
| Hız sınırı | kullanıcı başına dakika kovası (başkası etkilenmez) · saat kovası dakika sıfırlansa da tutar · önizleme ve kodlu checkout **aynı kova** · kodsuz checkout kovadan etkilenmez |

Hız sınırı testleri değerleri `Config::set` ile küçültüyor (3, 2, 1). Gerçek değerlerle
(10/30) test yazmak her testte 30 istek atmak demekti.

`assertExactJson` bilerek kullanıldı: yanıta bir alan eklenirse (örneğin kalan kullanım
sayısı) test kırılır ve ekleme bilinçli bir karar olur.

Her istekten sonra `forgetAuthState()` çağrılıyor: Laravel'in guard'ı çözdüğü kullanıcıyı
önbellekte tutuyor ve ikinci istek token'a hiç bakmıyor (T13, `tests/TestCase.md`).
