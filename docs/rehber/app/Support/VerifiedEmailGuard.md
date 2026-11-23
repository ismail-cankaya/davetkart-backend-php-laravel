# `app/Support/VerifiedEmailGuard.php`

> **Faz 19 (19.14 · K191, SEC-09)** · Kardeş: [`MediaDiskGuard.md`](MediaDiskGuard.md)

## Ne yapar

`assertVerified(User)`: `email_verified_at` boşsa `EmailNotVerifiedException` → **403 `EMAIL_NOT_VERIFIED`**.
Kapının **tek** yeri. Çağıranlar:

| Çağıran | Neden |
|---|---|
| `StartCheckoutAction::handle()` ilk satır | ödeme kimliğe bağlı ayrıcalık (indirim, iade postası) |
| `PublishInvitationAction::handle()` ilk satır (davetiyenin sahibi) | yayın, ödemeyle aynı eşik |

Bilerek **kapsanmayanlar** (§A-1 varsayılanı): giriş, taslak oluşturma/düzenleme, önizleme, fiyat görme, hesap ayarları.

## Neden ara katman değil

Ara katman yalnızca HTTP yolunu korurdu. Action başındaki guard konsol komutu, kuyruk işi ya da yeni bir uç gibi her
çağırana uygulanır (`StoreGuestMediaAction`'daki tür izniyle aynı gerekçe). Mutant: kapıyı `StartCheckoutAction`'dan
kaldırmak `an_unverified_account_cannot_start_a_checkout`'u kırmızı yapar.

## Kim doğrulanmış sayılır

- Kayıt sonrası bağlantıyı onaylayan (`ConfirmEmailVerificationAction`).
- E-posta adresini değiştiren (`ConfirmEmailChangeAction` `email_verified_at = now()` yazar; yeni adres o bağlantıyla kanıtlandı).
- Faz 19 öncesi hesaplar göçle **doğrulanmadı** (§A-4: canlıda müşteri yok). Yerel deneme hesabı için:

```powershell
php artisan tinker --execute="App\Models\User::where('email','ben@ornek.test')->update(['email_verified_at'=>now()]);"
```
