# `app/Services/Ai/NullProvider.php`

> **Faz:** 8, dosya 8.4 · **Arayuzu:** [`AiProvider.md`](AiProvider.md)
> **Desen:** Null Object Pattern

---

## 1. 🔴 `FakeGateway` ile ayni sey degil

Faz 7'nin sahte odeme surucusu **gercek is yapiyordu**: gercek HMAC
dogruluyor, gercek sozluk cevirisi yapiyordu; sahte olan yalnizca paraydi.
Gerekcesi netti — imza dogrulamasi uretimde ilk kez calismamaliydi.

Burada ayni sey **yapilamaz**, cunku taklit edilecek bir algoritma yok:

| | `FakeGateway` (Faz 7) | `NullProvider` (Faz 8) |
|---|---|---|
| Ne der | "Her seyi yap, yalnizca para alma" | "Hicbir sey yapma" |
| Taklit ettigi | HMAC + durum cevirisi (gercek kod) | — bir dil modeli taklit edilemez |
| Testteki degeri | Imza yolunu **gercekten** sinar | Ag acilmadigini garanti eder |

Bir "sahte Gemini" yazmak, uydurma bir cumle dondurmekten ibaret olurdu.
O yuzden bu sinif taklit etmeye **calismiyor**.

---

## 2. Sabit yanit neden Ingilizce ve neden "yapilandirilmamis" diyor?

```php
public const REPLY = 'The DavetKart assistant is not configured in this environment.';
```

Turkce bir karsilama cumlesi (*"Merhaba, nasil yardimci olabilirim?"*)
yazmak cazipti. Reddedildi: o metin **gercek bir yanittan ayirt edilemez**
olurdu ve gelistirici, asistanin calistigini sanarak saatlerce yanlis yerde
hata arardi. **Bir yer tutucu, yer tutucu oldugunu soylemelidir.**

`public` olmasi bilincli: testler bu sabite bakarak "hangi surucu bagli"
sorusunu sihirli string yazmadan dogrular.

---

## 3. Null Object Pattern ne kazandiriyor?

Alternatif su olurdu:

```php
// ❌ Her cagri yerinde tekrarlanan bir kontrol
if ($this->provider !== null) {
    $reply = $this->provider->reply($prompt);
} else {
    $reply = '...';
}
```

Eksik bir bagimliligi `null` ile temsil etmek, **her cagiraniya bir dal**
yazdirir. Bir nesne ile temsil etmek, cagri yolunu tek sekle indirir.
`RsvpQuotaResolver`'in `null` donmesi (=sinirsiz) ile karistirma: orada
`null` bir **degeri** degil bir **soruyu** gecersiz kiliyordu.

---

## 4. 🔴 Bu surucu sessiz bir varsayilan DEGILDIR

```php
// ❌ Reddedilen alternatif
default => $app->make(NullProvider::class),   // "anahtar yoksa buna dus"
```

Uretimde `GEMINI_API_KEY` unutuldugu gun asistan **sahte cevaplar**
dondururdu; kullanici bunu "kotu model" sanar ve hata aylarca gorunmezdi.
K70'in tam olarak yasakladigi sey. Bir yapilandirma hatasi **gurultulu**
olmalidir.

---

## 5. Kendin dene

```php
// php artisan tinker
config(['ai.default' => 'null']);
app()->forgetInstance(App\Services\Ai\AiProvider::class);
app(App\Services\Ai\AiProvider::class)->reply('merhaba');
// "The DavetKart assistant is not configured in this environment."
```

**Mutasyon denemesi (T16):** `REPLY` sabitini Turkce bir karsilama cumlesi
yap. Testler **yesil kalir** (`AssistantTest` sabite bakiyor, icerigine
degil) — ama gelistirici deneyimi bozulur. Bu, testin degil **kararin**
korudugu bir ozelliktir; B6 geregi burada yaziyor.

---

## 6. Sirada ne var?

**8.5 — `GeminiProvider`**: sir yonetimi, 15 saniye hesabi ve "200 gelmesi
cevap gelmesi demek degildir".
