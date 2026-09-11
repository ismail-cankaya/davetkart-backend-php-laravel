<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Zamanlanmis bakim isleri (Faz 9)
|--------------------------------------------------------------------------
|
| 🔴 Buraya kadar yazilan her sey bir ISTEK tarafindan tetikleniyordu:
| kullanici bir uca vurur, kod calisir. Bu blok projenin ilk KENDILIGINDEN
| calisan kodudur ve uc yeni risk getirir:
|
|   1. Kimse bakmiyorken calisir      -> hata sessizce yutulabilir
|   2. Ust uste calisabilir            -> onceki kosu bitmeden ikincisi baslar
|   3. Sunucu ayaga kalkmazsa hic calismaz -> ve bunu kimse fark etmez
|
| Ucune de asagida acik cevaplar verildi.
|
| ⚠️ Bu blok TEK BASINA hicbir sey yapmaz. Zamanlayicinin calismasi icin
| sunucuda dakikada bir `php artisan schedule:run` cagrilmali (cron / Task
| Scheduler). Bu dosya "ne zaman" der; "calistir" diyen sey isletim sistemidir.
| Ayrintili aciklama: docs/rehber/routes/console.md
|
*/

// Odeme penceresi dolmus bekleyen siparisler (9.9).
//
// Saatlik: `order_expires_after_minutes` 30 dakika, yani bir siparis en kotu
// ihtimalle ~90 dakika 'pending' gorunur. Daha sik kosmanin degeri yok —
// kimse bu satiri gercek zamanli okumuyor. Daha seyrek kosmak ise "odeme
// bekliyor" satirlarini gun boyu ekranda tutardi.
Schedule::command('orders:expire')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Hicbir LCV'ye baglanmamis misafir yuklemeleri (9.10).
//
// 🔴 Gunluk ve GECE YARISINDAN SONRA degil, 03:15'te. Iki sebep:
//   - Gece yarisi bir yigin isin ayni anda basladigi andir (raporlar,
//     yedekler, log rotasyonu). Bakim isleri o tepeden uzak tutulur.
//   - :00 yerine :15 — ayni dakikada baslayan islerin veritabanina ayni anda
//     yuklenmesini onler. "Thundering herd"in kucuk olcekli hali.
//
// Saat dilimi BILEREK ayarlanmadi: sunucu UTC'de kosar ve bu is icin "saat
// kacta" sorusunun is tarafinda bir cevabi yok (kimseyi rahatsiz etmiyor).
// K71'in tersi: orada saat dilimi ANLAMLIYDI, burada degil.
Schedule::command('media:prune-orphans')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

// Suresi dolmus Sanctum token'lari.
//
// 🔴 Laravel bu komutu Faz 2'den beri sagliyordu ve sekiz fazdir CAGRILMADI:
// `personal_access_tokens` tablosu her girisle buyuyor, hicbir sey kucultmuyor.
// --hours=720 (30 gun): iptal edilmis ya da suresi dolmus bir token bir ay
// saklanir, sonra silinir. Sifir yazmiyoruz cunku bir guvenlik incelemesinde
// "hangi token ne zaman iptal edildi" sorusunun izi kalmali.
Schedule::command('sanctum:prune-expired --hours=720')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
