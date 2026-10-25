# Fiyat, indirim ve ödeme yöntemi yönetimi — işletme kılavuzu

> **Faz:** 13 · **Kime:** DavetKart'ı işleten kişiye (sen)
> **Kural:** Frontend'de fiyat yok. Bütün sayılar backend'de; frontend onları
> `GET /api/public/plans` ve `GET /api/pricing` uçlarından okur.

Bu belge "nasıl yaparım?" sorularını cevaplar. "Neden böyle?" için her bölümün sonunda
ilgili kılavuz var.

---

## 0. Hızlı bakış

| Ne yapmak istiyorsun? | Nerede? | Deploy gerekir mi? |
|---|---|---|
| Paket fiyatını değiştirmek | `config/davetkart.php` → `tiers.*.price` | ✅ Evet (commit + deploy) |
| İlk yayın indirimini değiştirmek / kapatmak | `config/davetkart.php` → `pricing.first_publish_percent` | ✅ Evet |
| Süreli kampanya açmak / durdurmak | `php artisan pricing:campaign:*` | ❌ Hayır, anında |
| Ödeme yöntemini açmak / kapatmak | Sunucu `.env` → `PAYMENT_METHODS` | ❌ Kod yok; `config:cache` |
| Havale hesabını değiştirmek | Sunucu `.env` → `BANK_TRANSFER_*` | ❌ Kod yok; `config:cache` |
| Kişiye özel indirim / bedava paket | `php artisan gift-codes:*` | ❌ Hayır, anında |

Frontend'i yeniden derlemen **hiçbirinde** gerekmez.

---

## 1. Paket fiyatını değiştirmek

`config/davetkart.php`:

```php
'tiers' => [
    'standart' => ['rank' => 0, 'price' => 249, 'rsvp_limit' => 100],
    'gold'     => ['rank' => 1, 'price' => 399, 'rsvp_limit' => null],
    'elit'     => ['rank' => 2, 'price' => 549, 'rsvp_limit' => null],
],
```

1. `price` değerini değiştir. TL, tam sayı; `449` yaz, `449.90` yazma.
2. Commit at, deploy et. Deploy betiği `php artisan config:cache` çalıştırır.
3. Kontrol et:
   ```bash
   curl -s https://api.davetkart.com/api/public/plans
   ```

**Neden `.env`'de değil?** Fiyat ortamdan ortama değişen bir ayar değil, müşteriye
verilmiş bir söz. Repoda durursa "ne zaman, neden değişti?" sorusunun cevabı git
geçmişinde olur.

**Eski siparişler etkilenmez.** Her sipariş kendi tutarını açıldığı anda dondurur.

---

## 2. İlk yayın indirimi (%60)

`config/davetkart.php`:

```php
'pricing' => [
    'first_publish_percent' => 60,   // 0 = kapalı, en fazla 90
],
```

- **Kim alır?** `paid_at` damgalı **hiç** siparişi olmayan kullanıcı. Hediye koduyla bedava
  aldığı paket de sayılır, yani o kullanıcı sonradan %60 alamaz (senin kararın).
- **Ödemeyi yarıda bırakan** (sayfaya gidip vazgeçen) kullanıcı indirimini kaybetmez.
- **Misafire** fiyat sayfasında "İlk davetiyenizde %60 indirim" yazısı görünür. Giriş
  yapınca kartlarda indirimli fiyatı görür.
- `0` yaparsan ana sayfadaki, kayıt sayfasındaki ve fiyat sayfasındaki "%60" yazıları
  **kendiliğinden kaybolur**. Kapatılmış bir indirim vaat edilmez.
- 🔴 `90`'dan büyük bir değer her checkout'u 500 yapar (gürültülü hata, bilerek). Bedava
  yayının yolu hediye kodu.

---

## 3. Süreli kampanya (manuel indirim)

### Açmak

```powershell
# Hemen başla, 7 Ocak'ın sonuna kadar (İstanbul saati), bütün paketlerde %20
php artisan pricing:campaign:start 20 --until=2027-01-07 --label="Yilbasi"

# 1 Kasım'da başla, yalnızca Gold, durdurulana kadar %30
php artisan pricing:campaign:start 30 --tier=gold --from=2026-11-01
```

| Seçenek | Anlamı |
|---|---|
| `percent` (zorunlu) | 1–90 |
| `--tier` | `standart` · `gold` · `elit`; boş = hepsi |
| `--from` | Başlangıç günü (o günün başı). Boş = hemen |
| `--until` | Son gün (o günün sonu). Boş = sen durdurana kadar |
| `--label` | Kendi notun; müşteri görmez |

### Görmek

```powershell
php artisan pricing:campaign:list            # hepsi, en yeni üstte
php artisan pricing:campaign:list --active   # şu an geçerli olanlar
```

"Ödenen sipariş" sütunu bu kampanyadan indirim alıp **ödemesi tamamlanmış** sipariş sayısıdır.

### Durdurmak

```powershell
php artisan pricing:campaign:stop 01jb2k...   # list'teki kimlik
```

Kampanya silinmez, durdurulur. Ondan indirim almış siparişler ona bağlı kalır.

### Kurallar

- **Bitiş kendiliğinden.** `--until` geldiğinde kampanya uygulanmaz. Zamanlanmış görev
  ya da deploy gerekmez.
- **Toplanmaz.** Kampanya ile ilk yayın indiriminden **büyük olan** uygulanır. %20'lik bir
  kampanya yeni üyeye bir şey kazandırmaz; onlar zaten %60 alıyor. Kampanya, ilk yayınını
  yapmış müşteriler ve misafirler içindir. `start` komutu bunu sana hatırlatır.
