# `tests/Feature/PaymentMethodsTest.php`

> **Faz:** 13, adım 13.1 · **Sınıf:** `PaymentMethods`

| Test | Kanıtladığı |
|---|---|
| `only_the_listed_methods_are_enabled_in_a_fixed_order` | Boşluk/büyük harf önemsiz; sıra `cases()` sırası |
| `the_gift_code_only_setting_turns_the_card_off` | Bugünkü üretim ayarı |
| `an_empty_list_closes_every_method` | Boş liste hata değil, bilinçli kapanış |
| `an_unknown_word_is_a_configuration_error_not_a_silent_skip` | K70 kalıbı: 503, kelime yanıta girmez |
| `ensure_enabled_throws_the_disabled_code_with_the_method` | 403 + `method` parametresi |
| `bank_transfer_needs_a_complete_account_with_a_valid_iban` | IBAN normalize edilir |
| `listed_bank_transfer_stays_closed_without_a_usable_account` | Dört bozuk hesap türünde de havale kapalı |

`RefreshDatabase` yok: sınıf veritabanına dokunmuyor.
