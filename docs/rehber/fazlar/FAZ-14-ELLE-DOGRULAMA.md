# FAZ 14 — Elle Doğrulama Betiği

> **Tarih:** 25 Ekim 2026 · **Adım sayısı:** 15 (+ Adım 0)
> **Ortam:** Yerel (Herd + Vite) · gerçek ödeme YOK · mail `log`
> Her adımın sonucunu yaz. Gözlenmeyen bir şeye ✅ konmaz (B7).

---

## Adım 0 — Kapı

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
git switch faz-14-account-settings
php artisan migrate        # 3 migration: consumed_at, refund_requests, user_agent
composer check             # beklenen: 958 test
cd ..\davetkart-frontent
git switch faz-14-account-settings
npm run check
```

Mail yerelde `storage/logs/laravel.log`'a düşer (`MAIL_MAILER=log`). Kuyruk `database` ise
ayrı bir pencerede `php artisan queue:work` çalıştır.

Deneme verisi (tinker):

```php
$u = App\Models\User::where('email', 'SENIN@ADRESIN')->first();
App\Models\Order::factory()->paid()->tier(App\Enums\SubscriptionTier::Gold)->package()->count(2)->create(['user_id' => $u->id]);
```

| # | Adım | Beklenen | Gözlenen |
|---|---|---|---|
| 1 | Panel (`/dashboard`) | Başlıkta yeşil **"2 kullanılabilir hak"** rozeti | |
| 2 | Rozete tıkla | `/hesap/planlarim`; Gold kartında büyük **2** | |
| 3 | Bir Gold satırında **İptal Et** → açıklama yaz → Devam Et → onayla | Toast "talebiniz alındı"; Gold **1**, "İptal bekliyor 1"; satırda "Talebi Geri Çek" | |
| 4 | `php artisan refunds:list` | Talep satırı, açıklama, "Shopier ödeme" sütunu | |
| 5 | `php artisan refunds:approve <kimlik>` → **hayır** | "Vazgeçildi", hiçbir şey değişmez | |
| 6 | Aynı komut → **evet** | Talep `approved`; ekranı yenile: Gold **1**, "İade edildi 1" — 🔴 İsmail'in örneği | |
| 7 | Kalan Gold'u iptal et, sonra **Talebi Geri Çek** | Gold yeniden **1** kullanılabilir | |
| 8 | Bir davetiye yayınla (Gold yeterli) → Planlarım | Gold 0, satır "Kullanımda" + davetiye adı; İptal Et **yok** | |
| 9 | `/hesap` → adı değiştir → Kaydet | Toast; sağ üstteki ad hemen değişir; sayfa yenilenince kalıcı | |
| 10 | İkinci bir tarayıcıda giriş yap → `/hesap/guvenlik` | Oturumlar listesinde iki satır, biri "Bu cihaz" | |
| 11 | Yanlış mevcut parolayla parola değiştir | Alan altında hata, hiçbir şey değişmez | |
| 12 | Doğru parolayla değiştir | Toast; liste tek satıra iner; ikinci tarayıcı bir sonraki tıklamada girişe atılır; log'da "parolanız değiştirildi" maili | |
| 13 | E-posta değiştir (yeni adres) | "Bağlantı gönderildi"; log'daki `/eposta-onay?…` bağlantısını aç → "adresiniz değişti", giriş sayfası; yeni adresle giriş açılır; eski adrese maskeli uyarı maili | |
| 14 | Aynı bağlantıyı tekrar aç | "Bağlantı kullanılamadı" | |
| 15 | `/hesap/gizlilik` (kullanılmamış hakkın varken) | Silme kartında amber kutu: "Kullanılmamış **N Gold** hakkınız…" — silme! | |

**Kapanış:** 15 satırın hepsi ✅ ise `fazlar/README.md`'de Faz 14 durumunu güncelle.
