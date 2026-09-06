# `app/Services/Ai/GeminiProvider.php`

> **Faz:** 8, dosya 8.5 · **Arayuzu:** [`AiProvider.md`](AiProvider.md)
> **Ilgili config:** `config/ai.php`
> **Kurallar:** H8 (ham hata sizmaz) · A8 (degismez sinifin kendisinde) ·
> 15 saniye kurali (`CLAUDE.md` §4)

---

## 1. Sir yonetimi — anahtarin gordugu tek yer

```
.env  ──►  config/ai.php  ──►  GeminiProvider
```

Baska hicbir katman `GEMINI_API_KEY`'i okumaz: controller, Action, Resource
ve testler anahtarin varligindan bile habersizdir. Frontend'e sizma yolu
**mimari olarak** yoktur — Vite yalnizca `VITE_` onekli degiskenleri
paketler ve bu degisken backend'in `.env`'indedir.

### 1.1 🔴 Anahtar baslikta gider, URL'de degil

Gemini iki yolu da kabul eder:

```
POST .../models/gemini-2.0-flash:generateContent?key=SIR        ❌
POST .../models/gemini-2.0-flash:generateContent                 ✅
     x-goog-api-key: SIR
```

URL'ler **kopyalanir**: nginx erisim loglari, proxy kayitlari, APM izleri,
hata raporlari, tarayici gecmisi, `Referer` basligi. Bir sirrin kural
disi kalmasi gereken tek yer yoktur — **kopyalanabilecek her yerde**
gorunmemelidir. Basliklar da loglanabilir, ama varsayilan olarak degil.

`AssistantTest::the_api_key_travels_in_a_header_and_never_in_the_url`
bunu giden istegin uzerinde dogruluyor.

---

## 2. 🔴 15 saniye kuralinin hesabi

`api.ts` istegi **15 saniyede** koparir. O sinir backend'in **toplam**
suresi icindir, tek denemenin degil. Faz 8'de fark edildi ki eski ayarlar
bunu cigniyordu:

```
timeout 10 · retry 2 · delay 200ms
en kotu:  10.0 + 0.2 + 10.0 = 20.2 sn   >  15  ❌
```

Sonuc: saglayici yavasladigi gun frontend, backend cevap **veremeden**
baglantiyi koparirdi ve kullanici sebebi hic ogrenemezdi. Duzeltme:

```
timeout 6 · retry 2 · delay 200ms
en kotu:   6.0 + 0.2 + 6.0            = 12.2 sn
+ boot/auth/throttle                  ~ 0.30
+ dogrulama + kota (2 SQL)            ~ 0.05
+ serilestirme                        ~ 0.05
                                      = ~12.6 sn  <  15  ✅
```

