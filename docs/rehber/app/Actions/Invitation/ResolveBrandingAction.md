# `app/Actions/Invitation/ResolveBrandingAction.php`

> **Faz:** 10 — Dilim F, adım 10.66 · **Karar:** **K102** (P-1, karışık)
> **Çağıran:** `PublicInvitationController::show()` (önbelleğe giren yanıtı kurarken) ·
> 🆕 `InvitationController` (sahibin kaydı, 10.66b)
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

- ~~**Editör önizlemesi** imzayı her zaman gösteriyor: sahibin yanıtında `showBranding` yok. Elit
  sahibi önizlemede imzayı görür, misafir görmez.~~ → 10.66b'de kapandı (§6).
- **Paket (K99):** bağlanmamış bir Elit paketi davetiyeye hak vermez; imza ilk yayında, paket
  bağlandıktan sonra kalkar.

---

## 6. 🆕 Faz 10 (10.66b) — sahibin önizlemesi de aynı kararı alıyor

**Sorun:** karar yalnızca misafir yanıtındaydı. Editör sahibin kaydını (`InvitationResource`) okuyor
ve orada alan yoktu; frontend alan yoksa imzayı çiziyor. Elit sahibi, misafirin görmeyeceği bir
imzayı önizlemesinde görüyordu.

**Çözüm:** sahibin bütün uçları (`index`, `store`, `show`, `update`, `publish`) aynı eylemi çağırıyor;
karar `invitation.showBranding` olarak misafir yanıtıyla **aynı yerde** gidiyor. Frontend'de tek bir
koşul (`InvitationComposition`) iki ekrana da hizmet ediyor.

### `forMany()` neden var?

Sahibin listesi (`GET /invitations`) her davetiye için ayrı sorgu açsaydı N+1 olurdu (CLAUDE.md §4).
`forMany()` kararı bütün liste için **tek sorguda** veriyor; `handle()` de onu tek elemanla çağırıyor,
kural tek yerde kalıyor. Sorgu `PublishEntitlementResolver::highestTiersFor()`'ta
([`../../Contracts/PublishEntitlementResolver.md`](../../Contracts/PublishEntitlementResolver.md), Faz 10 10.66b bölümü).

### Yayında karar neden yayından SONRA soruluyor?

Yayın bağsız bir Elit paketini davetiyeye bağlayabilir (K99). Karar önce sorulsaydı yanıt *"imza var"*
derdi ve editör imzayı bir sonraki kayda kadar göstermeye devam ederdi.

### Testler ve mutasyonlar (2 Ekim 2026)

| Mutasyon | Kırılan |
|---|---|
| Liste her davetiye için `handle()` çağırsın | `InvitationTest::the_list_carries_each_invitations_branding_without_a_query_per_invitation` (`orders`'a 2 sorgu) |
| `update` / `show` kararı geçirmesin | `an_update_returns_the_branding_decision` · `the_owner_sees_the_same_branding_as_the_guests` (Elit) |
| `publish` kararı yayından önce sorsun | `PaywallTest::publishing_with_an_elit_package_returns_the_record_without_branding` |
| Kaynak kararı yok saysın (`true`) | 4 test |

`store` için mutasyon **eşdeğer**: yeni davetiyenin siparişi olamaz, karar her zaman `true`.

**Tarayıcıda görüldü (2 Ekim 2026):** Elit davetiyenin hem misafir sayfasında hem editör önizlemesinde
alt bilgide yalnızca adlar; Gold'da ikisinde de *"DavetKart ile hazırlandı"*.
