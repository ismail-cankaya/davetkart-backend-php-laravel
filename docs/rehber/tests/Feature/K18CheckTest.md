# `tests/Feature/K18CheckTest.php`

> **Faz:** 19.28 (`ARC-03`, K259) · **Sınanan:** `app/Support/K18Inspector.php`, `app/Console/Commands/CheckK18.php`

| Test | Ne kilitler |
|---|---|
| `a_new_file_with_a_long_block_and_no_guide_fails` | İki ihlal türü birlikte: kılavuz yok + 4 satırlık blok |
| `a_short_commented_file_with_a_guide_passes` | Sınır: 3 içerik satırlı docblock + kılavuz → temiz (`>` / `>=` mutantını öldürür) |
| `type_annotations_in_docblocks_are_not_counted` | `@template`, `@param`, çok satırlı `@return array{…}`, `@phpstan-*` sayılmaz |
| `blank_lines_split_blocks_and_code_ends_them` | Boş satır ve kod bloğu böler; `//`, `#`, `/* */` karışık blok tek sayılır |
| `a_trailing_comment_does_not_start_a_block` | `$x = 1; // a` altındaki 3 satır geçer; 4 satır 3. satırdan başlayan blok olur |
| `a_line_comment_block_under_a_docblock_is_counted_separately` | Docblock ile altındaki `//` bloğu ayrı sayılır (2 + 3 geçer; 2 + 4'te `//` bloğu ihlal) |
| `only_code_roots_are_checked` | `app/`, `database/` evet; `tests/`, `.php` olmayan hayır |
| `the_command_passes_for_a_clean_file_and_fails_for_a_long_block` | Komutun özeti ve çıkış kodu; kirli dosya depoya değil geçici köke yazılır |
| `an_unknown_base_fails_closed` | Bulunamayan taban → çıkış 1 (sığ klon sessizce yeşil olmasın) |

Geçici kök: `sys_get_temp_dir()/k18-<rastgele>`; `tearDown` siler. Komut `base_path()` kullandığı için test,
çağrı süresince uygulamanın kökünü geçici köke çevirir (`$this->app->setBasePath()`), `finally` ile geri alır.
Eskiden kirli dosya depodaki `database/`'e yazılıyordu: süreç öldürülürse kalıp `k18:check`'i kırmızı yapıyordu
(Faz 19 ortak düzeltme). Komut çağrıları `command()` yardımcısıyla `PendingCommand` olarak alınır (PHPStan `method.nonObject`).
