# `config/trustedproxy.php`

> **Faz:** 10 — Sertleştirme, adım 10.18
> **Kapattığı bulgu:** gözden geçirme raporu §2.3 (*"TrustProxies ayarlı değil"*)
> **Test:** [`tests/Feature/TrustedProxiesTest.md`](../tests/Feature/TrustedProxiesTest.md)
> **Env:** `TRUSTED_PROXIES` ([`docs/10`](../../10-URETIM-ENV-SABLONU.md))

---

## 1. Problem: `$request->ip()` kimi gösteriyor?

Tarayıcı ile sunucu arasında bir **yük dengeleyici** varsa (AWS ALB, CloudFront,
Cloudflare), sunucuya bağlantıyı açan taraf ziyaretçi değil **dengeleyicidir**:

```
Ziyaretçi 203.0.113.9  ──►  ALB 10.0.0.5  ──►  Laravel
                                               REMOTE_ADDR = 10.0.0.5
                                               X-Forwarded-For: 203.0.113.9
```

Ziyaretçinin gerçek adresi yalnızca `X-Forwarded-For` başlığında durur. Ama o
başlığı **herkes** yazabilir. Laravel, başlığa ancak onu yazan tarafa (burada
ALB'ye) güveniyorsa bakar.

| Durum | `ip()` | Sonuç |
|---|---|---|
| Dengeleyici var, **güvenilmiyor** | `10.0.0.5` (herkes için aynı) | 🔴 IP anahtarlı **bütün** hız sınırı kovaları (`throttle:api` 60/dk, `auth`, `rsvp`, `media`, `contact`) tek kovaya düşer. Site dakikada 60 istekte kilitlenir. `ip_hash` kolonları anlamsızlaşır |
| Dengeleyici var, **güveniliyor** | `203.0.113.9` | ✅ Her ziyaretçinin kendi kovası |
| Dengeleyici **yok**, `'*'` ile herkese güveniliyor | Saldırganın yazdığı adres | 🔴 Sahte `X-Forwarded-For` ile **bütün** kovalar atlatılır (plan tuzak #7) |
| Dengeleyici yok, kimseye güvenilmiyor | Bağlantıyı açan adres | ✅ Bugünkü yerel geliştirme |

---

## 2. Neden bu dosya? (plandan sapma)

Plan (10.18) `config/davetkart.php` → `trusted_proxies` + `AppServiceProvider`'da
`TrustProxies::at(...)` diyordu. Kaynağı okuyunca daha kısa bir yol çıktı:

```php
// vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustProxies.php
protected function setTrustedProxyIpAddresses(Request $request)
{
    $trustedIps = $this->proxies() ?: config('trustedproxy.proxies');
    ...
}
```

Laravel'in `TrustProxies` middleware'i global yığında **zaten kayıtlı** ve
`config('trustedproxy.proxies')`'i **her istekte kendisi okuyor**. Bu yüzden:

| | Planın yolu (`at()` + provider) | Bu dosya |
|---|---|---|
| Kod | Provider'da yeni bir metot | **Hiç** (yalnızca config) |
| `config:cache` | ✅ | ✅ (`env()` yalnızca config dosyasında) |
| Durum | `at()` **statik** bir özelliğe yazar | Yok: her istek config'i okur |
| Test | Provider `boot()` testten önce bir kez koştuğu için config değiştirilerek sınanamaz | `Config::set()` + istek, o kadar |

> `trustedproxy` adı Laravel'in eski `fideloper/proxy` paketinden geliyor.
> Laravel paketi çekirdeğe aldığında anahtarı geriye dönük uyumluluk için korudu.

---

## 3. Değerler

```php
'proxies' => env('TRUSTED_PROXIES') ?: null,
```

| `TRUSTED_PROXIES` | Anlamı | Ne zaman |
|---|---|---|
| *(boş)* → `null` | **Hiçbir vekile güvenme** (varsayılan) | Yerel geliştirme · aynı sunucuda nginx + php-fpm (AWS Yol A) |
| `10.0.0.0/16` | Yalnızca bu aralıktan gelen bağlantının başlığına güven | AWS ALB (VPC'nin CIDR'ı) |
| `10.0.0.5,10.0.0.6` | Virgülle birden çok adres/aralık | Sabit IP'li vekiller |
| `*` | 🔴 **Herkese** güven (`0.0.0.0/0` ve `::/0`) | Yalnızca sunucuya doğrudan erişim **ağ seviyesinde** kapalıysa (security group yalnızca ALB'ye açık) |

`?: null`: `.env`'de `TRUSTED_PROXIES=` (boş) yazmak `""` üretir. Middleware
boş dizgiyi de *"kimseye güvenme"* olarak işler, ama `null` niyeti açıkça
söylüyor ve test onu iddia ediyor.

### `'*'` neden bu kadar tehlikeli?

`'*'` yazıldığında Laravel güvenilen vekil listesini `0.0.0.0/0` ve `::/0` yapar:
bağlantıyı açan **her** adres vekil sayılır. Sunucu yalnızca ALB'den erişilebilir
durumdaysa bu doğrudur, çünkü bağlantıyı açan her zaman ALB'dir. Ama sunucunun
genel IP'si açıksa, doğrudan bağlanan bir saldırgan her istekte farklı bir
`X-Forwarded-For` yazar ve **her istek yeni bir kova** olur. `auth` limiti
(e-posta + IP başına 5/dk) anlamını yitirir, kaba kuvvet serbest kalır.

**Kural:** CIDR yaz. `'*'`'ı ancak security group'un yalnızca dengeleyiciye açık
olduğunu **gördükten** sonra düşün.

---

## 4. Hangi başlıklara güveniliyor?

Laravel'in varsayılanı, değiştirmedik:

```
X-Forwarded-For · X-Forwarded-Host · X-Forwarded-Port · X-Forwarded-Proto ·
X-Forwarded-Prefix · X-Forwarded-AWS-ELB
```

`X-Forwarded-Proto` ikinci kazanım: ALB TLS'i sonlandırıp PHP'ye `http` ile
geliyorsa, bu başlık güvenilir olunca `$request->isSecure()` doğru döner ve
üretilen URL'ler `https` kalır (`docs/10`'daki eski *"URL'ler http kalır"* notu).

> **B6:** `X-Forwarded-Host`'a da güveniliyor. Güvenilen vekil bu başlığı
> istemciden gelen hâliyle geçirirse, `route()` ile üretilen mutlak URL'lerin
> alan adı istemcinin yazdığı olabilir. Bugün `app/` içinde `route()` ya da
> `url()` ile mutlak URL üreten **hiçbir** yer yok (10.18'de arandı). Medya
> URL'leri disk config'inden (`APP_URL`) geliyor, isteğe bakmıyor. Parola
> sıfırlama bağlantısı Dilim D'de frontend config'inden üretilecek (10.34).
> Başlıkları daraltmak gerekirse:
> `bootstrap/app.php` → `$middleware->trustProxies(headers: …)`.

---

## 5. Yayına alırken

```
1. Barındırma yolu seçilince (AWS Yol B/C) dengeleyicinin adres aralığını bul
2. .env → TRUSTED_PROXIES=<CIDR>
3. php artisan config:cache
4. Doğrula: iki farklı ağdan (ör. telefon verisi + ev) art arda istek at;
   Sentry/log'daki ip_hash değerleri FARKLI olmalı
```

Cloudflare kullanılırsa: Cloudflare'in yayımladığı IP aralıkları
(`cloudflare.com/ips`) yazılır. Sabit değiller; değişince güncellenmeleri gerekir.

---

## 6. Kendin dene

```powershell
php artisan tinker
```

```php
config('trustedproxy.proxies');   // null — yerelde hiçbir vekile güvenilmiyor
```

Yerelde `TRUSTED_PROXIES` **boş kalmalı**: `artisan serve`'ün önünde vekil yok.
