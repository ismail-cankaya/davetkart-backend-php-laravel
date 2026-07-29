# `config/app.php` — Kılavuz

Uygulamanın kimliği ve genel davranışı. Laravel'in en temel config dosyası.

## Önemli anahtarlar

| Anahtar | Ne işe yarar |
|---|---|
| `name` | Uygulama adı. Mail başlıklarında, log'larda, hata sayfalarında görünür |
| `env` | Çalışma ortamı: `local`, `production`. Kod bazı davranışları buna göre değiştirir |
| `debug` | `true` ise hata sayfasında dosya yolu + kod + değişkenler gösterilir |
| `url` | Konsoldan (kuyruk, komut) URL üretirken kullanılan taban adres |
| `timezone` | `now()` ve tarih işlemlerinin zaman dilimi |
| `locale` | Varsayılan dil. Doğrulama mesajlarının dilini belirler |
| `fallback_locale` | Çeviri bulunamazsa dönülecek dil |
| `key` | 🔴 `APP_KEY`. Şifreleme ve imzalama anahtarı |

## 🔴 `APP_DEBUG` — üretimde kesinlikle `false`

`true` iken oluşan bir hata sayfası; dosya yollarını, kod satırlarını ve
o anki değişken içeriklerini (veritabanı şifresi dahil) tarayıcıya basar.
Bu, gerçek dünyada en sık görülen veri sızıntısı sebeplerinden biridir.

## 🔴 `APP_KEY` — kaybedilirse geri dönüşü yok

Laravel bu anahtarla şifreler ve imzalar. Değiştirilirse:

- Şifrelenmiş sütunlar okunamaz hâle gelir,
- İmzalı URL'ler geçersizleşir,
- Bizde ayrıca **`ip_hash` değerleri anlamsızlaşır** — çünkü hash'i
  `hash(ip + app_key)` olarak üretiyoruz (KVKK gereği ham IP saklamıyoruz).

Üretim `APP_KEY`'i yedeklenir, git'e **girmez**.

## `timezone` ve `locale` — DavetKart'ta ne yapacağız

Şu an ikisi de Laravel varsayılanında (`UTC` / `en`).

**Zaman dilimi:** `UTC` kalacak. Sunucuda UTC saklamak, kullanıcı diliminde
göstermek standart yaklaşımdır; yaz saati ve çok ülkeli kullanım sorunlarını
ortadan kaldırır. Dönüşüm sunum katmanında (Resource) yapılır.

**Dil:** `APP_LOCALE=tr` olacak — doğrulama hataları Türkçe dönmeli. Ancak
frontend **10 dil** destekliyor. Bu yüzden Adım 6'da `SetLocaleFromHeader`
middleware'ini yazacağız: gelen `Accept-Language` başlığına göre istek başına
`app()->setLocale()` çağrılacak. `config('app.locale')` yalnızca varsayılandır.

## Dikkat

- `config('app.debug')` üzerinden dallanmak yerine `app()->isProduction()` gibi
  okunur yardımcılar tercih edilir.
- `env('APP_ENV')` kod içinde çağrılmaz; `app()->environment('local')` kullanılır.
