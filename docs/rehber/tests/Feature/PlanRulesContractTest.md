# `tests/Feature/PlanRulesContractTest.php`

> **Faz:** 18, adım 18.1.2 (`OPS-01` · K146)
> **Sınanan:** [`ExportPlanRules.md`](../../app/Console/Commands/ExportPlanRules.md) ·
> `contracts/plan-rules.json`

Veritabanına dokunmaz (`RefreshDatabase` yok); 18 test bir saniyenin altında koşar.

## Ne kilitleniyor?

| Grup | Test | Soru |
|---|---|---|
| Depodaki dosya | `the_plan_rules_contract_is_up_to_date` | Config değişti de sözleşme yenilenmedi mi? |
| | `the_committed_contract_carries_no_price` | Dosyaya fiyat sızdı mı (K128)? |
| `--check` | `check_fails_when_a_rule_changes_…` (6 veri seti) | Her kural türü bayatlığı yakalatıyor mu? |
| | `check_fails_when_a_premium_preset_row_is_removed` | `OPS-01` senaryosu: bir premium satır kayboldu |
| | `check_passes_right_after_an_export` | Taze dosya yeşil mi? |
| | `check_fails_when_the_file_is_missing` · `check_never_writes_the_file` | Dosya yoksa kırmızı; `--check` yazmaz |
| Çıktı | `the_export_writes_sorted_rules_without_price_or_timestamp` | Biçim bayt bayt sabit mi? |
| | `the_export_is_deterministic` | Config sırası ve saat çıktıyı değiştiriyor mu? |
| | `the_tier_order_follows_the_configured_rank` | Plan sırası `rank`'ten mi geliyor? |
| Bozuk config | `an_unknown_tier_in_the_config_fails_loudly` (3 veri seti) | Tanınmayan plan adı sözleşmeye yazılıyor mu? |

## Neden böyle yazıldı?

**İlk test bir kapıdır.** `the_plan_rules_contract_is_up_to_date` gerçek dosyaya, gerçek
config'e bakar. `composer check` aynı denetimi testlerden önce de koşar; bu test,
`php artisan test`'i tek başına çalıştıranı korur.

**Diğerleri geçici bir dosyayla çalışır.** `--path=storage/framework/cache/…` Git'in görmediği
bir klasördür (`.gitignore`: `*`); `tearDown` dosyayı siler. Depodaki sözleşmeye hiçbir test
yazmaz. Yazsaydı, yarıda kesilen bir test koşusu çalışma ağacını kirletirdi.

**Veri seti = kural türü.** `ruleChanges()` altı ayrı kuralı tek tek değiştirir: yeni premium
şablon, bir şablonun planı, bir modülün planı, LCV sınırı, plan sırası, logosuz yayın planı.
Komuta yeni bir alan eklenip `--check`'e bağlanmazsa hangi kuralın denetim dışı kaldığı
başarısız veri setinin adından okunur.

**Biçim testi tam metni karşılaştırır.** `assertSame($expected, File::get(…))` girintiyi,
anahtar sırasını ve sondaki satır sonunu da kilitler. Frontend kopyası bu baytlarla
karşılaştırıldığı için biçim de sözleşmenin parçasıdır. Test config'i kendi küçük örneğiyle
doldurur; gerçek iş kuralı değişince bu test kırılmaz.

**`plans()` yardımcısı.** `$this->artisan()` `PendingCommand|int` döner; PHPStan (seviye 8)
`int` üzerinde metot çağrısına izin vermez. Yardımcı tipi `assertInstanceOf` ile daraltır
(`PriceCampaignCommandsTest::command()` ile aynı).

## Öldürdüğü mutantlar

| Mutant | Kırmızıya dönen |
|---|---|
| `config/davetkart.php`'den bir `preset_tiers` satırını silmek | `the_plan_rules_contract_is_up_to_date` + `plans:export --check` (18.1'de elle denendi) |
| Komutta `ksort`'u kaldırmak | `the_export_is_deterministic`, biçim testi |
| Komuta `generatedAt` eklemek | Biçim testi, `the_export_is_deterministic` (`travel(3)->days()`) |
| `check()`'te `FAILURE` yerine `SUCCESS` dönmek | `check_fails_…` testlerinin hepsi |
| `--check` kipinde dosyayı yazmak | `check_never_writes_the_file` |
| Sözleşmeye `price` eklemek | Biçim testi, `the_committed_contract_carries_no_price` |

Yalnızca ilk satır elle denendi; diğerleri testin yapısından okunan beklentidir.

## Kendin dene

```powershell
php artisan test --filter=PlanRulesContractTest
```
