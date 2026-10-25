# E-posta değişikliği: istek + imzalı onay

> **Faz:** 14, adım 14.10 · **Karar:** K144
> **Uçlar:** `POST /api/auth/email-change` (202) · `POST /api/public/email-change/confirm?…` (204)
> **Dosyalar:** `RequestEmailChangeRequest`, `RequestEmailChangeAction`, `EmailChangeController`,
> `ConfirmEmailChangeRequest`, `ConfirmEmailChangeAction`, `PublicEmailChangeController`,
> `App\Support\EmailChangeLink`, `EmailChangeConfirmationNotification`, `EmailChangedNotification`,
> `EmailChangeFailedException`, `ErrorCode::EmailChangeInvalid`
> **Test:** `tests/Feature/EmailChangeTest.php`

---

## 1. Akış

```
Hesap ayarları  ── POST /auth/email-change {email, currentPassword} ──► 202
                                   │
                                   └─► YENİ adrese mail: FRONTEND/eposta-onay?user&email&old&expires&signature

Mail bağlantısı ── /eposta-onay sayfası ── POST /public/email-change/confirm?<aynı sorgu> ──► 204
                                   │
                                   ├─ adres değişir, email_verified_at = now
                                   ├─ BÜTÜN oturumlar kapanır → yeni adresle giriş
                                   └─► ESKİ adrese mail: "adresiniz y***@gmail.com olarak değişti"
```

## 2. 🔴 Güvenli yol: adres kullanımdaysa ne olur? (İsmail'in kararı)

| Seçenek | Sonuç |
|---|---|
| İstekte "bu adres kullanımda" (422) | Kullanıcı dostu, ama oturum açmış herkes `x@y.com` kayıtlı mı diye sorabilir: **hesap tarayıcı** |
| **Her zaman 202 + mail; çakışma onayda, genel hatayla** | Hiçbir şey sızmaz ✅ |

İstek ucu veritabanına **hiç bakmıyor** (`RequestEmailChangeAction`). Bakmış olsaydı, cevap
aynı olsa bile yanıt süresi farkı adresin varlığını sızdırabilirdi (LoginUserAction'ın
zamanlama savunmasıyla aynı aile). Çakışmayı `users.email` UNIQUE kısıtı onay anında
yakalıyor ve `EMAIL_CHANGE_INVALID` döner — "süresi dolmuş" ile aynı kod (H6).

Bedeli: kullanıcı kayıtlı bir adrese geçmeye çalışırsa onay sayfasında "bağlantı geçersiz"
görür, sebebini bilmez. Güvenlik lehine bilinçli bir takas.

## 3. Bağlantı: tablosuz, imzalı, tek kullanımlık (`EmailChangeLink`)

Bekleyen değişikliği saklayan bir tablo yok. Bağlantı her şeyi taşıyor:

| Parametre | Ne işe yarar |
|---|---|
| `user` | Kimin adresi |
| `email` | Yeni adres |
| `old` | ESKİ adresin HMAC'i — tek kullanımlık yapan parça |
| `expires` | 60 dk (`davetkart.auth.email_change_expire_minutes`) |
| `signature` | Laravel'in imzası (APP_KEY ile HMAC): yukarıdakilerin hiçbiri değiştirilemez |

**Neden tek kullanımlık?** Onaydan sonra adres değişti; `old` artık güncel adresin HMAC'iyle
tutmuyor. Aynı bağlantı ikinci kez çalışmaz — bir tablo satırını "kullanıldı" diye
işaretlemeye gerek kalmadan.

**Neden düz hash değil HMAC?** `sha256(eski@adres)` bağlantıda dursaydı, yaygın adreslerden
bir sözlükle eski adres geri bulunabilirdi.

**Neden göreli imza (`absolute: false`)?** API'nin alan adı (`APP_URL`) ile isteğin geldiği
adres proxy/CDN arkasında farklı olabilir. Yol + sorgu imzalanıyor.

Bağlantının hedefi **frontend** sayfası (`/eposta-onay`), API değil. Sayfa sorgu kısmını API'ye
**aynen** POST eder. Adres config'ten kurulur, isteğin Host başlığından değil (reset poisoning).

## 4. Onay ucu neden `public`?

Kullanıcı maili telefonda, oturum açmadığı bir cihazda açabilir. Yetkinin kanıtı oturum değil
**imza**. K12 gereği kimliksiz uç `/api/public/` altında.

`ConfirmEmailChangeRequest`:
- Kurallar **sorguya** uygulanır (`validationData()`): imza sorguyu kapsıyor, gövdeyi değil.
- Her hata — eksik parametre dahil — `EMAIL_CHANGE_INVALID`. Bağlantıyı kurcalayan biri hangi
  parçanın yanlış olduğunu öğrenmesin.
- İmza doğrulaması `passedValidation()`'da. Laravel'in `signed` middleware'i 403 dönerdi;
  sözleşmede bunun karşılığı "parola sıfırlama bağlantısı geçersiz" ailesi (422).

## 5. Onayın yan etkileri (`ConfirmEmailChangeAction`)

1. Kullanıcı satırı **kilitlenir** (iki sekmeden iki onay sırayla).
2. Bekleyen parola sıfırlama silinir: tablo e-postayla anahtarlı, adres değişmeden silinmeli.
3. `email_verified_at = now()`: kullanıcı yeni adrese erişimini kanıtladı.
4. **Bütün token'lar silinir**: hesabın kimliği değişti. Parola sıfırlamayla aynı kural.
5. Commit'ten sonra eski adrese maskeli uyarı.

## 6. Sık hatalar

| Hata | Sonuç |
|---|---|
| İstekte `unique:users` kuralı | Hesap tarayıcısı |
| Bağlantıyı `request()->getHost()`'tan kurmak | Saldırganın sitesine giden onay maili |
| `old` parmak izini koymamak | Bağlantı 60 dk boyunca tekrar tekrar kullanılabilir |
| Onayda token'ları silmemek | Eski adresle açılmış oturumlar açık kalır |
