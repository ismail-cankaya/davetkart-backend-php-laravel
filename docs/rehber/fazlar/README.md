# `docs/rehber/fazlar/` — Faz Özetleri

Her faz tamamlandığında buraya bir özet yazılır (kural **B3**).

Dosya bazlı kılavuzlar (`docs/rehber/app/...`, `docs/rehber/config/...`) *"bu
dosya neden böyle yazıldı"* sorusunu cevaplar. Buradaki faz özetleri ise
*"bu aşamada ne hedefledik, ne kurduk, hangi kurallar doğdu"* sorusunu.

## İçerik

| Faz | Konu | Durum | Özet | Elle doğrulama |
|---|---|---|---|---|
| **0** | Zemin ve kalite kapıları | ✅ | [FAZ-0.md](FAZ-0.md) | — |
| **1** | İlk endpoint + hata zarfı | ✅ | [FAZ-1.md](FAZ-1.md) | — |
| **2** | Auth özellik dilimi | ✅ | [FAZ-2.md](FAZ-2.md) | [13 adım](FAZ-2-ELLE-DOGRULAMA.md) |
| **3** | Invitation CRUD | ✅ | [FAZ-3.md](FAZ-3.md) | [betik](FAZ-3-ELLE-DOGRULAMA.md) |
| **4** | Public davetiye + cache | ✅ | [FAZ-4.md](FAZ-4.md) | [15 adım](FAZ-4-ELLE-DOGRULAMA.md) |
| **5** | RSVP / LCV | ⚠️ kod ✅ · `composer check` ✅ · **elle doğrulama bekliyor** | [FAZ-5.md](FAZ-5.md) | 🔴 [16 adım](FAZ-5-ELLE-DOGRULAMA.md) |
| **6** | Media | ⚠️ kod ✅ · `composer check` ✅ · **elle doğrulama bekliyor** | [FAZ-6.md](FAZ-6.md) | 🔴 [18 adım](FAZ-6-ELLE-DOGRULAMA.md) |
| **7** | Ödeme ve paywall | ⚠️ kod ✅ · `composer check` ✅ · **elle doğrulama bekliyor** | [FAZ-7.md](FAZ-7.md) | 🔴 [20 adım](FAZ-7-ELLE-DOGRULAMA.md) |
| **8** | AI asistan + iletişim | ⚠️ kod ✅ · `composer check` ✅ · **elle doğrulama bekliyor** | [FAZ-8.md](FAZ-8.md) | 🔴 [betik](FAZ-8-ELLE-DOGRULAMA.md) |
| **9** | Üretim hazırlığı | ⚠️ kod ✅ · `composer check` ✅ (238 test) · **elle doğrulama bekliyor** | [FAZ-9.md](FAZ-9.md) | 🔴 [22 adım](FAZ-9-ELLE-DOGRULAMA.md) |
| 9+ | Faz 9 sonrası eklemeler (17–21 Eylül) | ⚠️ kod ✅ · `composer check` **kayıt yok** | `docs/07` §4 Faz 9 | — |
| **11** | Hediye kodu (backend) | ⚠️ kod ✅ · `composer check` ✅ 684 test (**bulut çalışma alanında**, bu makinede değil) · frontend ✅ (`npm run check` yeşil) · **elle doğrulama bekliyor** | [FAZ-11.md](FAZ-11.md) | 🔴 [16 adım](FAZ-11-ELLE-DOGRULAMA.md) |
| **12** | Shopier ile kartla ödeme | ⚠️ kod ✅ · `composer check` ✅ 781 test (**bulut çalışma alanında**) · frontend `npm run check` ✅ · 🔴 **§A boş, gerçek Shopier'e karşı hiç sınanmadı** | [FAZ-12.md](FAZ-12.md) | 🔴 [9 adım, canlı](FAZ-12-ELLE-DOGRULAMA.md) |
| **13** | Fiyat API'si, indirimler (ilk yayın %60, kampanya), ödeme yöntemi anahtarı | ⚠️ kod ✅ · `composer check` ✅ 865 test (**bulut çalışma alanında**) · frontend `npm run check` ✅ · **elle doğrulama bekliyor** | [FAZ-13.md](FAZ-13.md) | 🔴 [10 adım](FAZ-13-ELLE-DOGRULAMA.md) |
| **14** | Hesap ayarları: Planlarım (hak sayacı), iptal/iade talebi, profil, parola, oturumlar, e-posta değişikliği · yanmış hak açığı kapandı | ⚠️ kod ✅ · `composer check` ✅ 958 test (**bulut çalışma alanında**) · frontend `npm run check` ✅ · **elle doğrulama bekliyor** | [FAZ-14.md](FAZ-14.md) | 🔴 [15 adım](FAZ-14-ELLE-DOGRULAMA.md) |
| **15** | Yeni şablon kimlikleri (18 konsept + 7 "Yedi Davetiye"): backend kod değişikliği gerekmedi, sözleşme testi eklendi | ⚠️ test yazıldı · `composer check` **koşmadı** (bulut alanında PHP 8.3) | [FAZ-15.md](FAZ-15.md) | — |
| **18** | Denetim düzeltmeleri (`AUD-2026-11-12`, altı 🟠 bulgu). 18.1 `OPS-01`: plan kuralları sözleşmesi (`plans:export`), dağıtım sırası, sürüm etiketi. 18.2 `OPS-02`: gece `pg_dump` yedeği (ayrı S3 bucket'ı, yalnızca yazabilen kimlik), geri yükleme provası. 18.3 `SEC-01`: ilk yayın indirimi tek açık siparişe (kullanıcı kilidi + kısmi UNIQUE index + ödeme anında son kapı; aynı niyet aynı siparişi döndürür). 18.4 `SEC-02`: yayın yapmış hak iade edilmez (`orders.first_published_at` damgası, `used` engeli, onayda yeniden sınama, `refunds:list` "Yayın" sütunu). 18.5 `SEC-03`: Shopier `callback` kipi üretimde kurulmaz (503), callback ucu sürücü kurulamazsa hata sayfasına yönlendirir, üretimde tutarsız `paid` reddedilir. 18.6 `SEC-04`: SPA güvenlik başlıkları Nginx snippet'inde (API dışındaki her `location`'da `include`; tam CSP rapor kipinde), `install.sh` (sına → reload → kırmızıda geri al), duman testi | ⚠️ kod tamam (6/6) · **`v1.1.0` etiketi bekliyor (`composer audit` kırmızı, `SEC-07`)** · ayrıntı ve B7 tabloları dosyada · 18.2'nin sunucu kurulumu ve üretim provası, 18.3 ve 18.4'ün yerel göçü ve tarayıcı denemesi, 18.5'te sunucudaki `SHOPIER_CONFIRMATION` değeri, 18.6'nın sunucuda kurulumu ve duman testi koşmadı. Faz 16–17'nin kaydı yok (`ARC-02`) · → 20 Kasım: `SEC-07` Faz 19.1'de ele alındı (lock temiz); `vendor` + kapılar ve `v1.1.0` docs `phases/faz-19/FAZ-19-SEC-07-COMMIT-KOMUTLARI.md` §0, §5 | [FAZ-18.md](FAZ-18.md) | [FAZ-18.md](FAZ-18.md) §18.1–§18.5 "Elle doğrulama" |
| **19** | Denetimin orta öncelikli bulguları (`AUD-2026-11-12`, 28 🟡; `TST-01` Faz 18.1'de kapandı). 19.1 `SEC-07`: `composer audit` yeşil (`composer.lock`: Laravel 13.43, Symfony 8.1.10/8.1.11; majör yok, K179), Shopier callback adresi istek host'undan değil `APP_URL`'den (K180), yayın adayı = `check` + `audit` (K181). 19.0 ve 19.2–19.27 (üç paralel hat): karar indeksi denetimi, Faz 16–17 kaydı, sağlık ucu, parola politikası, hız sınırı anahtarları, kilit testleri, misafir yükleme bütçesi, e-posta doğrulaması, saklama yedek kuralı, kendi fontlarımız, gizlilik metni, paylaşım önizlemesi, paket bölme, taslak yaşam döngüsü, erişilebilirlik (K182–K192, K220–K232, K250–K258); 19.28 `ARC-03`: K18 cırcırı `k18:check` (K259) | ⚠️ 28/28 kod ✅ ve birleşti (25 Kasım) · tam takım 1271/1271 ✅ · `composer audit` ✅ · frontend check/e2e/perf ✅ · ~~`composer check` 🔴 PHPStan iç hatası (#11)~~ 25 Kasım'da kapandı · 26 Kasım: `composer audit` kırmızı (`SEC-51`) → Faz 20.2 · `v1.2.0` bekliyor | [FAZ-19.md](FAZ-19.md) | [FAZ-19.md](FAZ-19.md) her bölümün "Elle doğrulama" / "İsmail'e kalan" kısmı |
| **20** | Faz 19 sonrası kritik hatalar (`AUD-2026-11-26`): yerel şema 4 göç geride → Hesap Ayarları 500 (ortam eşitlendi). 20.1 `OPS-10`: `/api/health` `schema` kontrolü (K263). 20.2 `SEC-51`: Symfony 8.1.12 (`composer audit` yeşil). 20.3 `OPS-11`: `composer sync`. 20.6 `OPS-13`: `composer setup` `APP_KEY`'i ezmez. 20.4 `OPS-06` ↑ (deploy penceresi) karar bekliyor | ⏳ 26 Kasım 2026 · ayrıntı ve kapılar dosyada | [FAZ-20.md](FAZ-20.md) | [FAZ-20.md](FAZ-20.md) "Elle doğrulama" |
| **21** | Ertelenmiş düşük bulgular ve açık kararlar (`AUD-2026-11-12` 🟢/💡 + `AUD-2026-11-26` §4b), tek oturum. 21.1 `SEC-16` hesap başına giriş freni (K265) · 21.2 `SEC-15` kabul edilen risk (K266) · 21.3 `FE-58` (K267) · 21.4 `OPS-08` `X-Request-Id` (K268) · 21.5 `OPS-07` FPM + OPcache (K269) · 21.6 `ARC-06` üç hata kodu silindi (K270) · 21.7 `FE-08` (K271) · 21.9 `TST-05` (K272) · 21.10 `TST-06` `composer test:parallel` · 21.11 `TST-03` kapsam %95,9 (K273) · 21.12 `ARC-10` görsel işi bölündü · 21.13 `SEC-18` plan (K274) · 21.18 `ARC-07` (K275) | ✅ 18/18 (bloke yok; 21.13–21.15, 21.17 plan/liste) · `composer check` 1354/1354 ✅ · `composer audit` ✅ · frontend check/e2e ✅ · sunucu adımları ve CI koşusu koşmadı | [FAZ-21.md](FAZ-21.md) | [FAZ-21.md](FAZ-21.md) her maddenin "Elle doğrulama" kısmı |
| **22** | Yayın öncesi son faz: eksikler ve düzenlemeler (madde listesi açık). 22.1 e-posta doğrulaması satın almayı ve yayını engellemez, panelde hatırlatma bandı; ilk yayın indirimi doğrulama ister (K276) | ⏳ 22.1 kod ✅ · `composer check` **koşmadı** (bulut alanında PHP 8.3) · frontend `npm run check` ✅ · e2e koşmadı | [FAZ-22.md](FAZ-22.md) | [FAZ-22.md](FAZ-22.md) §22.1 "Elle doğrulama" |

> 🔴 23 Eylül 2026: backend "bitti" gözden geçirmesi →
> `davetkart-docs: audits/2026-09-23-backend-review/GOZDEN-GECIRME-RAPORU.md`

## Her özette bulunacaklar

1. **Fazın amacı** — tek cümle + neden bu sırada
2. **Öğrenme hedefleri** — soru → kılavuz eşlemesi
3. **Hedefler ve sonuçlar** tablosu
4. **Yazılan dosyalar** ve kılavuz bağlantıları
5. 🔴 **Kurulan kurallar** — fazın kalıcı çıktısı
6. **Alınan kararlar** ve geçersiz kılınanlar
7. **Bir sonraki faza devir**

> ⚠️ **Faz 5 istisnadır.** Kodu yazıldı ama `composer check` hiç koşmadı
> (gerekçe: [FAZ-5.md](FAZ-5.md) §0). Durum alanı bilerek "tamamlandı" değil
> "doğrulanmadı" yazıyor — **B7**: faz özetindeki durum, gerçekten koşan bir
> komuta dayanır. Faz 5 ancak elle doğrulama betiği yeşil bittiğinde kapanır.

> Özetler **kuralları** kaydeder, süreç anlatmaz. "Şunu deneyip vazgeçtik"
> türü anlatım buraya girmez; yalnızca **yürürlükteki karar ve gerekçesi** yazılır.
> Geçersiz kılınan kararlar tek satırla, yerine geçenle birlikte listelenir.
