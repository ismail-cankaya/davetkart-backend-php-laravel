# `app/Actions/Rsvp/ResolveGuestMediaAction.php`

> **İlgili:** [`SubmitRsvpAction.md`](SubmitRsvpAction.md) · [`UpdateRsvpAction.md`](UpdateRsvpAction.md) · K190 (SEC-08)

## Faz 19 ortak düzeltme (A-E4) — iliştirmede bütçe

K190 misafir bütçesini **yükleme anında** sınıyordu: iliştirilmiş baytlar + gelen dosya ≤ 1 GB, bekleyenler ayrı 50 dosya / 200 MB.
Bekleyen bir dosya sonradan bir yanıta iliştirilince yeniden sınanmıyordu; bütçe en çok 200 MB aşılabiliyordu (bilinen sınır).

Artık `fitsAttachedBudget` iliştirme anında aynı iki sınırı yeniden sorar: aynı türden iliştirilmiş adet < `maxPerInvitation` ve
iliştirilmiş baytlar + bu dosya ≤ `attached_bytes_per_invitation`. Aşarsa kimlik **sessizce null** olur (bu sınıfın geçersiz kimlik
kuralıyla aynı: misafire sınır söylenmez, H9); yanıtın kendisi kaydedilir — misafirin katılım bilgisi bir fotoğraf yüzünden kaybolmaz.

- Güncellemede yanıtın aynı türdeki eski dosyası (`getOriginal`) sayımdan çıkar: yerini yenisine bırakıyor. Aynı dosya yeniden gönderilirse
  zaten sayılıyordur, kontrol atlanır.
- **Kilit:** `SubmitRsvpAction` ve `UpdateRsvpAction` medyayı artık transaction içinde, davetiye satırı `lockForUpdate` ile kilitliyken
  çözer. Yükleme ucu (`StoreUploadedMediaAction`) aynı satırı kilitler; iki eşzamanlı iliştirme aynı boş yeri göremez.
  `EnsureRsvpQuotaAction`'ın kilidi sınırsız planda hiç alınmadığı için ona güvenilmedi.
- Testler: `MediaTest::a_pending_upload_is_not_attached_past_the_attached_budget`, `…_replacing_an_attached_photo_does_not_count_it_twice`.
## K18 ile koddan taşınan açıklama (Faz 19 ortak düzeltme, A-E4) — `ResolveGuestMediaAction.php` satır 13

```text
Misafirin LCV'sine iliştirdiği medya kimliğini doğrular; geçersizse null döner.

Faz 10 (10.59): SubmitRsvpAction'ın içinden çıkarıldı; güncelleme
(UpdateRsvpAction) aynı kuralı kullanıyor ve kural iki yerde duramaz (C3).

Uc kosul birden aranir ve ucu de sorgunun KAPSAMINDA (P3 ailesi):
  1. Medya BU davetiyeye ait mi   -> $invitation->media() iliskisi
  2. Beklenen TURDE mi            -> where('kind', ...)
  3. Baska bir yanita bagli DEGIL mi (Faz 10, 10.64) -> whereNotExists(rsvps)

🔴 Ikincisi olmasaydi misafir kendi yukledigi rsvp_video kimligini
photoMediaId olarak gonderebilir, ya da (davetiyeye ait oldugu icin)
SAHIBIN GALERI fotografini kendi yanitina ilistirebilirdi.

🔴 Gecersiz kimlik EXCEPTION FIRLATMAZ, sessizce null olur. Gerekce
bir kolaylik degil bir savunma: 403/422 donmek, saldirgana "bu kimlik
gecerliydi ama senin degil" ile "bu kimlik hic yok" arasindaki farki
ogretirdi — yani media tablosu ULID uzayindan taranabilir hale gelirdi
(docs/08 §3.2'nin ayni gerekcesi). Misafir kendi gonderdigi kimligi
zaten biliyor; yanitta fotografin gorunmemesi ona yeterli sinyal.

Dogrulama kurali olarak yazilamazdi: FormRequest davetiyeyi henuz
cozmemistir, dolayisiyla "hangi davetiye" sorusunun cevabi orada YOK.
Ayrintili aciklama: docs/rehber/app/Actions/Rsvp/SubmitRsvpAction.md
```
