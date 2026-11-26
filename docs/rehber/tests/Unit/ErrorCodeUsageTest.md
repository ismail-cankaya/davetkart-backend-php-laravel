# `tests/Unit/ErrorCodeUsageTest.php`

> Faz 21.6 · `ARC-06` · K270.

Katalogdaki (`app/Enums/ErrorCode.php`) her kod için `app/` içinde (katalog dosyası hariç) `ErrorCode::<Case>` metni aranır.
Bulunmayan kod, sözleşmede verilip tutulmayan bir sözdür (B4): frontend onu çevirir ve hiç gelmeyecek bir durumu ele
almaya çalışır. Faz 21.6'ya kadar üç kod böyleydi (`TOKEN_EXPIRED`, `INVITATION_LOCKED`, `SLUG_TAKEN`).

| Test | Korunan | Öldürdüğü mutant |
|---|---|---|
| `every_error_code_is_used_somewhere_in_app` | Kullanılmayan kod yok | kataloğa kullanılmayan bir kod eklemek |
| `the_removed_codes_stay_removed` | Üç kod yok, katalog 28 | silinen kodu geri eklemek |

Neden metin araması, `grep` değil? Test PHPUnit'in içinde koşar, Windows'ta ve CI'da aynı sonucu verir; `Unit` takımında
Laravel uygulaması kurulmaz (hızlı). Sınır (B6): kod `ErrorCode::from('...')` ile dinamik üretilseydi test onu görmezdi;
bugün böyle bir kullanım yok. Yeni bir kod gerekiyorsa önce onu fırlatan exception (`HasErrorCode`) yazılır, sonra
`28` sayısı güncellenir.
