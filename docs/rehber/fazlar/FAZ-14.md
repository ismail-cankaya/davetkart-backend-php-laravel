# FAZ 14 — Hesap ayarları: Planlarım, iptal/iade, profil, parola, oturumlar, e-posta

> **Tarih:** 25 Ekim 2026 · **Dal:** `faz-14-account-settings` (backend + frontend)
> **Önkoşul:** Faz 13 · **Plan:** `claude/FAZ-14-PLANI.md`
> **Elle doğrulama:** [FAZ-14-ELLE-DOGRULAMA.md](FAZ-14-ELLE-DOGRULAMA.md)
> **İşletme:** iade komutları [`../PROJE-KOMUTLARI.md`](../PROJE-KOMUTLARI.md) §3.3

---

## 1. Fazın amacı

Hesap ayarları tek bir "adını gör, hesabını sil" sayfasıydı. Standart bir SaaS'ın hesap
ayarlarından projeye uyanlar eklendi. Merkezde İsmail'in isteği: kullanıcı **sahip olduğu
plan haklarının sayısını** görsün ("2 Gold aldı, 1'ini iptal etti → 1").

| Bölüm | Önce | Sonra |
|---|---|---|
| Planlarım | yok | Plan başına kullanılabilir / kullanımda / iptal bekliyor / ödeme bekliyor / kullanıldı / iade edildi |
| İptal (iade talebi) | yok | Kullanılmamış, ücretli hak 14 gün içinde iptal edilir; yönetici komutla karar verir |
| Siparişlerim | uç vardı, ekran yoktu | Ekran |
| Profil | salt okunur | Ad/soyad düzenlenir |
| Parola | "şifremi unuttum"a yönlendirme | Mevcut parolayla değiştirme |
| Oturumlar | yok | Liste, tek tek kapatma, "diğerlerinden çık" |
| E-posta | değiştirilemiyordu | Doğrulamalı, sızdırmayan değişiklik |
| Hesabı sil | genel uyarı | Kullanılmamış haklar sayıyla |

