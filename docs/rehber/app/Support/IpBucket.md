# `app/Support/IpBucket.php`

> **Faz:** 10 — Dilim F, adım 10.60 · **Karar:** **K104** (hız sınırları büyür + IPv6 /64)
> **Kullanan:** `AppServiceProvider`'daki bütün IP anahtarlı hız sınırlayıcıları
> **Testler:** `tests/Unit/IpBucketTest.php` · `tests/Feature/RateLimitTest.php`

---

## Ne yapıyor?

Bir IP adresini hız sınırı kovasının anahtarına çevirir:

| Girdi | Anahtar |
|---|---|
| `78.180.45.12` (IPv4) | `78.180.45.12` (aynen) |
| `2a02:ff0:3:1a2b:9c4d:11:22:33` (IPv6) | `2a02:ff0:3:1a2b::/64` |
| `::ffff:78.180.45.12` (IPv6 içine gömülü IPv4) | `78.180.45.12` |
| boş | `bilinmeyen` |
| geçersiz metin | aynen |

## Neden IPv6'da `/64`?

IPv4'te bir ev ya da telefon hattı çoğunlukla **bir** adres alır. IPv6'da ise bir hat
genellikle koca bir `/64` alır: 2⁶⁴ adres. Cihaz bunlardan istediğini kullanabilir
(gizlilik uzantıları adresi sık sık değiştirir). Kova adres başına olsaydı, tek bir cihaz her
istekte yeni bir adresle sınırı sonsuza dek aşardı. Kovayı `/64`'e indirmek, **hat başına**
saymak demek; IPv4'teki *"adres başına"* ile aynı anlam.

## Neden gömülü IPv4 ayrı?

`::ffff:a.b.c.d` biçiminde ilk 64 bit **hep sıfır**. Önek alınsaydı bu biçimde gelen bütün
IPv4 adresleri `::/64` kovasına, yani **tek** kovaya düşerdi: bir kişi sınırı doldurunca herkes
429 alırdı. Bu biçim, bazı vekil sunucular ve çift yığınlı (dual-stack) dinleyiciler arkasında
gerçekten görülüyor. Test iki farklı gömülü IPv4'ün ayrı kovalarda kaldığını iddia ediyor.

## KVKK

Bu anahtar yalnızca **önbellekteki** sayaçta yaşar (dakikalar/saatler) ve hiçbir yere
kaydedilmez. Veritabanına giden IP izi (`ip_hash`) `IpHasher` ile, adres başına ve anahtarlı
özetle üretiliyor; bu sınıf onu değiştirmiyor.

## Bilinen sınırlar (B6)

- **CGNAT:** mobil operatörler birçok aboneyi tek bir IPv4 adresinin arkasında toplar. O
  durumda kova zaten paylaşılıyor. 10.60'ta sınırların büyütülmesinin bir sebebi de bu
  (`RateLimitTest.md`).
- **`/64` ve daha büyük bloklar:** bir saldırgan `/48` alıp `/64`'leri gezebilir. Davetiye
  başına kova (LCV, medya) bunu ayrıca sınırlıyor; asıl savunma o.
