# PHP-LARAVEL-SETUP — Faz 8 Yaması

> **Bu dosya nedir:** `claude/PHP-LARAVEL-SETUP.md` (master) Faz 4 sonunda
> yazıldı. Faz 5, 6, 7 ve **8**'in kararları ayrı yama dosyalarında birikti.
> Master'a işlenene kadar **bir çelişki görürsen daha yeni olan kazanır**:
> EK dosyaları > master.
>
> **Tarih:** 7 Eylül 2026 · **Kapsam:** K72–K79 · Q1-Q4 · X1-X3 · L8 · C8 · B9 · dersler 56–59

---

## A. §7 Karar Kaydına eklenecek (K72–K79)

| # | Karar | Gerekçe | Durum |
|---|---|---|---|
| **K72** | Asistan ucu **auth'lu** | Her çağrı paradır; bir maliyet kontrolü harcamanın bir **kimliğe** yazılabilmesini gerektirir. Anonim çağrıda tek anahtar IP olurdu ve IP iki yönde birden başarısızdır: CGNAT'te çok geniş (meşru kullanıcı kapıda kalır), saldırgan için çok dar (IP döndürmek ucuz). Ticari modelde ücretsiz katman yok | ✅ Faz 8 |
| **K73** | Kota **veritabanında** sayılır (`assistant_usages`); `throttle:assistant` **ayrıca** durur | Cache kovası `cache:clear`/deploy/Redis restart ile sıfırlanır. Hız sınırı için kabul edilebilir, **fatura** için değil. L3: ikisi birbirinin yerine geçmez | ✅ Faz 8 |
| **K74** | Kota aşımı **429** + yeni kod `ASSISTANT_QUOTA_EXCEEDED` | K28'in ayırt edici sorusu: *bekleyerek aşılabilir mi?* LCV'de hayır (403), günlük bütçede **evet**. `RATE_LIMITED` yeniden kullanılmadı: "hızlısın" ile "hakkın bitti" aynı metni gösteremez | ✅ Faz 8 |
| **K75** | AI sağlayıcı hatalarının tamamı → **`PROVIDER_UNAVAILABLE` (503)** | K27'nin 502/503 ayrımı **tekrarlanmadı**: ayrım izleme alarmı içindi ve ödemede biz bir gateway'iz. Burada kullanıcının önündeki eylem her iki hâlde aynı; ikinci kod ölü sözleşme maddesi olurdu (ders 26) | ✅ Faz 8 |
| **K76** | İletişim ucu **`/api/public/contact`** (`/api/contact` değil) | **K12** fail-safe: auth'suz yüzeyin tamamı tek önekte. İlk istisna ikincisinin gerekçesi olur. Faz 6 (N1) ve Faz 7 (K65) aynı kararı vermişti. Frontend uyarlanacak — tek satır | ✅ Faz 8 |
| **K77** | `ip_hash` **`hash_hmac`**'e birleşti; ortak yer `app/Support/IpHasher` | FAZ-7 §9 madde 2 kapandı. Düz hash uzunluk-uzatma saldırısına açık; `ip_hash` hiçbir yerde karşılaştırılmadığı için değişim zararsız. `app/Support/` yeni bir katman değil, **katmansızlık** işareti | ✅ Faz 8 |
| **K78** | `ai.request.timeout_seconds` **10 → 6**; retry **yalnızca bağlantı hatasında** | 🔴 Eski değerler 15 sn kuralını çiğniyordu: 10 + 0.2 + 10 = **20.2 sn**. Yenisi ~12.6 sn. Cevap veren bir sağlayıcıyı tekrar çağırmak **parayı ikiye katlar** | ✅ Faz 8 |
| **K79** | `Jobs/SendRsvpNotification` **yazılmadı** | Bir bildirim e-postası, backend'in ilk kez **insan tarafından okunacak metin** üretmesidir — K20/K21'in dışında kalan ilk şey. Kanal + dil (`users`'ta dil kolonu yok) + politika + şablon dört ayrı **karar**. Bugün yazılsaydı `handle()` yer tutucu olurdu (ders 26 / K48) | ⬜ Ertelendi |

---

## B. §11 Kurallara eklenecek (Faz 8 · 10 kural)

### Yeni seri **Q** — kota ve maliyet

| # | Kural |
|---|---|
| **Q1** | Bir **maliyet kontrolü** ancak harcamanın bir **kimliğe** yazılabildiği yerde kurulabilir |
| **Q2** | Bir **para kontrolü cache'te durmaz** |
| **Q3** | Ücretli bir dış çağrının **bedeli çağrıdan ÖNCE** yazılır (fail-closed) |
| **Q4** | **Yenilenen** sınır 429, **kapasite** sınırı 403 |

### Yeni seri **X** — dış model çağrısı

| # | Kural |
|---|---|
| **X1** | **Sistem talimatı çağıranın parametresi olamaz** |
| **X2** | **200 yanıt, kullanılabilir yanıt demek değildir** |
| **X3** | Bir **sır URL'e konmaz** — URL'ler kopyalanır (log, proxy, APM, `Referer`) |

### Mevcut serilere eklenenler

| # | Kural |
|---|---|
| **L8** | Bir savunma katmanı, cevapladığı bir soru yoksa **kopyalanmaz, çıkarılır** |
| **C8** | **Zarf istisnası büyütülmez** (C2 istisnayı ad ad tanımlar) |
| **B9** | Bir savunmanın **karşı tarafta karşılığı yoksa** savunma kurulmamıştır |

> **Toplam kural sayısı: 137** (0:31 · 1:19 · 2:20 · 3:15 · 4:11 · 5:10 ·
> 6:11 · 7:10 · **8:10**)

---

## C. §13 Derslere eklenecek (56–59)

**56. 🔴 Bir maliyet kontrolü, kimliği olmayan bir çağrıda kurulamaz.** IP
aynı anda hem çok geniş hem çok dardır; sonuç meşru kullanıcı için sıkı,
saldırgan için gevşek bir sınırdır. Sözleşme çelişkisi "hangi taraf daha
kolay değişir" diye değil, **kuralı hangi taraf taşıyabilir** diye çözüldü.

**57. 🔴 "Cevap alamadım" ile "para harcanmadı" aynı şey değildir.** Bir
zaman aşımı, isteğin işlenmediğini söylemez. Bu yüzden bedel çağrıdan
**önce** yazılır; sezgisel olan ters sıra hata anında para sızdırırdı.

**58. 🔴 Bir test saatin kaçında koştuğuna göre yeşil ya da kırmızı
yanıyorsa, yanlış olan testtir.** K71 son tarihi davetiyenin saat dilimine
taşımıştı; testler hâlâ UTC ile kuruyordu. Günün 21 saati yeşil, 3 saati
kırmızı — ve ilk koşu tam o pencereye denk geldi. Ders 34'ün zaman
eksenindeki hâli.

**59. Bir katmanı kopyalamak savunmayı güçlendirmez.** Anlamsız bir katman
bakımda *"bu neden burada?"* diye silinir ve **gerekli olanı da beraberinde
götürür**. Doğru boyutlandırma kopyalamak değil **çıkarmaktır** (L8).

---

## D. §9 Teknik Duruma eklenecek

| | |
|---|---|
| Uç nokta | **21** |
| Test | **198** (163 + 35) — ⚠️ 35'i doğrulanmadı |
| Kural | **137** · Karar **79** · Ders **59** |
| Yeni klasör | `app/Services/Ai/` · `app/Support/` · `app/Actions/Assistant/` · `app/Actions/Contact/` · `app/Http/Requests/{Assistant,Contact,Concerns}/` |
| Yeni tablo | `assistant_usages` · `contact_messages` |
| Yeni hata kodu | `ASSISTANT_QUOTA_EXCEEDED` (429) — katalog **21** kod |

---

## E. §15 Açık Sorulara eklenecek

| # | Konu | Öneri |
|---|---|---|
| 1 | **Bildirim kanalı** (K79) | Kanal + dil + politika seçilmeden yazılmamalı. `users.locale` kolonu gerekebilir |
| 2 | `SubscriptionTier::label()` | Faz 8 de çağıran doğurmadı → **ders 26 gereği silinmeli** |
| 3 | Kotanın gün sınırı **UTC** | İstanbul'da 03:00'te yenileniyor. Doğru çözüm `users.timezone`; bugün eklemek okunmayan alan üretir |
| 4 | `contact_messages` okuma ucu | Yazan var, okuyan yok. Admin paneli Faz 9'da mı? |
| 5 | 🔴 Frontend `contact.ts` docblock'u | *"destek ekibine yönlendirilir"* — bugün böyle bir kanal **yok**. **B4**: dokümanda verilen söz, kodda karşılığı yoksa yalandır |
| 6 | 🔴 `ContactPage` honeypot alanı | Render edilmiyor → tuzak kurulmamış (**B9**) |

---

## F. 🔴 Master'a işlenme durumu

| Yama | Master'a işlendi mi |
|---|---|
| `-EK-FAZ-5.md` (K49–K53, L1-L4, dersler 42–47) | ❌ |
| `-EK-FAZ-6.md` (K54–K63, F1-F5, dersler 48–49) | ❌ |
| `-EK-FAZ-7.md` (K64–K71, M5-M8, W1-W3, dersler 50–55) | Kısmen (§7/§9/§11/§13) |
| **`-EK-FAZ-8.md` (bu dosya)** | ❌ |
