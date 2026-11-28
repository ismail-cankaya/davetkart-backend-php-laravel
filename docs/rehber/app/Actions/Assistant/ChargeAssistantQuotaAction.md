# `app/Actions/Assistant/ChargeAssistantQuotaAction.php`

Faz 25 (K310). Asistanın günlük kotasından bir mesaj düşer. Eskiden `AskAssistantAction`'ın özel metoduydu; metin
önerisi (`SuggestInvitationTextsAction`) de aynı kotayı kullansın diye ayrı bir Action oldu. Davranış aynı:
`insertOrIgnore` ile günün satırı açılır, tek bir `UPDATE … WHERE message_count < limit` deyimi hem kontrol hem yazmadır
(check-then-act yok); etkilenen satır 0 ise `AssistantQuotaExceededException` (429, yeniden deneme süresi gün
sonuna kadar). Gün `davetkart.default_timezone`'a göre döner.

Sıra her çağıranda aynıdır: **önce kota düşer, sonra para harcayan sağlayıcı çağrısı** (gerekçe
`AskAssistantAction.md` §6).

## K18 taşıması (Faz 24, 2026-11-28)

`app/Actions/Assistant/ChargeAssistantQuotaAction.php` değiştiği için K18 cırcırı dosyanın tamamını denetledi; 3 satırı aşan yorum blokları kısaltıldı, tam metinleri aşağıda (değiştirilmeden).

### K18-1

Eski satır 19, şunun üstünde: `public function handle(User $user): void`.

> Gunluk sayaci bir artirir; butce doluysa exception firlatir.
> 🔴 CHECK-THEN-ACT YOK. "Once oku, sonra karsilastir, sonra yaz" uc
> ayri adim olsaydi es zamanli iki istek ayni degeri okur ve ikisi de
> "yer var" derdi (Faz 2'nin E2'si, Faz 5'in kota yarisi). Cozum orada
> satir kilidiydi; burada kilide bile gerek yok, cunku kontrol ve yazma
> TEK BIR SQL DEYIMINDE birlesiyor:
> UPDATE assistant_usages SET message_count = message_count + 1
> WHERE user_id = ? AND usage_date = ? AND message_count < ?
> Veritabani bu deyimi atomik uygular. Kota doluysa WHERE hicbir satira
> uymaz ve etkilenen satir sayisi 0 doner — reddin kaniti budur.

### K18-2

Eski satır 39, şunun üstünde: `AssistantUsage::query()->insertOrIgnore([`.

> 1. Satiri VAR ET. insertOrIgnore: satir zaten varsa hicbir sey
> yapmaz ve UNIQUE(user_id, usage_date) ihlali HATA FIRLATMAZ. Iki
> es zamanli ilk mesajdan biri sessizce eler (E2: benzersizlik `if`
> ile degil veritabani kisitiyla korunur).

### K18-3

Eski satır 81, şunun üstünde: `private function secondsUntilReset(): int`.

> Butcenin yenilenmesine kalan saniye — 429'un Retry-After degeri.
> Carbon'un diff metotlari surum ve `absolute` varsayilanina gore float
> dondurebilir; iki zaman damgasinin cikarilmasi belirsizlik birakmayan
> tam sayidir (ders 18: aracin davranisini tahmin etme, kesin olani yaz).