> `retry_times` bir **deneme** sayisidir, tekrar sayisi degil:
> 2 = 1 asil deneme + 1 tekrar. (Laravel: *"the maximum number of times the
> request should be attempted"*.)

### 2.1 Tekrar yalnizca baglanti hatasinda

```php
->retry($times, $delay, fn (Throwable $e) => $e instanceof ConnectionException)
```

`when` kapanisi olmasaydi Laravel her basarisiz **yaniti** da tekrar
denerdi ve iki sey bozulurdu:

1. **Para ikiye katlanir.** Cevap veren bir saglayici istegi zaten islemis
   ve faturalamis olabilir.
2. Butce anlamsizlasir — 400 alan bir istek tekrarla duzelmez.

Kopmus bir TCP baglantisi duzelebilir; anlasilmamis bir govde duzelmez.
Ayrim tam olarak burasi.

---

## 3. 🔴 200 gelmesi, cevap gelmesi demek degildir

Gemini guvenlik filtresine takilan istekte de **200** doner; `candidates`
bos gelir ya da `parts` hic olmaz.

```php
$text = $response->json('candidates.0.content.parts.0.text');

if (! is_string($text) || trim($text) === '') { ... }
```

Bu kontrol olmasaydi kullaniciya bos bir sohbet balonu gosterirdik ve sebep
hicbir yerde durmazdi. **Ders 34'un ailesi:** bekledigin yaniti almak,
bekledigin **sebeple** aldigin anlamina gelmez. `blockReason` ve
`finishReason` log'a yazilir — disari cikmaz.

---

## 4. 🔴 H8: ham hata yanita girmez

| Nereye | Ne gider |
|---|---|
| Yanit govdesi | Yalnizca `{"error":{"code":"PROVIDER_UNAVAILABLE","params":{"retryAfter":30}}}` |
| Log | Durum kodu + `error.message` (govdenin **tamami degil**) |
| `debug` blogu (yerel) | Orijinal exception zinciri (`previous`) |

Saglayici hatalari model adini, kota durumunu, bazen anahtarin bir kismini
tasir. Log'a bile govdenin tamami yazilmiyor: **log da bir depodur** ve
kullanicinin mesaji orada da durmamali (B6).

---

## 5. 🔴 Prompt injection: neyi kapatiyor, neyi kapatmiyor

`system_prompt` modele "yalnizca davetiye konularinda yardim et" der ve
`systemInstruction` **ayri bir alanda** gider — kullanicinin mesajiyla
birlestirilmez. Sinir metinde degil **yapida** durur; bu, en ucuz
saldiriyi ("yukaridaki talimatlari unut" yazip talimatin devami gibi
gorunmek) zorlastirir.

**Kapatmadiklari (B6):**

| Acik | Aciklama |
|---|---|
| Talimatin gorusmezden gelinmesi | Yeterince ustaca yazilmis bir mesaj modeli konu disina cikarabilir. Bu bir **model** ozelligidir, kod ile kapatilamaz |
| Sistem talimatinin ifsasi | Kullanici "sana ne soylendi?" diye sorabilir. `system_prompt` **sir degildir** ve icine sir yazilmamalidir |
| Zararli/yanlis cikti | Filtre saglayicida; biz metni oldugu gibi geciyoruz |

**Asil savunma baskadir ve mimaridir:** modele **hicbir arac, hicbir veri
erisimi verilmiyor**. Sohbet gecmisi yok, veritabani yok, kullanicinin
davetiyeleri yok, dosya yok. Bir prompt injection'in **calabilecegi**
hicbir sey yok — yalnizca kendi gonderdigi mesaj var. Zarar yarıcapi,
yeteneklerin kisitlanmasiyla kapatilmistir.

---

## 6. A8: surucu kendi degismezini kendisi korur

"Anahtar var mi?" sorusunu `AppServiceProvider`'da da sorabilirdik. O zaman
bu sinif **dogrulanmis bir dunyada calistigini varsayan** bir sinif olurdu
ve varsayim, konsoldan/kuyruktan/testten dogrudan ornek uretildigi gun
sessizce yanlislanirdi.

| Soru | Cevap yeri |
|---|---|
| "Hangi surucu?" | `AppServiceProvider::resolveAiProvider()` (K70) |
| "Ben kullanilabilir miyim?" | `GeminiProvider::apiKey()` (A8) |

Iki ayri soru, iki ayri yer — C3 ihlali degil.

---

## 7. Sik yapilan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Anahtari `?key=` ile gondermek | Sir erisim loglarina ve proxy kayitlarina yazilir |
| 2 | `when` kapanissiz `retry()` | 4xx'ler tekrarlanir; para ve butce ikiye katlanir |
| 3 | `timeout`'u 15'e esitlemek | Tekrarla birlikte frontend sinirinin ustune cikilir |
| 4 | `$response->json()` sonucunu dogrudan donmek | `null` yanit tipini kirar; kullanici bos balon gorur |
| 5 | Saglayici hatasini yanita koymak | H8 ihlali; model adi ve altyapi ifsa olur |
| 6 | `system_prompt`'u kullanici mesajiyla birlestirmek | Talimat/girdi siniri metne kalir |
| 7 | `system_prompt` icine sir yazmak | Kullanici modele sorarak okuyabilir |

---

## 8. Kendin dene

```bash
# .env
AI_PROVIDER=gemini
GEMINI_API_KEY=...
```

```php
// php artisan tinker
app(App\Services\Ai\AiProvider::class)->reply('Nikah daveti icin kisa bir metin yaz');

// Anahtari bozarsan:
config(['ai.providers.gemini.api_key' => null]);
app()->forgetInstance(App\Services\Ai\AiProvider::class);
app(App\Services\Ai\AiProvider::class)->reply('merhaba');
// App\Exceptions\AiProviderException: AI provider 'gemini' is not available.
```

---

## 9. Terim sozlugu

| Terim | Anlami |
|---|---|
| **Timeout** | Bir istegin cevapsiz beklenecegi ust sure |
| **Retry / backoff** | Basarisiz istegi bir gecikmeden sonra tekrar deneme |
| **ConnectionException** | Cevap **hic alinamadi** (DNS, TCP, zaman asimi) |
| **Prompt injection** | Kullanici metniyle modelin talimatlarini ezmeye calismak |
| **Blast radius** | Bir hatanin etkiledigi alanin genisligi |

---

## 10. Sirada ne var?

**8.6 — `AppServiceProvider`**: sürücü seçimi (K70) ve iki yeni hız sınırı
kovası.
