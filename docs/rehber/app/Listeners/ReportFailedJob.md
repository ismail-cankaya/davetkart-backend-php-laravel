# `app/Listeners/ReportFailedJob.php`

> **Faz:** 19.7 (`OPS-03`) · olay `Illuminate\Queue\Events\JobFailed` (olay keşfi; `app/Listeners` taranır)
> **Test:** `tests/Feature/ReadinessTest.php::a_failed_job_is_reported`

## Neden

Denemeleri biten iş (`failed_jobs`'a düşen) sessizdi: parola sıfırlama maili gitmez, kullanıcı bekler.
Dinleyici her başarısızlığı tek satırla bildirir.

## Desen

- `Log::critical`: Sentry log kanalının eşiği `critical` (10.55b). Üretimde `LOG_STACK=daily,sentry` ile
  satır Sentry'ye gider; DSN yoksa yalnızca günlük dosyasına.
- Bağlam yalnızca: iş sınıfı, kuyruk, bağlantı, deneme sayısı, istisna **sınıfı**.
- İstisnanın kendisi zaten işçinin hata işleyicisinden (`report()`) Sentry'ye gider; bu satır "hangi iş
  vazgeçildi" sorusunu tek bakışta cevaplar.

## Hangi hataya karşı

Kişisel veri sızıntısı: iş yükü (`payload`) e-posta, ad, token taşır; istisna metni de çoğu zaman
("… ayse@example.com için gönderilemedi"). Test istisna metninde e-posta verir ve bağlamın birebir
beş alan olduğunu doğrular.

## Sık hata

- `$event->exception->getMessage()` ya da `$event->job->getRawBody()` eklemek.
- `Log::error` kullanmak: Sentry kanalı eşiğin altında kalanı almaz.
- Dinleyiciyi ayrıca `Event::listen` ile kaydetmek: olay keşfi zaten kaydeder, iki satır düşer.

## Nasıl denenir

Kuyruğu `database` yapıp hata fırlatan bir işi `--tries=1` ile koştur; `storage/logs` içinde
`Queue job failed.` satırı, bağlamda e-posta yok.
