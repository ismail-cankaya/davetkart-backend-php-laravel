# `app/Support/IpHasher.php`

> **Faz:** 8, dosya 8.7 · **Kurallar:** C3 · K14 · ders 52
> **Kapattigi acik karar:** `FAZ-7.md` §9 madde 2

---

## 1. Problem: ayni kural, iki refleks

Faz 8 baslarken depoda IP hash'leme **iki farkli sekilde** yaziliydi:

```php
// Faz 5 — SubmitRsvpAction
hash('sha256', $ip.Config::string('app.key'));

// Faz 7 — FakeGateway (webhook imzasi)
hash_hmac('sha256', $payload, Config::string('app.key'));
```

Ve ucuncusu (iletisim formu) geliyordu. Bir kuralin iki yerde durmasi
birini duzeltip digerini unutmanin yolunu acar; ucuncusu ise kural degil
**kopyalama aliskanligi** uretir (**C3**).

---

## 2. 🔴 Neden `hash_hmac`, duz `hash` degil?

Ikisi de "geri cevrilemez" ozet uretir. Fark **uzunluk-uzatma
(length-extension) saldirisidir**:

SHA-256 gibi Merkle–Damgård yapili ozetler, ic durumlarini ciktiyla birlikte
sizdirir. Saldirgan `hash($gizli . $veri)` degerini ve `$veri`'nin
uzunlugunu biliyorsa, `$gizli`'yi **hic bilmeden**
`hash($gizli . $veri . $ek)` degerini uretebilir.

`hash_hmac` tam olarak bu sinifi kapatmak icin tasarlandi: iki turlu
(ic + dis) hash ve anahtarin dolgulanmasi.

> Bizim durumumuzda somut bir sömürü senaryosu **yok** (`ip_hash` hicbir
> yerde imza olarak kullanilmiyor). Yine de dogru fonksiyonu secmek
> bedavaydi — ve yanlis refleks bir gun imza gereken bir yere kopyalanirdi.

### Degisim neden zararsizdi?

`ip_hash` degerleri hicbir yerde **karsilastirilmiyor**; yalnizca
yaziliyor. Hicbir test degerine bakmiyor (`RsvpTest` yalnizca yanitta
*gorunmedigini* dogruluyor, `RsvpFactory` kendi sahte degerini uretiyor).
Eski satirlarin "gecersiz" olmasi bir islevi bozmuyor.

---

## 3. `APP_KEY` neden karisima giriyor? (pepper)

Yalnizca `sha256(ip)` yazsaydik, saldirgan tum IPv4 uzayinin (~4.3 milyar)
ozetini onceden hesaplayip tabloyu **geri cozebilirdi**. Anahtar karisima
girdiginde bu sozluk saldirisi imkansizlasir — cunku anahtar yalnizca
sunucuda.

Bu, K14'un ("IP adresleri hash'lenerek saklanir") gercek uygulamasi:
hash'lemek tek basina yetmez, **anahtarli** hash'lemek gerekir.

---

## 4. Neden statik metot, enjekte edilebilir sinif degil?

Bu **saf bir fonksiyondur**: ayni girdi + ayni `APP_KEY` → ayni cikti;
durum yok, yan etki yok, ikinci bir uygulamasi olamaz.

Arayuz koysaydik dikis yerinin **obur tarafi bos kalirdi** — ders 52'nin
tersten okunusu: bir soyutlamanin degeri, degistirilecek bir uygulama
**varsa** dogar. `RsvpQuotaResolver` degerliydi cunku gercek kaynak Faz
7'de geldi; burada gelecek bir sey yok.

Ek fayda: iki Action'in kurucusuna dokunulmadi — Faz 5 kodunda yapilan
degisiklik en aza indi.

---

## 5. `app/Support/` klasoru — yeni bir katman mi?

Hayir, bir **katmansizlik** isareti. `CLAUDE.md` §1'in katmanlari birer
**sorumluluk** tanimlar:

| Klasor | Kimin sorusunu cevaplar |
|---|---|
| `app/Services/<Alan>/` | Bir **dis servise** giden cagri |
| `app/Contracts/` | Uygulamanin **kendi** soyutlamasi |
| `app/Support/` | Hicbirine ait olmayan, durumsuz **yardimci** |

`IpHasher` bir dis servisle konusmuyor, bir is kurali tasimiyor ve
degistirilebilir bir uygulamasi yok. Uc katmandan birine zorla sokmak,
klasor adlarini anlamsizlastirirdi.

---

## 6. Kendin dene

```php
// php artisan tinker
App\Support\IpHasher::hash('127.0.0.1');
// 64 karakter; APP_KEY degisirse tamamen degisir

App\Support\IpHasher::hash('127.0.0.1') === hash('sha256', '127.0.0.1'.config('app.key'));
// false  ← 8.7 gercekten bir sey degistirdi
```

Son satir bir **mutasyon dusuncesidir** (T16) ve `ContactTest`'te test
olarak duruyor: `the_ip_hash_is_a_keyed_hmac`.

---

## 7. Terim sozlugu

| Terim | Anlami |
|---|---|
| **HMAC** | Anahtarla hesaplanan, kimlik kaniti saglayan ozet |
| **Pepper** | Tum kayitlar icin ortak, veritabaninda **durmayan** gizli ek |
| **Length-extension** | Sirri bilmeden ozeti "uzatma" saldirisi |
| **Saf fonksiyon** | Ayni girdiye ayni ciktiyi veren, yan etkisiz fonksiyon |