- **Birden çok kampanya** aynı anda açık olabilir. Bir pakete uyanların **en büyüğü** seçilir.
- Fiyat sayfası ve paywall kampanyayı rozetle gösterir: "%20 kampanya indirimi · 7 Ocak
  tarihine kadar".

---

## 4. Ödeme yöntemlerini açıp kapatmak

Sunucudaki backend `.env` dosyasında **tek satır**:

```dotenv
PAYMENT_METHODS=gift_code                      # BUGÜN: yalnızca hediye kodu
PAYMENT_METHODS=card,gift_code                 # Shopier canlıya alınınca
PAYMENT_METHODS=card,bank_transfer,gift_code   # hepsi
```

Sonra:

```bash
php artisan config:cache
```

| Kelime | Ne açar? |
|---|---|
| `card` | Kartla ödeme (aktif sağlayıcı: `PAYMENT_PROVIDER`, yani Shopier) |
| `bank_transfer` | Havale/EFT ekranı. `BANK_TRANSFER_*` dolu ve IBAN geçerliyse açılır |
| `gift_code` | Hediye kodu alanı |

### Kapattığında ne olur?

- **Frontend:** düğme hiç çizilmez. Kart ve havale kapalıyken ödeme sayfası yalnızca
  hediye kodu alanını ve "Şu an yalnızca hediye kodu" açıklamasını gösterir.
- **Backend:** kapalı yöntemle gelen istek **reddedilir** (403 `PAYMENT_METHOD_DISABLED`).
  Tarayıcı konsolundan gönderilen istek de geçemez.
- **Kart kapalıyken** %100'lük hediye kodu çalışır. Kısmi kod (%50 gibi) ise **çalışmaz**:
  kalan tutarı ödeyecek yöntem yok. Ekran bunu kullanıcıya söyler, kodun hakkı da yanmaz.
- **Kart kapalıyken** Shopier sırlarının boş olması sorun değil, çünkü sağlayıcı hiç
  kurulmaz (K136).

### ⚠️ Bilmen gerekenler

- **Bugünkü durum:** `PAYMENT_METHODS=gift_code` iken %60 indirim **ekranda görünür ama
  tahsil edilemez**. Yeni üye 159,60 ₺'yi ödeyemez. Yayın yalnızca %100'lük kodla mümkün.
  Kartı açtığın gün indirim gerçek satışa döner.
- **Yanlış yazım:** `PAYMENT_METHODS=gift_cod` gibi tanımsız bir kelime fiyat ve ödeme
  uçlarını **503** yapar. Sessizce yok sayılmaz, çünkü yok sayılsaydı satış durur ve kimse
  nedenini bilmezdi. Davetiye okuma, LCV ve giriş etkilenmez.
- **Boş değer** (`PAYMENT_METHODS=`) bilinçli olarak "hiçbir yöntem açık değil" demektir.
- Değişken **hiç yazılmamışsa** varsayılan `card,gift_code`.

### Havale hesabı

```dotenv
BANK_TRANSFER_BANK_NAME=Ziraat Bankası
BANK_TRANSFER_ACCOUNT_HOLDER=DavetKart Yazılım Ltd. Şti.
BANK_TRANSFER_IBAN=TR33 0006 1005 1978 6457 8413 26
```

IBAN boşluklu ya da boşluksuz yazılabilir. Sağlama toplamından geçmezse havale **kapalı**
sayılır ve log'a uyarı düşer. Yanlış bir IBAN müşterinin parasını başkasına gönderir.
Eski `VITE_BANK_TRANSFER_*` (frontend `.env`) artık okunmuyor; silebilirsin.

---

## 5. Hediye kodu (değişmedi, hatırlatma)

```powershell
php artisan gift-codes:create DUGUN2026 --benefit=free --tier=gold --max-uses=10
php artisan gift-codes:create INSTA20 --benefit=percent --percent=20 --max-uses=500 --expires=2026-12-31
php artisan gift-codes:generate --count=50 --benefit=free --tier=standart
php artisan gift-codes:list --active
php artisan gift-codes:disable <kimlik>
```

**Faz 13 değişikliği:** kod artık otomatik indirimden **sonra kalan** fiyata uygulanır.

| Paket | İlk yayın | Kod | Ödenecek |
|---|---|---|---|
| Gold 399 ₺ | −239,40 ₺ (%60) | %50 → −79,80 ₺ | **79,80 ₺** |
| Gold 399 ₺ | −239,40 ₺ (%60) | ücretsiz → −159,60 ₺ | **0 ₺** (anında etkin) |
| Gold 399 ₺ | — (ilk yayın kullanılmış) | %50 → −199,50 ₺ | **199,50 ₺** |

---

## 6. Bir siparişin neden o tutarda açıldığını görmek

```sql
SELECT id, tier, amount_minor, discount_minor,
       promotion, promotion_discount_minor, price_campaign_id
FROM orders WHERE id = '01j...';
```

| Kolon | Anlamı |
|---|---|
| `amount_minor + discount_minor` | Liste fiyatı (kuruş) |
| `promotion` | `first_publish` · `campaign` · boş |
| `promotion_discount_minor` | Otomatik indirimin payı |
| `discount_minor − promotion_discount_minor` | Hediye kodunun payı |

---

## 7. Daha fazlası

- Fiyat hesabı: [`app/Actions/Pricing/QuotePriceAction.md`](app/Actions/Pricing/QuotePriceAction.md)
- Kampanya komutları: [`app/Console/Commands/PriceCampaignCommands.md`](app/Console/Commands/PriceCampaignCommands.md)
- Yöntem anahtarı: [`app/Services/Payment/PaymentMethods.md`](app/Services/Payment/PaymentMethods.md)
- Fazın özeti: [`fazlar/FAZ-13.md`](fazlar/FAZ-13.md)
