# `app/Console/Commands/ExportPlanRules.php`

> **Faz:** 18, adım 18.1.2 (`OPS-01` · K146)
> **Kapsanan dosyalar:** `app/Console/Commands/ExportPlanRules.php` · `contracts/plan-rules.json` ·
> `composer.json` (`check` zinciri)
> **Bağlantılı:** [`ExportErrorCodes.md`](ExportErrorCodes.md) (aynı desenin ilk örneği) ·
> [`TierResolver.md`](../../Services/Pricing/TierResolver.md) ·
> [`PlanRulesContractTest.md`](../../../tests/Feature/PlanRulesContractTest.md) ·
> [`FAZ-18.md`](../../../fazlar/FAZ-18.md) §18.1

## 0. Bir dakikalık özet

`config/davetkart.php`'deki plan kurallarını okuyup `contracts/plan-rules.json`'a yazar:

```powershell
php artisan plans:export           # dosyayı yeniden yazar
php artisan plans:export --check   # yazmaz; dosya config'le aynı değilse çıkış kodu 1
```

```json
{
    "moduleTiers": { "show_envelope": "gold", "show_gallery": "elit", "…": "…" },
    "presetTiers": { "baby-ay-besigi": "gold", "…": "…" },
    "rsvpLimits": { "elit": null, "gold": null, "standart": 100 },
    "tierOrder": ["standart", "gold", "elit"],
    "whiteLabelTier": "elit"
}
```

Frontend bu dosyanın kopyasını tutar ve kendi kural kopyalarını onunla karşılaştırır
(`npm run verify:plans`).

## 1. Hangi problemi çözüyor?

"Hangi şablon hangi planda" kuralı iki depoda ayrı ayrı yazılı:

| Depo | Yer | Görevi |
|---|---|---|
| backend | `config/davetkart.php` → `preset_tiers`, `module_tiers` | Kuralı **uygular** (402 döner) |
| frontend | `src/data.ts` → `minimumTier`, fiyat kartı, `getRequiredTier` | Kuralı **gösterir** ("Gold+" rozeti) |

İki kopya zorunlu: istemcinin hesabına güvenilmez, o yüzden sunucu kendi hesabını yapar
([`TierResolver.md`](../../Services/Pricing/TierResolver.md)). Sorun kopyanın varlığı değil,
**iki kopyayı birbirine bağlayan hiçbir şeyin olmamasıydı.** Denetimde (`OPS-01`) görülen
senaryo: frontend 32 şablonu "Gold+" diye gösterirken yayındaki backend yalnızca 13'ünü
tanıyordu. Kullanıcı Standart planla premium şablon yayınlayabilirdi; fark şablon başına 150 ₺.

Kimse hata yapmadı; iki depo ayrı ayrı ilerledi ve farkı söyleyen bir araç yoktu.

## 2. Desen: tek kaynak → üretilen sözleşme → bayatlık denetimi

```
config/davetkart.php ─[plans:export]→ contracts/plan-rules.json ─(kopyala)→ frontend/src/contracts/
        ▲                                      │                                   │
        └──── composer check: --check ─────────┘            npm run check: verify:plans
```

Üç parça, üç ayrı güvence:

1. **Tek kaynak.** Kural yalnızca config'te değişir. Komut hiçbir kuralı kendisi bilmez.
2. **Üretilen dosya (sözleşme).** Config PHP'dir, frontend onu okuyamaz. JSON iki tarafın da
   okuyabildiği ortak dildir. Dosya repoya işlenir, çünkü tüketicisi başka bir depodadır.
3. **Bayatlık denetimi (`--check`).** Üretilen dosya kaynağından geri kalabilir. `--check`
   bunu `composer check` içinde, testlerden önce yakalar.

