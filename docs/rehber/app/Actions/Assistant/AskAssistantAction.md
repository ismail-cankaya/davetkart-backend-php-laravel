# `app/Actions/Assistant/AskAssistantAction.php`

> **Faz:** 8, dosya 8.10 · **Kurallar:** L1 · L3 · L7 · E2 · E9 · B6

---

## 1. Katmanli savunma — ve burada "pahali" mecaz degil

```
0. auth:sanctum        rota;  kimliksiz istek buraya HIC gelmez
1. throttle:assistant  rota;  cache sayaci          (mikrosaniye)
2. uzunluk siniri      Request; tek strlen          (mikrosaniye)
3. GUNLUK KOTA         burada;  iki SQL             (milisaniye)
4. saglayici cagrisi   burada;  ag + PARA           (saniyeler)
```

**L1:** her katman, kendinden sonrakinin maliyetini haklı cikaracak kadar
ucuz olmali. Faz 5'te en pahali katman bir `SUM()` sorgusuydu; burada
gercek para.

---

## 2. 🔴 Neden kota ONCE dusuluyor, cagri SONRA yapiliyor?

Sezgisel olan ters siraydi: cagri basarisizsa kullanicinin hakki yanmasin.
Reddedildi.

| Sira | Hata anında ne olur |
|---|---|
| Once cagri, sonra sayac | Sayac yazilirken hata olursa saglayici **zaten faturaladi**, biz hic saymadik → **para sizar** |
| **Once sayac, sonra cagri** | Cagri patlarsa kullanici **bir mesaj** kaybeder → **para sizmaz** |

Belirleyici gercek: bir **zaman asimi**, istegin islenMEDIGI anlamina
gelmez. Gemini istegi almis, uretmis ve faturalamis olabilir; biz yalnizca
cevabi zamaninda alamamis olabiliriz. *"Cevap alamadim"* ile *"para
harcanmadi"* ayni sey degildir.

Bu, **L7**'nin ("dis servis transaction'a dahil degildir; geri alinamayan
is en sona") kota eksenindeki tamamlayicisi: geri alinamayan is en sonda,
ama **bedeli en basta yazilir**.

**B6 — kabul edilen bedel:** saglayici cokerse kullanici gunluk hakkindan
bir mesaj kaybeder. Telafi (`decrement`) **bilerek yazilmadi**: ne
faturalandigimizi bilemeyiz ve telafi, kapatilmis bir yarisi yeniden acar.

---

## 3. 🔴 Check-then-act yok — kontrol ve yazma tek deyimde

Faz 5'in kota yarisi satir kilidiyle cozulmustu. Burada kilide bile gerek
kalmiyor:

```sql
UPDATE assistant_usages
   SET message_count = message_count + 1, updated_at = ?
 WHERE user_id = ? AND usage_date = ? AND message_count < ?
```

Veritabani bu deyimi **atomik** uygular. Kota doluysa `WHERE` hicbir satira
uymaz ve **etkilenen satir sayisi 0** doner — reddin kaniti budur.

Uc adimli hali (`oku → karsilastir → yaz`) es zamanli iki istekte ikisine
de "yer var" derdi (**E2**, Faz 2'de ogrenilen check-then-act yarisi).

### 3.1 Satirin varligini kim garanti ediyor?

```php
AssistantUsage::query()->insertOrIgnore([...]);   // 1
```

`insertOrIgnore`, `UNIQUE(user_id, usage_date)` ihlalini **hata
firlatmadan** yutar. Es zamanli iki "ilk mesaj"dan biri sessizce elenir —
benzersizlik `if` ile degil **veritabani kisitiyla** korunur (**E2**).

Iki deyim de idempotan: birincisi satiri var eder, ikincisi kosullu artirir.
Aralarina baska bir istek girse bile sonuc dogru kalir.

---

## 4. Neden `throttle` kovasi yetmiyor? (L3)

| | Hiz siniri (`throttle:assistant`) | Kota (`assistant_usages`) |
|---|---|---|
| Sorusu | "Ne siklikta?" | "Bugun kac tane?" |
| Nerede durur | Cache (file/Redis) | **Veritabani** |
| `cache:clear` sonrasi | Sifirlanir | **Durur** |
| Amaci | Kotuye kullanim | **Fatura** |

Bir para kontrolu cache'e emanet edilmez. **L3** zaten soyluyordu: hiz
siniri ile kota birbirinin yerine gecmez.

---

## 5. Gun siniri hangi saat diliminde?

Uygulamanin saat diliminde — `config/app.php` → **UTC**.

**B6 — kapatMADIGI sey:** Istanbul'daki kullanici icin kota gece yarisi
degil **sabah 03:00'te** yenilenir.

Neden davetiyenin `timezone`'u (K71) kullanilmadi? Cunku o alan bir
**etkinligin** yerel saatini anlatir; sohbetin bir etkinligi yok.
Kullanicinin kendi dilimi ise hicbir yerde saklanmiyor (`users` tablosunda
boyle bir kolon yok). Ihtiyac dogarsa dogru cozum o kolonu eklemektir;
bugun eklemek hicbir yerden okunmayan bir alan uretirdi (**ders 26**).

---

## 6. Sik yapilan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Once cagri, sonra sayac | Hata aninda para sizar |
| 2 | `oku → karsilastir → yaz` | Es zamanli istekler kotayi asar |
| 3 | `insertOrIgnore` yerine `firstOrCreate` | UNIQUE ihlali exception olarak patlar |
| 4 | Kotayi `RateLimiter` ile tutmak | `cache:clear` butceyi sifirlar |
| 5 | Cagri hatasinda sayaci geri almak | Ne faturalandigi bilinemez; yaris yeniden acilir |
| 6 | Prompt'u tabloya yazmak | Sistemin en hassas metin deposu dogar (KVKK) |

---

## 7. Kendin dene

```php
// php artisan tinker
$u = App\Models\User::first();
config(['davetkart.assistant.daily_message_limit_per_user' => 2]);

$a = app(App\Actions\Assistant\AskAssistantAction::class);
$a->handle($u, 'merhaba');
$a->handle($u, 'merhaba');
$a->handle($u, 'merhaba');
// App\Exceptions\AssistantQuotaExceededException

App\Models\AssistantUsage::where('user_id', $u->id)->first()->message_count;  // 2
```

---

## 8. Terim sozlugu

| Terim | Anlami |
|---|---|
| **Check-then-act** | Once oku sonra yaz — arada baskasi girebilir |
| **Atomik deyim** | Veritabaninin bolunmeden uyguladigi tek ifade |
| **Idempotans** | Ayni islemi tekrarlamanin sonucu degistirmemesi |
| **Fail-closed** | Supheli durumda **kisitlayici** tarafa dusmek |
