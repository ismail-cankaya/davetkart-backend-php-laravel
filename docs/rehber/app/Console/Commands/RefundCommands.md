# `refunds:list` · `refunds:approve` · `refunds:reject`

> **Faz:** 14, adım 14.6 · **Karar:** K141 · **Faz 18.4 (`SEC-02`):** K167 (onay ön denetimi, §2), K168 ("Yayın" sütunu, §4)
> **Dosyalar:** `ListRefundRequests`, `ApproveRefundRequest`, `RejectRefundRequest`, `Concerns/ResolvesRefundRequests`
> **Kullanım kılavuzu:** [`PROJE-KOMUTLARI.md` §3.3](../../../PROJE-KOMUTLARI.md)
> **Test:** `tests/Feature/RefundCommandsTest.php`

## 1. Neden komut, neden panel değil?

Projede yönetim paneli yok; iletişim mesajları, hediye kodları ve kampanyalar da komutla
yönetiliyor. Talep hacmi küçük ve her biri bir **para hareketi**: elle, bilinçli bir adım
olması bir eksik değil, bir emniyet.

## 2. Onayın sorusu

`refunds:approve` önce *"Parayı Shopier panelinden geri gönderdiniz mi?"* diye sorar,
varsayılan cevap **hayır**. `--no-interaction` ile çalıştırılırsa soru varsayılanı alır ve
komut hiçbir şey yapmadan çıkar. Bir betik ya da yanlış yapıştırılmış bir satır, para
gönderilmeden kaydı "iade edildi" yapamaz.

Ret komutu soru sormaz: para hareketi yok, hak kullanıcıya döner.

### 2.1 🆕 Faz 18.4 (K167): sorudan önce, hak kullanılmış mı?

`refunds:approve` artık soruyu sormadan önce talebin hakkına bakar
(`RefundEligibility::usageBlockerFor()`). Hak bir davetiyedeyse, yanmışsa ya da bir davetiyeyi
yayınlamışsa komut **soruyu sormadan** durur:

```
Bu hak kullanılmış (used): talep onaylanamaz. Parayı GÖNDERMEYİN; refunds:reject ile kapatın.
```

- **Neden sorudan önce?** Soru "parayı gönderdiniz mi?" diye sorar. Reddedilecek bir talep için bu
  soruyu sormak, yöneticiyi parayı göndermeye iter.
- **Bu bir ön denetimdir.** Kilit almaz. Kesin karar `ResolveRefundRequestAction`'da, sipariş satırı
  kilitliyken yeniden verilir; ön denetim silinse bile kullanılmış hak onaylanmaz, yalnızca soru
  boşuna sorulmuş olur.
- **Karışmadığı yollar:** talep yoksa, kapalıysa ya da sipariş zaten `refunded` ise ön denetim
  susar; o yolların mesajını Action verir.
- Parantezdeki değer `in_use` ya da `used` (`RefundBlocker`).

## 3. Neden kalıtım değil trait?

İki komut birbirinin özel hâli değil. `RejectRefundRequest extends ApproveRefundRequest`
yazılsaydı onay sorusu ret komutuna da miras kalırdı ya da ezilmesi gerekirdi. Ortak olan
yalnızca "Action'ı çağır, hatayı yaz" adımı; o da `ResolvesRefundRequests`'te.

## 4. Liste

- Varsayılan: açık talepler, **en eski üstte** (iş kuyruğu sırası).
- `Shopier ödeme` sütunu `provider_payment_id`: Shopier paneli ödemeyi bu kimlikle tanır (K121).
- 🆕 **`Yayın` sütunu (Faz 18.4, K168):** hakkın bir davetiyeyi yayınladığı an.

  | Hücre | Anlamı | Onay |
  |---|---|---|
  | `2026-11-10 12:30` | Hak o an bir davetiyeyi yayınladı (`orders.first_published_at`, `davetkart.default_timezone` ile) | ❌ reddedilir (`used` / `in_use`) |
  | `davetiyeye bağlı` | Damga yok ama hak şu an bir davetiyede (ör. yayındaki davetiyeye sonradan ödenen yükseltme) | ❌ reddedilir (`in_use`) |
  | `hiç yayınlanmadı` | Hak hiç kullanılmadı | ✅ |
  | `-` | Sipariş bulunamadı | — |

  **Neden bir sütun?** Denetimde (P49) yönetici plan, tutar ve e-postayı görüyordu; hakkın
  kullanıldığını gösteren hiçbir şey yoktu. Son savunma insan onayıydı ama onaylayan kişi kararı
  verecek bilgiye sahip değildi. Kural kodda da uygulanıyor (yukarıda §2.1); sütun, yöneticinin
  parayı göndermeden **önce** görmesi için.
- Açıklama kullanıcıdan geliyor: kontrol karakterleri görünür kılınır (`contact:list` kuralı).

## 5. 🆕 Faz 18.4: yöneticinin sırası

```powershell
php artisan refunds:list                        # 1) "Yayın" sütununa bak
# Tarih ya da "davetiyeye bağlı" yazıyorsa: para GÖNDERME
php artisan refunds:reject <kimlik> --note="Bu hakla bir davetiye yayınlandığı için iade edilemiyor"

# "hiç yayınlanmadı" yazıyorsa: eskisi gibi
#   2) Shopier panelinden parayı geri gönder
#   3) kaydı kapat
php artisan refunds:approve <kimlik> --note="İadeniz yapıldı"
```

| Sık hata | Sonuç |
|---|---|
| Listeye bakmadan parayı gönderip `approve` demek | Komut durur; para gitmiş, kayıt onaylanamıyor (`ResolveRefundRequestAction.md` §7 "Açık kalan") |
| "Yayın" sütununu yalnızca bilgi sanmak | Tarih olan her satır kodda da reddedilir |
| Sütunu `first_published_at` yerine `invitation_id`'den türetmek | Serbest kalmış hak "hiç yayınlanmadı" görünür: `SEC-02`'nin yönetici tarafı geri gelir |

Dene: `php artisan test --filter=RefundCommandsTest`