Bu desen hata kodlarında zaten vardı (`errors:export`, K31). Faz 13'te fiyat için "tek kaynak"
kuruldu (K128: frontend'de fiyat yok). Bu adım aynı ilkeyi fiyatın **anlamını** veren kurallara
uzatıyor.

> Paylaşılan paket ya da ortak depo neden değil? [`ExportErrorCodes.md`](ExportErrorCodes.md) §1:
> iki depoyu derleme zamanında birbirine bağlamak, tek geliştiricili projede faydasından ağır.
> Bağımlılık yerine **kopya** var; kopyanın eskimesini iki denetim yakalıyor.

## 3. Tasarım kararları

### 3.1 Dosyada ne var, ne yok?

| Alan | Kaynak | Not |
|---|---|---|
| `tierOrder` | `tiers.*.rank` | Küçükten büyüğe plan sırası |
| `rsvpLimits` | `tiers.*.rsvp_limit` | `null` = sınırsız |
| `moduleTiers` | `module_tiers` | Anahtarlar veritabanı kolon adı (`show_gallery`) |
| `presetTiers` | `preset_tiers` | Listede olmayan şablon her planda yayınlanır |
| `whiteLabelTier` | `branding.white_label_tier` | "Logosuz özel yayın" |

🔴 **Fiyat yok.** Fiyat ve indirim API'den okunur (`GET /public/plans`, K128–K129). Sözleşmeye
de yazılsaydı aynı sayının üçüncü bir kopyası olurdu ve indirim geldiği gün ayrışırdı.
`PlanRulesContractTest` dosyada `price` kelimesi geçerse kırmızı yanar.

Modül anahtarları config'teki hâliyle (`show_gallery`) yazılır, `showGallery`'ye çevrilmez.
Çeviri bir tahmin olurdu (`Str::camel`); API alan adları Resource'larda **açıkça** yazılır.
Frontend eşlemeyi kendi tarafında, tipli bir tabloda tutar; sözleşmeye yeni bir modül
eklenirse `verify:plans` "eşlemesi yok" diye durur.

### 3.2 Neden zaman damgası yok? (`errors:export`'tan tek fark)

`error-codes.json`'da `generatedAt` var ve `--check` karşılaştırmadan önce onu siler
([`ExportErrorCodes.md`](ExportErrorCodes.md) §3.4). Burada damga hiç yazılmıyor:

- `--check` düz bir **bayt karşılaştırması** olur, düzenli ifade gerekmez.
- Dosyayı yeniden üretmek, kural değişmediyse **hiç diff üretmez**. Damgalı dosyada her
  `export` bir satır değiştirir ve "bir şey mi değişti?" sorusunu commit'e taşır.
- Frontend kopyası kaynakla doğrudan karşılaştırılabilir.

Buna **deterministik çıktı** denir: aynı girdi, bayt bayt aynı çıktı. Üretilen her dosya için
iyi bir varsayılandır; "ne zaman üretildi" bilgisini zaten `git log` verir.

### 3.3 Sıralama: `ksort` ve `usort`

```php
ksort($map, SORT_STRING);                                   // moduleTiers, presetTiers, rsvpLimits
usort($tiers, fn ($a, $b) => $a->rank() <=> $b->rank());    // tierOrder
```

- Haritalar **ada göre** sıralanır. Config'te bir satırı yukarı taşımak (okunabilirlik için)
  dosyada diff üretmez.
- `tierOrder` bir **liste**dir; sırası anlam taşır, o yüzden alfabetik değil `rank`'e göre.
  Sıra enum'un yazılış sırasından değil config'ten gelir (`the_tier_order_follows_the_configured_rank`).
- `SORT_STRING`: varsayılan kip sayıya benzeyen anahtarları sayı gibi karşılaştırır. Bugün
  öyle bir anahtar yok; kip, sıranın bir gün sessizce değişmemesi için açıkça yazıldı.

### 3.4 Tanınmayan plan adı gürültülü patlar

```php
throw new RuntimeException("Configuration error: {$key} is not a valid tier.");
```

Config'e `'show_gallery' => 'platin'` yazılırsa komut dosyayı yazmaz, istisna fırlatır.
`TierResolver` aynı mesajla aynı şeyi yapar. Sessizce geçseydi sözleşme frontend'e
var olmayan bir planı taşırdı.

### 3.5 `composer check` zincirindeki yeri

```json
"@php artisan errors:export --check",
"@php artisan plans:export --check",
"@php artisan test"
```

Testlerden **önce**: saniyelik bir denetim, iki dakikalık test koşusunu beklemeden kırmızıyı
gösterir (*fail fast*). Aynı denetim `PlanRulesContractTest::the_plan_rules_contract_is_up_to_date`
içinde bir kez daha var; biri zinciri, öbürü `php artisan test`'i tek başına koşanı korur.

### 3.6 Neden ortak bir "sözleşme komutu" sınıfı yok?

`ExportErrorCodes` ile `check()` gövdesi benziyor. İki örnek için soyutlama erken: iki komutun
farkı (damga var/yok, kaynak enum/config) ortak sınıfı bayraklarla doldururdu. Üçüncü bir
sözleşme gelirse ortak parça o gün çıkarılır.

## 4. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `preset_tiers`'ı değiştirip `plans:export`'u unutmak | `composer check` kırmızı | `php artisan plans:export`, sonra frontend kopyasını yenile |
| `contracts/plan-rules.json`'u elle düzenlemek | `--check` kırmızı; kural config'te değişmemiştir | Config'i değiştir, dosyayı üret |
| Yalnızca frontend kopyasını elle düzeltip `verify:plans`'i susturmak | Arayüz "Gold+" der, sunucu uygulamaz: `OPS-01`'in ta kendisi | Kural backend'de değişir; kopya oradan gelir |
| Dosyaya fiyat eklemek | Üçüncü fiyat kopyası (K128 ihlali) | Fiyat API'den |
| Frontend'i backend'den önce yayınlamak | Yeni premium şablon bir süre korumasız | Sıra: önce backend (K148, `YAYIN-SIRASI.md`) |
| `--check`'i hata basıp `0` döndürecek şekilde değiştirmek | Kapı sessizce geçer | `self::FAILURE` |

## 5. Kendin dene

```powershell
php artisan plans:export --check; $LASTEXITCODE     # 0, "Plan kurallari sozlesmesi guncel."
php artisan test --filter=PlanRulesContractTest
```

**Kasten kır (mutant):** `config/davetkart.php`'de `preset_tiers` içinden bir satırı sil.

```powershell
php artisan plans:export --check; $LASTEXITCODE     # 1, "... guncel degil."
php artisan test --filter=the_plan_rules_contract_is_up_to_date   # kırmızı
```

Satırı geri koy; ikisi de yeşile döner. `git diff --stat` boş olmalı.

**Kural gerçekten değişecekse** (örnek: yeni bir premium şablon):

```powershell
# 1. config/davetkart.php → preset_tiers'a satırı ekle
php artisan plans:export
Copy-Item contracts\plan-rules.json ..\davetkart-frontent\src\contracts\plan-rules.json
# 2. frontend: src/data.ts'te şablona minimumTier: 'gold' yaz
#    npm run verify:plans   → yeşil olmalı
# 3. Yayın sırası: önce backend, sonra frontend
```

Tinker ile komutun okuduğu değerlere bak:

```powershell
php artisan tinker --execute="dump(count(config('davetkart.preset_tiers')), App\Enums\SubscriptionTier::Standart->rsvpLimit());"
```

## 6. Sözlük

| Terim | Anlamı |
|---|---|
| **Sözleşme dosyası** | İki sistemin üzerinde anlaştığı, makine okunabilir kural listesi |
| **Tek kaynak (single source of truth)** | Bir bilginin değiştirildiği tek yer; diğer yerler ondan türer |
| **Bayatlık (drift)** | Üretilen kopyanın kaynağından geri kalması |
| **Deterministik çıktı** | Aynı girdiden her zaman bayt bayt aynı çıktı |
| **Fail fast** | Ucuz denetimi öne alıp hatayı erken göstermek |
