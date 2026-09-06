# `app/Services/Ai/AiProvider.php`

> **Kod dosyasi:** `app/Services/Ai/AiProvider.php` (arayuz)
> **Faz:** 8 — AI asistan ve iletisim, dosya 8.3
> **Uygulamalari:** [`NullProvider.md`](NullProvider.md) (8.4) ·
> [`GeminiProvider.md`](GeminiProvider.md) (8.5)
> **Karar:** **K8** — Strategy Pattern; **PaymentGateway'in ikizi**

---

## 1. Problem: bugun olmayan bir servise bugun ihtiyac var — ucuncu kez

Bu proje ayni sorunla ucuncu kez karsilasiyor ve her seferinde cevap ayni:

| | Faz 5 | Faz 7 | **Faz 8** |
|---|---|---|---|
| Eksik olan | Bir **veri kaynagi** (`orders` tablosu) | Bir **anlasma** (Iyzico) | Bir **anahtar** ve bir **maliyet** |
| Bugunku cozum | `RsvpQuotaResolver` | `PaymentGateway` | **`AiProvider`** |
| Yerine gecen | `config`'ten okuyan uygulama | `FakeGateway` | `NullProvider` |
| Degisecek olan | `AppServiceProvider`'daki bir satir | Ayni | Ayni |

Bir kaliba ucuncu kez basvurmak, onun bu projede **ogrenilmis bir refleks**
oldugunu gosterir. Ders 52 bunu soyluyordu: *bir arayuzun degerini yazdigin
gun degil, kaldirdigin uygulamanin maliyetiyle olcersin.*

---

## 2. Iki metot — ve neden yalnizca iki

```php
public function name(): string;
public function reply(string $prompt): string;
```

Yazilmayanlar en az yazilanlar kadar bilincli:

| Yazilmadi | Neden |
|---|---|
| `chat(array $history)` | Frontend gecmis gondermiyor; her surucu bugun bos gecerdi |
| `stream()` | Akis yaniti frontend'de yok; HTTP katmani da bugun buna hazir degil |
| `embed(string $text)` | Arama/benzerlik ozelligi yok |
| `setSystemPrompt()` | 🔴 Asagiya bak — bu **kasitli** bir eksiklik |

**Interface Segregation (I):** bir arayuze bugun kullanilmayan bir metot
koymak, tum uygulamalari bos govde yazmaya zorlar. `PaymentGateway`'de
`refund()` ayni sebeple yoktu.

---

## 3. 🔴 Sistem talimati neden parametre degil?

Cazip imza suydu:

```php
// ❌ Daha "esnek" gorunuyor
public function reply(string $systemPrompt, string $userText): string;
```

Reddedildi. Sebep esneklik degil **guven siniri**: `system_prompt` bir
guvenlik/konu sinirlamasidir (`config/ai.php`). Parametre olsaydi:

- Yarin yazilan ikinci bir cagiran onu bos gecebilirdi.
- Bir hata ayiklama kodu gecici olarak zayiflatir ve orada kalirdi.
- Testte kolayca "kapatilir" ve uretimdeki davranis test edilmemis olurdu.

Talimat surucunun **icinde** durdugunda hicbir cagri yolu onu zayiflatamaz.
Bu, `PaymentNotification`'in fikrinin aynisi: bir kurali *hatirlanmasi
gereken bir adim* olmaktan cikarip **gecilmesi zorunlu bir kapiya**
donusturmek (**H12**).

---

## 4. 🔴 Sohbet gecmisi neden tasinmiyor?

Frontend (`useAssistantChat.ts`) yalnizca son mesaji gonderiyor. Biz de
saklamiyoruz. Bu bir eksiklik gibi gorunur, uc kazanci vardir:

| Kazanc | Aciklama |
|---|---|
| **Maliyet sabit** | Token faturasi girdi uzunlugu ile buyur. Gecmis tasinsaydi 20. mesaj, 1. mesajin 20 katina mal olurdu |
| **Sizmayan veri** | Saklanmayan sohbet sizamaz. Bir `assistant_messages` tablosu sistemdeki en hassas metin deposu olurdu (KVKK) |
| **Karismayan baglam** | Bir kullanicinin gecmisi baska birine karisamaz — cunku ortada gecmis yok |

**Bedeli** durustce yazilir (**B6**): asistan "az once ne demistim?"
sorusunu cevaplayamaz. Gerektiginde dogru cozum, gecmisi **istemcide**
tutup gondermek degil (o zaman baglami saldirgan kurar), sunucuda kisa
omurlu bir oturum acmaktir — ve o, kendi fazini hak eden bir istir.

---

## 5. Neden `string` doner, DTO degil? (plandan sapma)

`CheckoutSession` bir sinifti cunku **uc** alan tasiyordu
(`providerRef`, `redirectUrl`, `expiresAt`) ve bir dizide bu alanlarin
adlari yazim hatasina acikti. Burada tasinan tek sey metin.

```php
// Bugun toren:
final readonly class AiReply { public function __construct(public string $text) {} }
```

Tek tuketici var (`AskAssistantAction`). Token sayaci veya `truncated`
bayragi gerektiginde donus tipini degistirmek **tek dosyalik** istir.
K15'in soyutlama butcesi: bir sarmalayici, sardigi seyden daha fazla bilgi
tasidigi gun yazilir.

---

## 6. Sik yapilan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `system_prompt`'u parametre yapmak | Konu sinirlamasi cagiranin kararina duser |
| 2 | Arayuze `history` eklemek | Baglami istemci kurar; talimat bir gorunuse doner |
| 3 | Action icinde `GeminiProvider` type-hint etmek | D ihlali; test aga cikar, CI kirilgan olur |
| 4 | Surucude `Order`/`AssistantUsage` yazmak | Sorumluluk karisir; sayac Action'in isi |
| 5 | Hata durumunda `''` dondurmek | Kullanici bos balon gorur, sebep hicbir yerde durmaz |

---

## 7. Kendin dene

```php
// php artisan tinker
$p = app(App\Services\Ai\AiProvider::class);
get_class($p);        // config'e gore GeminiProvider veya NullProvider
$p->name();

config(['ai.default' => 'openai']);
app()->forgetInstance(App\Services\Ai\AiProvider::class);
app(App\Services\Ai\AiProvider::class);
// App\Exceptions\AiProviderException: AI provider 'openai' is not available.
```

---

## 8. Terim sozlugu

| Terim | Anlami |
|---|---|
| **Strategy Pattern** | Ayni isin farkli yollarini ayri siniflara koyup tek arayuzun arkasina almak |
| **Seam (dikis yeri)** | Ileride degisecegi bilinen yerde bilerek birakilan ayrilma cizgisi |
| **Prompt** | Modele gonderilen metin |
| **System instruction** | Modele "nasil davran" diyen, kullanicidan gelmeyen talimat |
| **Token** | Modelin metni boldugu birim; fatura bunun uzerinden hesaplanir |

---

## 9. Sirada ne var?

**8.4 — `NullProvider`**: aga hic cikmayan surucu ve Null Object Pattern'in
`FakeGateway`'den farki.
