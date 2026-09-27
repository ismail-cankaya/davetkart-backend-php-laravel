# `app/Console/Commands/NormalizeUserEmails.php`

> **Faz:** 10 — Sertleştirme, adım 10.14
> **Karar:** **D-5** (`İ→i`) · **K84** (bakım komutu önce elle)
> **Kapattığı bulgu:** test denetimi **K-3**, geçmişe dönük yarısı
> **Önce oku:** [`app/Support/EmailNormalizer.md`](../../Support/EmailNormalizer.md) ·
> [`ExpireStaleOrders.md`](ExpireStaleOrders.md) (`--dry-run` ve koşullu `UPDATE` deseni)
> **Test:** [`tests/Feature/MaintenanceTest.md`](../../../tests/Feature/MaintenanceTest.md) §6c

---

## 1. Neden bir komut? 10.13 yetmedi mi?

10.13'ten sonra **yeni** her yazım doğru biçimde kaydediliyor. Ama Faz 9'a kadar
`İsmail@…` ile kaydolmuş bir hesabın satırında hâlâ şu duruyor:

```
i̇smail.cankaya@gmail.com      ← i + U+0307, ekranda "ismail" gibi
```

Bu kullanıcı 10.13'ten sonra **daha da kötü** durumda:

| | Faz 9 | 10.13 sonrası, bu komuttan önce |
|---|---|---|
| `İsmail@…` ile giriş | ✅ 200 (iki taraf da aynı yanlış kuralı uyguluyordu) | ❌ 401: giriş artık `ismail@…` arıyor |
| `ismail@…` ile giriş | ❌ 401 | ❌ 401 |

Mutator yalnızca **yazarken** çalışır, eski satırlar kendiliğinden düzelmez. Bu
yüzden 10.13'ün yayına alındığı gün bu komut da koşulmalı (§5).

---

## 2. Ne yapar?

```
users:normalize-emails [--dry-run]
```

1. Her kullanıcının e-postasını `EmailNormalizer::normalize()`'dan geçirir.
   Sonuç farklıysa satır **aday** olur.
2. Adayları ikiye ayırır: **yazılabilir** ve **çakışan**.
3. Yazılabilirleri koşullu `UPDATE` ile düzeltir. Çakışanlara **dokunmaz**,
   tablo olarak raporlar.
4. Çakışma varsa **`FAILURE`** (1) ile, yoksa `SUCCESS` (0) ile biter.

### 2.1 Neden SQL'de U+0307 aranmıyor?

```php
// YAPILMADI
User::where('email', 'like', "%\u{0307}%")
```

Daha hızlı olurdu, ama kuralı **ikinci kez** yazmak olurdu (C3): biri
normalizer'da, biri bu sorguda. Yarın kural genişlerse sorgu eski kalır.
Komut bunun yerine her satırı normalizer'dan geçiriyor. Kural değişirse komut
değişmeden yeni kuralı uygular.

Bedeli: tablonun tamamı okunuyor. `lazyById(500)` ile 500'lük parçalarla
okunduğu için bellek sabit kalıyor. Yüz binlerce kullanıcıya kadar bu tek
seferlik bir komut için sorun değil.

### 2.2 🔴 Çakışma: kod karar vermez

İki tür çakışma var:

