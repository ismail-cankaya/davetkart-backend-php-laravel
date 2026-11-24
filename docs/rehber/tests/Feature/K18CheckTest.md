# `tests/Feature/K18CheckTest.php`

> **Faz:** 19.28 (`ARC-03`, K259) · **Sınanan:** `app/Support/K18Inspector.php`, `app/Console/Commands/CheckK18.php`

| Test | Ne kilitler |
|---|---|
| `a_new_file_with_a_long_block_and_no_guide_fails` | İki ihlal türü birlikte: kılavuz yok + 4 satırlık blok |
| `a_short_commented_file_with_a_guide_passes` | Sınır: 3 içerik satırlı docblock + kılavuz → temiz (`>` / `>=` mutantını öldürür) |
| `type_annotations_in_docblocks_are_not_counted` | `@template`, `@param`, çok satırlı `@return array{…}`, `@phpstan-*` sayılmaz |
| `blank_lines_split_blocks_and_code_ends_them` | Boş satır ve kod bloğu böler; `//`, `#`, `/* */` karışık blok tek sayılır |
| `only_code_roots_are_checked` | `app/`, `database/` evet; `tests/`, `.php` olmayan hayır |
| `the_command_passes_for_a_clean_file_and_fails_for_a_long_block` | Komutun özeti ve çıkış kodu; geçici dosya `database/`'e yazılır, `finally`'de silinir |
| `an_unknown_base_fails_closed` | Bulunamayan taban → çıkış 1 (sığ klon sessizce yeşil olmasın) |

Geçici kök: `sys_get_temp_dir()/k18-<rastgele>`; `tearDown` siler. Komut testi `base_path()` gerektirdiği için
tek geçici dosya depo içinde açılır; adı rastgele, `finally` ile silinir.
