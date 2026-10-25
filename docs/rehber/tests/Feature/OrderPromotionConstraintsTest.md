# `tests/Feature/OrderPromotionConstraintsTest.php`

> **Faz:** 13, adım 13.2

Satırlar `DB::table()` ile yazılır, modelle değil: `Order`'ın `#[Fillable]` listesi boş
ve sınanan şey modelin değil **kısıtın** davranışı. Her "imkânsız satır" için
`QueryException` beklenir. Kampanya tarafında yüzdenin 1–90 sınırı, ters tarih penceresi
ve kullanılmış kampanyanın silinememesi (FK `restrict`) sınanır.