| Tür | Örnek | Neden yazılmaz |
|---|---|---|
| Hedef adres **başka bir hesapta** | `#7 i̇smail@…` → `ismail@…`, ama `#12 ismail@…` zaten var | K-3'ün 3. adımı Faz 9'da ikinci bir hesap açıyordu. Hangisi kalacak? Davetiyeler, ödenmiş sipariş hangisinde? |
| İki aday **aynı hedefe** düşüyor | `#7 i̇smail@…` ve `#9 İsmail@…` (mutator'ı atlayan bir yazım) | Aynı soru, iki bozuk satırla |

İkisinde de UNIQUE kısıtı ikinci yazımı zaten reddederdi, ama asıl mesele o
değil: **hangi hesabın yaşayacağı bir iş kararı**. Birleştirme (davetiyeleri,
siparişleri taşıma) bu komutun işi değil. Komut satırları gösterir, elle
karar verilir.

Bir çakışma diğerlerini **rehin almaz**: aynı koşudaki çakışmayan satırlar yine
düzeltilir.

### 2.3 Neden çakışmada `FAILURE`?

Komut işini *kısmen* yaptı. Çıkış kodu 0 olsaydı bir betik ya da CI adımı onu
*"tamam"* sayardı. 1, *"bir insanın bakması gereken bir şey kaldı"* demek.
Aynı kural `--dry-run`'da da geçerli: prova, gerçek koşunun sonucunu önceden
söyler.

### 2.4 Koşullu `UPDATE` (`ExpireStaleOrders` deseni)

```php
User::query()
    ->whereKey($change['id'])
    ->where('email', $change['from'])   // okunduğu andan beri değişmediyse
    ->update(['email' => $change['to']]);
```

Satır okunduktan sonra değiştiyse `UPDATE` 0 satır etkiler, üstüne yazılmaz.
Değer zaten normalize, bu yüzden mutator'ı atlayan builder `update()` sorun değil.

Okuma ile yazma arasında biri **aynı adresle kaydolursa** UNIQUE kısıtı patlar.
Komut bu hatayı yakalar ve satırı çakışma listesine *"yazım sırasında adres
alındı"* nedeniyle ekler. Dar ama gerçek bir yarış.

### 2.5 🔴 Görünmezi görünür kılmak

```
+-----------+--------------------------------+--------------------------+
| Kullanici | Simdiki                        | Olacak                   |
+-----------+--------------------------------+--------------------------+
| 21        | i\u0307smail.cankaya@gmail.com | ismail.cankaya@gmail.com |
+-----------+--------------------------------+--------------------------+
```

`i̇smail@…` ile `ismail@…` terminalde **aynı** görünür. Ham değer basılsaydı
operatör *"değişmeyen bir şeyi değiştiriyor"* sanırdı. `visible()` değeri JSON
kaçışıyla basar: ASCII dışındaki her karakter `\uXXXX` olarak görünür.
**Operatör neyin değiştiğini görmeden bir satıra dokunmamalı.**

> Yan etki: Türkçe harfler de kaçışlı görünür (`ş` → `\u015f`). *"Olacak"*
> sütunu kaçışsız; normalize değerde U+0307 kalmadığı için okunur.

---

## 3. Neden zamanlayıcıda değil? (K84)

Bakım komutu önce elle, `--dry-run` ile koşulur. Bu komut ayrıca **tek
seferlik**: 10.13'ten sonra yeni bozuk satır oluşmuyor, dolayısıyla düzenli
koşmasının bir anlamı yok. Kodda kalması zararsız: idempotent, ikinci koşu
hiçbir satır bulmaz.

---

## 4. Bu komutun kapatmadıkları (B6)

| Kapatmaz | Neden / nerede |
|---|---|
| Çakışan hesapların birleştirilmesi | İş kararı. Komut yalnızca raporlar |
| Okuma-yazma yarışının **testi** | `where('email', eski)` koşulunu silen mutasyon hayatta kalıyor. Yarış tek süreçli testte kurulamıyor (**T15**) |
| `password_reset_tokens` (e-posta birincil anahtar) | Bugün kullanılmıyor. Dilim D'de parola sıfırlama gelince, komut ondan **önce** koşulmuş olmalı; yoksa eski adresle açılmış bir sıfırlama kaydı yetim kalır |

---

## 5. Yayına alma sırası

```
1. 10.13'ü deploy et                         (yeni yazımlar doğru)
2. php artisan users:normalize-emails --dry-run
3. Çıktıyı oku: düzeltilecekler + çakışmalar
4. php artisan users:normalize-emails
5. Çıkış kodu 1 ise çakışma tablosundaki hesaplar için elle karar
```

1 ile 4 arasındaki süre kısa tutulmalı: o arada `İ` ile kaydolmuş eski
kullanıcılar giriş yapamaz (§1'deki tablo).

---

## 6. Kendin dene

```powershell
php artisan users:normalize-emails --dry-run
```

Geliştirme veritabanında `İ` ile kaydolmuş bir hesap yoksa *"0 e-posta
düzeltilecek"* görürsün. Bozuk bir satırı elle kurmak için (**yalnızca yerelde**):

```powershell
php artisan tinker
```

```php
$u = App\Models\User::factory()->create();
DB::table('users')->where('id', $u->id)->update(['email' => "i\u{0307}smail.deneme@ornek.test"]);
```

Sonra `--dry-run` ile `i\u0307smail.deneme@…` satırını görmelisin.

---

## 7. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **İdempotent** | İkinci kez çalıştırıldığında hiçbir şey değiştirmeyen işlem |
| **Çıkış kodu** | Komutun işletim sistemine döndüğü sayı; 0 = başarılı, 0 dışı = bir şey ters gitti |
| **Koşullu `UPDATE`** | `WHERE`'inde *"okuduğum değer hâlâ bu mu?"* koşulu taşıyan güncelleme; araya giren değişikliğin üstüne yazmaz |
