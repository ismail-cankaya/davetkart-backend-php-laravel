# `tests/Feature/AccountDeletionTest.php`

> **Faz:** 10 — Dilim D, adım 10.41 · **7 test**
> **Test edilen:** `DeleteAccountRequest` · `DeleteAccountAction` · `DELETE /api/auth/me` ·
> migration `2026_10_01_100000` (siparişlerin anonimleşmesi)
> **Kurallar:** T13 · T14 · T16

## 1. Testler

| Test | Ne kanıtlar |
|---|---|
| `a_guest_cannot_delete_an_account` | Token yok → 401 |
| 🔴 `a_wrong_password_deletes_nothing` | 422 `current_password` · kullanıcı ve davetiye **yerinde** |
| `the_password_is_required` | 422 `required` |
| 🔴 `personal_data_and_files_go_while_orders_stay_anonymous` | Ana senaryo: 10 tür veri gider, **dosyalar** dahil · iki sipariş kalır, `user_id` ve `invitation_id` `NULL` · eski token 401 |
| `another_users_data_is_untouched` | Mehmet'in satırı, dosyası, siparişi yerinde |
| 🔴 `a_cached_public_invitation_disappears_with_the_account` | Önbellekteki public davetiye silmeden sonra 404 (model olayları ateşlendi) |
| `password_guesses_are_rate_limited` | 6. yanlış parola 429 |

## 2. Ana senaryonun kurulumu

Tek bir kullanıcıya silinebilecek **her şey** veriliyor. Eksik bir tür, eylemin onu
unuttuğunu gizlerdi:

```
2 token (iki cihaz) · yayında bir davetiye + çöp kutusunda bir davetiye
program adımı · galeri fotoğrafı (dosyalı) · misafir fotoğrafı (dosyalı) + LCV
çöp kutusundaki davetiyenin fotoğrafı (dosyalı)
tekil sipariş (ödenmiş) · paket siparişi (ödenmiş) · asistan sayacı · sıfırlama token'ı
```

**Çöp kutusundaki davetiye neden?** Mutasyon M3: eylem `Invitation::query()` kullansaydı
(varsayılan kapsam çöp kutusunu dışarıda bırakır) o davetiyenin satırı FK'yle silinirdi
ama **dosyası** toplanmazdı. Yalnızca bu kurulum onu yakalıyor.

## 3. Dosyalar: `Storage::fake()` + `withFile()`

Fabrika bir medya **satırı** üretir, dosya yazmaz. `withFile()` satırın yoluna sahte bir
dosya koyuyor; test silmeden sonra `assertMissing()` ile dosyanın gittiğini doğruluyor.
Dosya hiç yazılmasaydı `assertMissing()` boş yeşil olurdu.

## 4. Önbellek testi neden ayrı?

Ana senaryo satırları ve dosyaları sayıyor. Davetiyeler model üzerinden değil yalnızca
veritabanı cascade'iyle silinseydi bile satırlar ve dosyalar **yine gider**. Fark
yalnızca **model olaylarında**: `deleted` olayı ateşlenmez, public önbellek temizlenmez.
Bu test önce public sayfayı açarak önbelleği dolduruyor, sonra silme sonrası 404
bekliyor.

## 5. Mutasyon kanıtı (1 Ekim 2026, İsmail'in makinesi, PHP 8.5)

| # | Mutasyon | Kırılan |
|---|---|---|
| M1 | Davetiyeler model üzerinden silinmesin (yalnızca cascade) | **yalnızca** önbellek testi |
| M2 | Dosyalar silinmesin | ana senaryo |
| M3 | Çöp kutusundaki davetiyeler atlansın | ana senaryo |
| M4 | Sıfırlama token'ı kalsın | ana senaryo |
| M5 | Parola onayı yok | yanlış parola testi · hız sınırı testi |
| M6 | Hız sınırı yok | hız sınırı testi |
| M7 | Migration: sipariş kullanıcıyla silinsin (`cascadeOnDelete`) | ana senaryo |
| M8 | Token iptali yok | ana senaryo (Sanctum token'larının FK'si yok, kullanıcı silinince kalırlar) |

M8 ilginç: Sanctum'un `personal_access_tokens` tablosu polimorfik bir ilişki
(`tokenable_type`, `tokenable_id`) kullanıyor ve **yabancı anahtar taşımıyor**.
Kullanıcı silinse bile token satırları kalırdı. Açık iptal bu yüzden şart.
