# `tests/Feature/RateLimitTest.php`

> **Faz:** 10 — Dilim F, adım 10.60 · **Karar:** **K104** · **7 vaka**

---

## Sayılar (K104)

| Sınır | Önce | Şimdi | Gerekçe |
|---|---|---|---|
| LCV, IP başına dakikada | 10 | **20** | Salonda herkes aynı Wi-Fi'dan, yani aynı IP'den yanıt veriyor |
| LCV, davetiye başına saatte | 60 | **300** | 300 kişilik bir davetiye aynı akşam gönderilir; eskisi ilk saatte gelen yanıtların bir kısmını 429 ile geri çeviriyordu |
| Misafir medyası, IP başına dakikada | 5 | **15** | Düğün gecesi aynı Wi-Fi'dan fotoğraf yükleyen masalar |
| Misafir medyası, davetiye başına saatte | 40 | **150** | Aynı gece |

Medya sınırları LCV'ninkinden **dar** kalıyor: medya ucunda honeypot yok ve istek başına
maliyet (MB'lar, MIME analizi, kuyrukta yeniden kodlama) çok daha yüksek
(`AppServiceProvider::guestMediaLimits()` yorumu).

Sayılar testte **sabit** (10.49'un dersi): config'ten okuyan bir test sayı ne olursa olsun
yeşil kalırdı.

## IPv6 `/64`

| Test | Uç |
|---|---|
| `addresses_in_the_same_ipv6_slash_64_share_one_bucket` | LCV (+ komşu `/64` ayrı kova) |
| `guest_media_uploads_share_the_ipv6_slash_64_bucket` | Misafir medyası |
| `contact_messages_share_the_ipv6_slash_64_bucket` | İletişim |

Her sınırlayıcının **bağlantısı** ayrı sınanıyor. `IpBucket`'ın kendisi birim testinde
(`IpBucketTest`) doğru olsa bile, bir sınırlayıcı ham `$request->ip()`'ye geri dönerse
yalnızca kendi testi kırılır. İlk sürümde yalnızca LCV testi vardı; mutasyon medya ve
iletişim sınırlayıcılarının ham IP'ye dönmesini yakalayamadı, iki test bunun için eklendi.

Giriş (`auth`) ve genel `api` sınırlayıcıları da `IpBucket` kullanıyor ama testleri yok:
sayıları sabit (5, 20, 60) ve bir istek dizisini doldurmak pahalı. Bilinen boşluk.

## Mutasyon (1 Ekim 2026)

| Mutasyon | Kırılan |
|---|---|
| `IpBucket` IPv6'yı önek almasın | `IpBucketTest` (3 vaka) · LCV testi |
| Gömülü IPv4 dalı silinsin | `IpBucketTest` (2) |
| LCV / medya / iletişim sınırlayıcısı ham IP'ye dönsün | ilgili testin her biri |
| LCV davetiye 300 → 60 · medya IP 15 → 5 | sayı testleri |
