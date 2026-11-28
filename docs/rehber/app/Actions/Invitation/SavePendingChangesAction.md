# `app/Actions/Invitation/SavePendingChangesAction.php`

Faz 27.3 (K323). `PUT /invitations/{id}/pending`.

| Davetiye | Davranış |
|---|---|
| Taslak (yayında değil) | Misafir yok: `UpdateInvitationAction` ile doğrudan yazar. Stüdyo her durumda aynı ucu çağırabilir |
| Yayında | Satır kilitlenir; mevcut taslak + gelen alanlar birleşir; canlıya eşit olanlar atılır; program canlıyla aynıysa atılır |

- Fark ölçümü: gelen değerler modelin bir kopyasına `fill()` edilir ve cast'li değerler karşılaştırılır (tarih ISO, dizi
  anahtar sıralı: `jsonb` anahtar sırasını korumaz). Böylece "aynı değeri geri yazdım" taslakta iz bırakmaz.
- Yazım `toBase()->update()`: model olayı yok (`InvitationChanged` → misafir önbelleği temizlenmez), `updated_at`
  değişmez (misafirin gördüğü hiçbir şey değişmedi).
- 🔴 Paket kontrolü **yok**: taslak denemek serbest (K43). Kapı yayınlamada.
