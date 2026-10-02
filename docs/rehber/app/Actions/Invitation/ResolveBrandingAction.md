# `app/Actions/Invitation/ResolveBrandingAction.php`

> **Faz:** 10 — Dilim F, adım 10.66 · **Karar:** **K102** (P-1, karışık)
> **Çağıran:** `PublicInvitationController::show()` (önbelleğe giren yanıtı kurarken)
> **Testler:** `PublicInvitationTest::the_branding_follows_the_paid_plan` · `PaywallTest::a_paid_or_refunded_order_refreshes_its_invitations_public_page`

---

## 1. Çözdüğü sorun (P-1'in birinci maddesi)

Fiyat kartı Elit'e **"Logosuz özel yayın"** vaat ediyordu, Standart ve Gold'a etmiyordu. Ama misafir
sayfasının alt bilgisi (*"DavetKart ile hazırlandı"*) **koşulsuz** çiziliyordu
(`InvitationComposition.tsx`). Public yanıtta planı söyleyen bir alan olmadığı için frontend kimin
Elit olduğunu bilemiyordu.

İsmail'in kararı (K102, *karışık* seçenek): logo ve tema vaadi kodla karşılanır, video galeri
karttan çıkar.

## 2. Ne yapıyor?

```php
$owned = $this->entitlements->highestTierFor($invitation);
return $owned === null || ! $owned->covers($whiteLabel);   // true = imzayı göster
```

`$whiteLabel` config'ten: `davetkart.branding.white_label_tier` = `elit` (E6: hangi planın
imzasız olduğu bir satış kararı). Public yanıta `showBranding` olarak giriyor ve **her zaman**
geliyor (C7): eksik olsaydı frontend onu *"göster"* diye yorumlardı.

## 3. 🔴 Önbellek

Public yanıt 6 saat önbellekte duruyor (`public_invitation_ttl`) ve önbellek davetiye
**değiştiğinde** düşüyor (`InvitationChanged`). Ama imza davetiyeye değil **siparişe** bağlı:
yayındaki bir Gold davetiye Elit'e yükseltildiğinde davetiye satırı değişmez. Önlem alınmasaydı
misafirler imzayı 6 saat boyunca görmeye devam ederdi.

`HandlePaymentCallbackAction` sipariş **ödendiğinde ya da iade edildiğinde** bağlı davetiye için
`InvitationChanged` yayınlıyor; dinleyici commit'ten sonra önbelleği siliyor. Başarısız ya da süresi
dolan bir sipariş hakkı değiştirmediği için olay yok (test her iki yönü sınıyor).

## 4. Neden ayrı bir eylem, neden kaynakta değil?

Karar bir iş kuralı (*"Elit imzasızdır"*) ve bir sözleşme (`PublishEntitlementResolver`) okuyor.
Resource yalnızca alan adlarını çeviriyor (CLAUDE.md §1); kararı `withBranding()` ile dışarıdan
alıyor. Controller iki eylemi sırayla çağırıyor: çözümle, imzayı sor.

## 5. Bilinen sınırlar (B6)

- **Editör önizlemesi** imzayı her zaman gösteriyor: sahibin yanıtında `showBranding` yok. Elit
  sahibi önizlemede imzayı görür, misafir görmez.
- **Paket (K99):** bağlanmamış bir Elit paketi davetiyeye hak vermez; imza ilk yayında, paket
  bağlandıktan sonra kalkar.
