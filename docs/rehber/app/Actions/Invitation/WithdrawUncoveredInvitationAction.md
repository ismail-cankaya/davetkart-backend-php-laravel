# `app/Actions/Invitation/WithdrawUncoveredInvitationAction.php`

> **Faz:** 10 — Dilim F, adım 10.61 · **Karar:** **K100** (iade → yayından kalkar)
> **Çağıran:** `HandlePaymentCallbackAction` (yalnızca sipariş `refunded` olduğunda)
> **Testler:** `PaywallTest` → *Faz 10 — iade*

---

## Ne yapıyor?

Bir sipariş iade edildiğinde, bağlı olduğu davetiyeye bakar:

| Durum | Sonuç |
|---|---|
| Davetiye yayında değil / silinmiş | Hiçbir şey |
| Kalan ödenmiş siparişler davetiyenin **gerektirdiği** planı karşılıyor | Yayında kalır |
| Karşılamıyor (ya da hiç sipariş kalmadı) | `status = saved`, `published_at = null`: misafir bağlantısı 404 |

İsmail'in kararı (K100, 1 Ekim 2026): **para geri verildiyse hizmet de durur.** Önceden iade yalnızca
siparişin durumunu değiştiriyordu; davetiye ödenmeden yayında kalıyordu.

## Kararlar

### Neden *"başka sipariş var mı"* değil, *"gerektirdiği planı karşılıyor mu"*?

Gold + Elit ödenmiş, galeri açık (Elit ister). Elit iade edilirse elde Gold kalır ve galeriyi
**karşılamaz**. *"Başka ödenmiş sipariş var mı?"* diye sorsaydık davetiye Elit özelliğiyle yayında
kalırdı. Soru yayın anındaki soruyla aynı: `PublishEntitlementResolver` + `TierResolver`. Mutasyonla
görüldü: koşul *"herhangi bir sipariş yeter"*e indirilince bu senaryo kırılıyor.

### Neden yalnızca iadede?

Fiyat haritası değişince yayındaki davetiye kendi planının üstünde kalabilir; K88 bunu bilerek
serbest bırakıyor. Böyle bir davetiyenin bir yükseltme siparişi **başarısız** olursa davetiyeye
dokunulmamalı: para geri verilmedi, yalnızca yükseltme olmadı. Kontrol her durum değişiminde
çalışsaydı bu davetiye yayından kalkardı (test: `a_failed_upgrade_does_not_unpublish_the_invitation`).

### Neden `published_at` da siliniyor?

`status` ve `published_at` birlikte değişir: `PublishInvitationAction` ve `InvitationFactory::published()`
de ikisini birlikte yazıyor. Taslak bir davetiyenin yayın anı olmaz. Sahibi yeniden ödeyip
yayınlarsa yeni bir an yazılır (ve 3 günlük serbest bırakma penceresi oradan başlar).

### Önbellek

`save()` → `updated` olayı → `InvitationChanged` → `ClearInvitationCache`: public önbellek commit'ten
sonra düşer. Test public sayfayı iadeden **önce** okumuyor: dinleyici commit sonrası koşuyor ve testin
transaction'ı hiç commit edilmiyor (`PublicInvitationTest.md` §6). Zincirin o halkası orada sınanıyor.

### Neden ayrı bir eylem?

`HandlePaymentCallbackAction` siparişin durumunu işler; davetiyenin yayında kalıp kalmayacağı
başka bir soru (plan hesabı, kilit, olay). Aynı soruyu yarın bir yönetim ekranı (*"elle iade"*) de
soracak. Çağıran zaten bir transaction içinde; bu eylem davetiye satırını kilitliyor.

## İz

Kaldırma `Log::warning('Invitation unpublished after a refund', [invitation_id, order_id])` bırakıyor:
müşteri *"davetiyem kayboldu"* derse ilk bakılacak yer.

## Bilinen sınırlar (B6)

- **Misafire açıklama yok.** Bağlantı 404 veriyor; *"bu davetiye kaldırıldı"* gibi bir sayfa yok.
- **Sahibe bildirim yok.** Panelde davetiye taslak olarak görünüyor. Mail atılmıyor.
- **Kısmi iade** sağlayıcı sözleşmesinde yok (`refunded` tek durum).
