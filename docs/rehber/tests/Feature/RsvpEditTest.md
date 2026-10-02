# `tests/Feature/RsvpEditTest.php`

> **Faz:** 10 — Dilim F, adım 10.59 · **Karar:** **K101** · **10 test**
> **Sınadığı kod:** [`UpdateRsvpAction`](../../app/Actions/Rsvp/UpdateRsvpAction.md) ·
> [`RsvpEditCode`](../../app/Support/RsvpEditCode.md) · `SubmitRsvpAction` (kod üretimi) · `RsvpResource`

---

| Test | İddia |
|---|---|
| `the_first_reply_hands_out_a_code_stored_only_as_a_hash` | Yanıtta 40 karakterlik kod; satırda yalnızca `sha256` özeti, düz kod yok |
| 🔴 `the_same_guest_updates_the_reply_instead_of_adding_a_row` | Aynı kimlik, aynı kod, **tek satır**, alanlar değişti |
| `a_wrong_code_is_indistinguishable_from_a_missing_reply` | Yanlış kod ile var olmayan yanıt **birebir aynı** 404 gövdesi; satır değişmedi (H7) |
| `a_reply_cannot_be_updated_through_another_invitation` | Doğru kod, başka davetiyenin adresi: 404 |
| `a_reply_without_a_code_cannot_be_updated` | Faz 10 öncesi yanıt (`edit_code_hash` boş): hiçbir kod işe yaramaz |
| `an_update_after_the_deadline_is_refused` | Son tarihten sonra 403 `RSVP_DEADLINE_PASSED` |
| 🔴 `the_update_counts_the_guest_once_against_the_quota` | Kota 5: aynı 3 kişiyle güncelleme geçer, 4'e çıkarmak 403 |
| `the_owner_never_sees_the_code` | Sahibin listesinde ne `editCode` anahtarı ne kod ne özet |
| `the_update_requires_the_code` | Kodsuz istek 422, kural `required` |
| `the_update_is_rate_limited_like_a_new_reply` | Rotada `throttle:rsvp` |

Zaman `RsvpTest` ile aynı ana sabitlendi (20 Eylül 2026 12:00 UTC, son tarih 10 Ekim).

## Mutasyon kanıtı (1 Ekim 2026)

İlk koşuda on test de yeşildi; bu kendi başına bir şey kanıtlamaz. Dokuz mutasyon:

| # | Mutasyon | Kırılan |
|---|---|---|
| M1 | Kod kontrolü silindi | yanlış kod · kodsuz yanıt |
| M2 | Kotada eski kişi sayısı da sayılsın | kota testi |
| M3 | Kod düz metin saklansın | özet testi (+ ikisi, kod artık eşleşmediği için) |
| M4 | Yanıt davetiye kapsamı olmadan aransın | başka davetiye testi |
| M5 | Son tarih sorulmasın | son tarih testi |
| M6 | Kaynak `editCode`'u hep göstersin | sahip testi · `RsvpTest`'in liste beyaz listesi |
| M7 | Bot boş kod alsın | `RsvpTest`'in honeypot testi |
| M8 | Güncelleme rotasından hız sınırı kalksın | hız sınırı testi |
| M9 | Güncelleme yeni satır açsın (`replicate()`) | tek satır testi · kota testi |

---

## 🆕 10.64 eklemesi

| Test | İddia |
|---|---|
| `the_update_keeps_the_guests_own_photo` | Misafirin **kendi** yanıtına bağlı fotoğraf güncellemede korunuyor. *"Başka bir yanıta bağlı medya düşer"* kuralı (10.64) kendi yanıtını hariç tutmasaydı, misafir yanıtını her güncellediğinde fotoğrafını kaybederdi |

Dosya artık 11 test.
