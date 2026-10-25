# Migration: `price_campaigns` tablosu

> **Faz:** 13, adım 13.2 · **Karar:** K132
> **Test:** `OrderPromotionConstraintsTest`

---

## 1. Kolonlar

| Kolon | Tip | Anlamı |
|---|---|---|
| `id` | ULID | Komutlarda (`pricing:campaign:stop <id>`) kullanılan kimlik |
| `percent` | smallint, CHECK 1–90 | İndirim yüzdesi |
| `tier` | string, NULL | NULL = bütün planlar |
| `starts_at` | timestamp | Başlangıç (`--from` yoksa komutun koştuğu an) |
| `ends_at` | timestamp, NULL | Bitiş (o günün sonu); NULL = durdurulana kadar |
| `stopped_at` | timestamp, NULL | Elle durdurma anı |
| `label` | string(120), NULL | Senin notun; müşteriye gösterilmez |

## 2. Kararlar

- **Neden config değil veritabanı? (K132)** Kampanya bir **olay**: başlar, biter, geçmişi
  vardır. Config'te dursaydı her kampanya bir commit ve deploy olurdu, bitiş tarihi de
  deploy saatine bağlı kalırdı. Tabloda duran kampanya `ends_at` geldiğinde **kendiliğinden**
  biter. Hiçbir zamanlanmış görev ya da cron gerekmez, çünkü "şu an geçerli mi?" sorusu her
  istekte `now()` ile sorulur.
- **Neden üst sınır 90?** Tutar indirimle 0'a inerse sipariş `orders_gift_amount_check`
  kısıtını ihlal eder (0 tutarlı sipariş = hediye siparişi). Bedava yayının yolu %100'lük
  hediye kodu.
- **Neden silinmiyor, durduruluyor?** Sipariş `price_campaign_id` ile "bu indirimi şu
  kampanyadan aldım" der. FK `restrict`: kullanılmış bir kampanyayı silmeye çalışmak hata
  verir. Muhasebe izi bir komutla yok olamaz.
- **Neden "aynı anda tek kampanya" kısıtı yok?** Gerek yok. Aynı plana birden fazla kampanya
  uygunsa **büyük olan** seçilir (`QuotePriceAction`). Kısıt koysaydık "Gold'a %30" ile
  "hepsine %10"u aynı anda açamazdın.
- `ends_at > starts_at` CHECK'i ters yazılmış bir tarihi (`--from` bitişten sonra) reddeder.