**Kapsam dışı (İsmail'in kararı / ertelendi):** verilerimi indir, bildirim tercihleri, 2FA,
sosyal giriş, fatura.

## 2. Merkezi fikir: "hak zaten siparişte"

Yeni bir kredi tablosu ya da sayaç kolonu **yok**. Her ödenmiş sipariş bir haktır (K99) ve
nerede durduğu siparişin kendi alanlarından türetilir. Sayaç bir **okuma modeli**
(`SummarizeEntitlementsAction`). İkinci bir sayaç beş ayrı yerde güncellenmek zorunda
kalırdı; birini unutmak sayıyı sessizce bozardı.

Bu yaklaşım bir açığı da ortaya çıkardı (14.1): yanmış hak, davetiyesi 30 gün sonra kalıcı
silinince serbest bir hakla aynı görünüyordu ve bedava bir yayın açıyordu.

## 3. Yazılan dosyalar

### Backend (14.1–14.11)

| Adım | İçerik |
|---|---|
| 14.1 | 🔴 Açık kapandı: `orders.consumed_at` migration'ı + geriye dönük doldurma · `Order::unconsumed()/isConsumed()` · `DeleteInvitationAction` damgalar · `ClaimReleasedOrderAction` yanmışı bağlamaz · `OrderConsumptionTest` |
| 14.2 | `refund_requests` migration'ı (kısmi UNIQUE: siparişte tek açık talep) · `RefundRequestStatus` · `RefundRequest` + fabrika · `Order` ilişkileri · `RefundRequestConstraintsTest`, `RefundRequestStatusTest` |
| 14.3 | `RefundBlocker` · `RefundEligibility` · `EntitlementState` · `SummarizeEntitlementsAction` + `EntitlementSummary`/`TierEntitlements`/`EntitlementRight` · `davetkart.refunds.window_days` · `SummarizeEntitlementsActionTest` |
| 14.4 | `GET /api/account/entitlements` · `EntitlementSummaryResource` · `AccountController` · `AccountEntitlementsTest` |
| 14.5 | `POST/DELETE /api/orders/{order}/refund-request` · `RequestRefundAction` · `WithdrawRefundRequestAction` · `REFUND_NOT_ALLOWED` (409) · donmuş hak bağlanmaz · `RefundRequestTest` |
| 14.6 | `refunds:list/approve/reject` · `ResolveRefundRequestAction` · `RefundRequestResolvedNotification` · `PROJE-KOMUTLARI.md` §3.3 · `RefundCommandsTest` |
| 14.7 | `PATCH /api/auth/me` · `UpdateProfileAction` · `ProfileUpdateTest` |
| 14.8 | `PUT /api/auth/password` · `ChangePasswordAction` · `PasswordChangedNotification` · `PasswordChangeTest` |
| 14.9 | `personal_access_tokens.user_agent` · `IssueApiTokenAction` (giriş + kayıt) · `GET/DELETE /api/auth/sessions[/{id}]` · `DeviceLabel` · `SessionTest`, `DeviceLabelTest` |
| 14.10 | `POST /api/auth/email-change` + `POST /api/public/email-change/confirm` · `EmailChangeLink` (tablosuz, imzalı, tek kullanımlık) · iki mail · `EMAIL_CHANGE_INVALID` (422) · `EmailChangeTest` |
| 14.11 | Bu dosya · elle doğrulama · `fazlar/README.md` |

### Frontend (14.12–14.14)

| Adım | İçerik |
|---|---|
| 14.12 | Veri katmanı: tipler · `services/account.ts` · `stores/useAccountStore.ts` · `useAuthStore.setUser` · `signOut` temizliği · hata kataloğu + `REFUND_NOT_ALLOWED`/`EMAIL_CHANGE_INVALID` (tr/en) · `verify-endpoints` (10 uç) |
| 14.13 | Bileşenler (`components/account/*`): kart, menü, form alanı, plan kartları, hak satırı (iptal / geri çek), sipariş listesi, profil / parola / e-posta formları, oturum listesi, hesabı sil · `accountLabels` · `useAccountEntitlements` |
| 14.14 | Sayfalar: hesap kabuğu + 5 bölüm (alt yollar) · `/eposta-onay` · panel rozeti · fiyat SSS'si · rehber md'leri |

## 4. Kararlar (K137–K145)

| # | Karar |
|---|---|
| K137 | Yanmış hak bir **damgadır** (`orders.consumed_at`), bağ değil. Bağ (`invitation_id`) purge'te kopar; bilgi kopabilecek bir bağın içinde saklanmaz |
| K138 | İptal = iade **talebi**, ayrı tablo (`refund_requests`), sipariş durumu değil. Açık talep hakkı **dondurur** (yayında bağlanmaz); siparişte en fazla bir açık talep (kısmi UNIQUE) |
| K139 | İptal edilebilir hak: `paid` + bağsız + yanmamış + ücretli + ödemeden itibaren 14 gün (`davetkart.refunds.window_days`, env yok). Kural tek sınıfta (`RefundEligibility`); ekran ve sunucu aynısını sorar. Engel 409 `REFUND_NOT_ALLOWED` + `reason` |
| K140 | Planlarım bir okuma modeli: yeni tablo/kolon yok, sabit sayıda sorgu, kova kuralı enum'larda. Üç plan her zaman döner |
| K141 | Para kodda gönderilmez: yönetici Shopier panelinden iade eder, sonra `refunds:approve` (önce sorar, varsayılan "hayır"). Mail commit'ten sonra |
| K142 | Oturum açıkken parola değişince **bu cihaz hariç** bütün oturumlar kapanır; bekleyen sıfırlama silinir; "siz değilseniz" maili |
| K143 | Oturum = Sanctum token'ı. Cihaz için yalnızca User-Agent; **IP saklanmaz** (veri minimizasyonu). Ham UA dışarı çıkmaz, `browser`/`system` çıkar. Token tek üreticiden (`IssueApiTokenAction`) |
| K144 | E-posta değişikliği **sızdırmaz**: istek her zaman 202 ve veritabanına bakmaz; çakışma onayda UNIQUE kısıtında, genel `EMAIL_CHANGE_INVALID` ile. Bağlantı tablosuz, göreli imzalı, eski adresin HMAC'iyle tek kullanımlık. Onayda bütün oturumlar kapanır, eski adrese maskeli uyarı |
| K145 | Frontend hak saymaz: yazma sonrası listeyi sunucudan yeniden okur (K128'in haklar için hali) |

**Plandan sapmalar:**

- **14.1 (yanmış hak açığı) planda yoktu.** Sayaç yazılırken bulundu; sayaçtan önce kapatıldı.
- **IP yok.** Planda "IP + User-Agent" vardı; CLAUDE.md §3 ham IP'yi yasaklıyor, hash'lenmiş
  IP kullanıcıya bir şey söylemiyor.
- **Sayaç "tek SQL CASE" değil, 3 sabit sorgu + PHP.** Kural enum'larda kalsın diye (K50).
- **E-posta isteği ve onayı tek adım (14.10):** bağlantı onay rotasının adını kullanıyor;
  ayrı commit'lerde ilk commit derlenmezdi.
- **Verilerimi indir çıkarıldı** (İsmail'in kararı).

## 5. Kurulan kurallar

1. **Bir bilgi, kopabilecek bir bağın içinde saklanmaz** (K137).
2. **Serbest hak arayan her sorgu üç şey sorar:** bağsız mı, yanmamış mı, açık iade talebi yok mu.
3. **İptal kuralı tek sınıfta** (`RefundEligibility`); ekran düğmeyi ona göre gösterir, karar yine sunucuda.
4. **Para hareketi kodda değilse, kayıt "gönderildi" demeden önce sorar** (`refunds:approve`).
5. **Hesabın kimliği (e-posta, parola) değişince eski oturumlar kapanır.**
6. **Adresin varlığını söyleyen hiçbir yanıt yok** — kayıtta, sıfırlamada, e-posta değişikliğinde.

## 6. Sayılar

- Backend: `pint` ✅ · `phpstan` 0 hata ✅ · `errors:export --check` ✅ · **958 test** ✅
  (865 → 958). Bulut çalışma alanında PHP 8.5.9 + PostgreSQL 16 ile koştu, bu makinede **değil**.
  Her commit ayrı ayrı sınandı.
- Frontend: `npm run check` ✅ (tsc + build + 11 doğrulama betiği; `verify-endpoints`'e 10 uç eklendi).
- Ekran görüntüleriyle bakıldı (masaüstü + mobil): Genel bakış, Planlarım, iptal onayı,
  Siparişlerim, Güvenlik, `/eposta-onay` (başarılı ve tekrar kullanılan bağlantı).

## 7. Devir

- 🔴 **Elle doğrulama bekliyor:** [FAZ-14-ELLE-DOGRULAMA.md](FAZ-14-ELLE-DOGRULAMA.md).
- 🔴 **Canlıda mail gönderici şart.** Parola değişikliği uyarısı, e-posta onayı ve iade sonucu
  maille gidiyor; `MAIL_MAILER=log` ile e-posta değişikliği **tamamlanamaz**.
- **14 günlük iptal süresi** ticari/hukuki bir söz (cayma hakkı, dijital içerik istisnası).
  Kullanım Şartları'ndaki "Cayma Hakkı ve İade Koşulları" metniyle karşılaştırılmalı; sayı
  `config/davetkart.php` → `refunds.window_days`.
- **Shopier iade API'si yok:** iade elle; otomatikleşirse yalnızca `ResolveRefundRequestAction` değişir.
- **Eski yanmış haklar:** çöp kutusu daha önce boşaltıldıysa o siparişler ayırt edilemez
  (yayın öncesi olduğu için pratikte boş küme).
