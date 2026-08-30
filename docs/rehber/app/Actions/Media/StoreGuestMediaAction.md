# `app/Actions/Media/StoreGuestMediaAction.php`

> **Kod dosyası:** `app/Actions/Media/StoreGuestMediaAction.php`
> **Faz:** 6 — Medya dilimi, dosya 6.14
> **Sistemin ikinci auth'suz yazma yolu.**
> **Kardeş dosyalar:** [`StoreUploadedMediaAction.md`](StoreUploadedMediaAction.md) ·
> [`../Rsvp/SubmitRsvpAction.md`](../Rsvp/SubmitRsvpAction.md) ·
> [`../Rsvp/ResolveOpenRsvpInvitationAction.md`](../Rsvp/ResolveOpenRsvpInvitationAction.md)

---

## 1. Faz 5'in sorusu, on kat maliyetle

Faz 5'te sisteme **tek** bir auth'suz yazma yolu açmıştık: misafirin LCV metni.
Bu ikincisi — ve aradaki fark boyut değil, **cins**:

| | LCV metni (Faz 5) | LCV medyası (bu dosya) |
|---|---|---|
| Yazılan şey | Bir veritabanı satırı | 🔴 Bir **dosya** |
| Tipik maliyet | Birkaç yüz bayt | 2 MB (foto) – 20 MB (video) |
| Nerede durur | PostgreSQL | Disk (bugün yerel, yarın S3) |
| Geri alınabilir mi | `DELETE` yeter | Satır **ve** dosya birlikte silinmeli |
| CPU maliyeti | ~0 | MIME analizi + kuyrukta yeniden kodlama |

Aynı tehdit modeli, **on kat maliyet**. Bu yüzden katmanlar aynı mantıkla ama
daha dikkatle dizildi.

---

## 2. Katmanlar (L1 — en ucuzdan pahalıya)

```
0. Hız sınırı     → rota katmanı (6.16)          — buraya hiç gelmez
1. Biçim / boyut  → StorePublicMediaRequest      — buraya hiç gelmez
─────────────── bu Action burada başlıyor ───────────────
2. Tür izni       → BU DOSYA                     — misafir galeriye yükleyemez
3. Hedef açık mı  → ResolveOpenRsvpInvitationAction (6.12)
4. Kota + saklama → StoreUploadedMediaAction (6.8)
```

### 🔴 Honeypot yok — ve olamaz (B6)

Faz 5'in **en ucuz** katmanı honeypot'tu: görünmez bir alan dolduruldu mu diye
bakan tek bir `if`. Bot, tek bir sorgu bile açtırmadan eleniyordu.

Burada o katman **yok**, çünkü honeypot bir **form alanının** doldurulmasına
bakar; burada gönderilen şey bir form değil bir **dosya**. Görünmez bir dosya
alanı diye bir şey yok.

Sonuç: bu uçta ilk gerçek engel **hız sınırı**. Faz 5'te hız sınırının önünde
bedava bir filtre vardı, burada yok — yani hız sınırı daha çok iş yapmak
zorunda ve 6.16'da kovaları buna göre seçeceğiz.

*Bir savunmanın neyi kapatmadığını yazmak, kapattığını yazmak kadar önemlidir.*

---

## 3. 🔴 2. katman: neden aynı kontrol iki kez?

```php
if (! $kind->isGuestUploadable()) {
    throw new LogicException("Media kind [{$kind->value}] cannot be uploaded by a guest.");
}
```

`StorePublicMediaRequest` bunu **zaten** eliyor:

```php
protected function allowedKinds(): array
{
    return MediaKind::guestUploadableValues();   // ['rsvp_photo', 'rsvp_video']
}
```

Yani `kind=gallery` gönderen bir istek buraya **hiç ulaşmaz** — 422 alır. O
hâlde neden tekrar?

### Çünkü doğrulama HTTP katmanına aittir, değişmez ise sınıfa

`CLAUDE.md` §1: *"Action'a gelen veri saf ve güvenilir kabul edilir."* Bu doğru
— **istek HTTP üzerinden geldiği sürece.**

Ama bir Action'ın çağrılabileceği tek yer bir controller değildir:

| Çağıran | FormRequest çalışır mı |
|---|---|
| `PublicMediaController` (bugün) | ✅ |
| Bir konsol komutu (`php artisan media:import`) | ❌ |
| Bir kuyruk işi | ❌ |
| Yeni bir uç — biri `StoreMediaRequest`'i yanlışlıkla kullanır | ❌ |

O gün `MediaKind::Gallery` geçirilse **misafir davetiyenin galerisine yazardı**
ve hiçbir şey uyarmazdı.

🔴 Ayrım şu: **doğrulama girdinin biçimini sorar, değişmez (invariant) sınıfın
anlamını korur.** "Bu Action misafir yoludur" cümlesi bir biçim kuralı değil,
sınıfın tanımıdır. Tanımı sınıfın kendisi korumalı.

> Faz 3'ün **30. dersi** buna sınır çiziyordu: *savunma kodu her yere değil,
> güven sınırına yazılır.* Burada güven sınırı Action'ın **girişi**, çünkü
> Action HTTP'den bağımsız olarak çağrılabilir bir birimdir.

### Neden `LogicException`, `RuntimeException` değil?

PHP'nin standart exception hiyerarşisinde bu ayrım anlamlıdır:

| Sınıf | Ne demek | Örnek |
|---|---|---|
| `LogicException` | **Programcı hatası** — kod yanlış yazılmış, çalışma anında düzeltilemez | Buraya `gallery` geçirmek |
| `RuntimeException` | **Çalışma anı durumu** — girdi/ortam beklenmedik | Kota dolu, dosya okunamadı |

`LogicException` `HasErrorCode`'u uygulamadığı için `ApiExceptionRenderer`'ın
`default` koluna düşer → **500**. Ve bu **doğru** cevaptır: ortada bir kullanıcı
hatası yok, bir kod hatası var. `422` deseydik var olmayan bir kullanıcı
hatasını raporlar ve gerçek sorunu gizlerdik.

> `MediaRequest::uploadedFile()` ve `strictestAllowedKind()` aynı refleksle
> yazılmıştı: **ulaşılmaması gereken yere ulaşıldıysa gürültülü patla.**

---

## 4. 3. katman: LCV metniyle **birebir aynı** üç koşul

```php
$invitation = $this->resolveOpenInvitation->handle($invitationId);
```

Bu satır 6.12 ve 6.13'ün bütün gerekçesi. `SubmitRsvpAction` **aynı** çağrıyı
yapıyor; yani "misafir bu davetiyeye yazabilir mi?" sorusunun cevabı sistemde
**tek bir yerde**.

Son tarih kontrolü burada özellikle kritik. Olmasaydı:

```
rsvp_photo:  200 dosya × 2 MB  =  400 MB
rsvp_video:  100 dosya × 20 MB = 2000 MB
                                 ─────────
                davetiye başına ≈ 2.4 GB — SÜRESİZ
```

Süresi dolmuş bir davetiyeye LCV **gönderilemezken** medya yüklenebilseydi,
sistemde açık bir disk doldurma yolu kalırdı. Ve bu, kuralı kopyalasaydık
**en kolay unutulacak** olan üçüncü kontroldü (**P1**).

---

## 5. 4. katman: kota — `forGuest()` kararını kim veriyor?

```php
return $this->storeMedia->handle($invitation, $kind, $file);
```

Bu Action kotayla ilgili **hiçbir şey söylemiyor**. Yine de kota aşımında
misafire dönen yanıt `params` **taşımıyor** (H9). Nasıl?

Karar `StoreUploadedMediaAction`'ın içinde, türe bakılarak veriliyor:

```php
throw $kind->isGuestUploadable()
    ? MediaQuotaExceededException::forGuest()      // params: {}
    : MediaQuotaExceededException::forOwner($limit); // params: {limit: 30}
```

🔴 Bu bilinçli: **"kim soruyor?" sorusunun cevabı türün kendisinde saklı.**
Alternatif, `handle()`'a bir `bool $isGuest` parametresi eklemekti — ve o
parametre bir gün yanlış geçirilirdi. Türden türetmek, çağıranın dürüstlüğüne
bağlı olmayan bir cevaptır.

> Faz 5'te `RsvpQuotaExceededException`'ın kurucusu **parametresizdi**, çünkü
> tek fırlatma yeri anonimdi. Burada iki okuyucu var, o yüzden iki adlandırılmış
> kurucu — ve `private __construct` ikisinin dışına çıkmayı imkânsız kılıyor.

---

## 6. Composition — ve neden controller'da iki çağrı değil?

```php
public function __construct(
    private readonly ResolveOpenRsvpInvitationAction $resolveOpenInvitation,
    private readonly StoreUploadedMediaAction $storeMedia,
) {}
```

Bu Action **ince**. Gövdesi bir `if` ve iki çağrı. Dürüst soru şu: gerçekten
gerekli mi?

Alternatif, controller'da iki satır yazmaktı:

```php
// PublicMediaController — alternatif
$invitation = $this->resolveOpen->handle($invitationId);
$media = $this->storeMedia->handle($invitation, $request->kind(), $request->uploadedFile());
```

Bu **çalışırdı** ve `if` kuralı gevşetildiği için yasak da değildi. Üç sebeple
yazılmadı:

1. **"Misafir medya yükler" tek bir eylemdir.** `CLAUDE.md` §1: *her sınıf tek
   bir eylemi gerçekleştirir.* İş akışını controller'da kurgulamak, onu HTTP
   katmanına bağlamak olurdu — konsoldan veya testten aynı akışı çalıştırmak
   için controller'ı taklit etmek gerekirdi.
2. **2. katman kaybolurdu.** Tür izni kontrolü controller'a yazılsaydı, tam da
   §3'te anlatılan boşluk geri gelirdi: FormRequest'siz her çağrı korumasız.
3. **Sıra bir güvenlik özelliğidir.** Katmanların sırası bir yerde **yazılı**
   olmalı; controller'a dağıtılınca kimse "hangi kontrol önce" sorusunu tek bir
   dosyaya bakarak cevaplayamaz.

🔴 Ama ince soyutlamanın gerçek bir riski var: **ölü soyutlama.** Eğer bu Action
yalnızca iki çağrıyı sıraya diziyor olsaydı, haklı olarak silinmesi gerekirdi.
Onu haklı çıkaran şey §3'teki değişmez — yani **kendi ürettiği bir garanti**.

---

## 7. Bu Action'ın YAPMADIKLARI

| Yapmadığı | Kim yapıyor |
|---|---|
| Dosya boyutu / MIME doğrulaması | `StorePublicMediaRequest` (`mimetypes:`, `max:`) |
| Görünürlük / modül / son tarih | `ResolveOpenRsvpInvitationAction` |
| Kota sayımı ve kilit | `StoreUploadedMediaAction` (**E9**) |
| Rastgele ad, içerikten MIME, kuyruk | `StoreUploadedMediaAction` |
| Hız sınırı | Rota middleware'i (6.16) |
| Medyayı bir LCV'ye **bağlamak** | 🔴 Henüz **kimse** — 6.17/6.18 |

Son satır önemli: bu Action bir dosya kaydeder ve kimliğini döner. O kimliğin
bir LCV'ye bağlanması **ayrı bir adım** ve o adım gelene kadar yüklenen her
dosya **yetim**dir.

---

## 8. ⚠️ Kapatılmamış iki şey (B6)

### 8.1 Yetim medya

Misafir dosya yükler, sonra LCV formunu **göndermezse** dosya diskte kalır ve
hiçbir LCV'ye bağlanmaz. Kota (`max_per_invitation`) bunu **sınırlar** ama
**temizlemez**.

`config/davetkart.php`'deki yorum bunu zaten öngörüyordu:

> *"Bu sınır olmasa gönderim yapmadan yüklenen 'yetim' dosyalarla disk
> doldurulabilirdi."*

Yani sınır bir savunma, çözüm değil. Gerçek çözüm periyodik bir temizlik
komutudur: *"hiçbir LCV'ye bağlı olmayan ve N saatten eski `rsvp_*` medyasını
sil."* **Faz 6'da yok**, `FAZ-6.md` §9'a yazılacak.

### 8.2 Kimliği bilinmeyen biri hâlâ disk yazdırabiliyor

Katmanların hepsi geçildiğinde geriye kalan gerçek şu: **doğru davetiyeyi bilen,
süresi geçmemiş bir bağlantıya sahip herkes 300 dosya yükleyebilir.**

Bunu sınırlayan üç şey var ve üçü de **sınır**, engel değil:

| Sınır | Ne yapar | Ne yapmaz |
|---|---|---|
| Hız sınırı (6.16) | Hızı düşürür | Yavaş bir saldırganı durdurmaz (**L3**) |
| Kota | Toplam hacmi kapar | Kotaya kadar dolmayı engellemez |
| Son tarih | Süreyi kapar | Süre içindeki hacmi sınırlamaz |

Faz 5'in cevabı buydu ve burada da aynı: **auth'suz bir yazma yolu kapatılamaz,
yalnızca pahalılaştırılır.**

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Tür izni kontrolünü "FormRequest zaten yapıyor" diye silmek | Action'ı HTTP dışından çağıran her yol misafiri **galeriye** yazar |
| 2 | Tür izni için `RuntimeException` kullanmak | Kod hatası kullanıcı hatası gibi raporlanır; gerçek sorun gizlenir |
| 3 | Tür izni için `422` üretmek | Var olmayan bir kullanıcı hatası; istemci düzeltemez |
| 4 | `ResolveOpenRsvpInvitationAction` yerine `ResolvePublicInvitationAction` çağırmak | Modül ve son tarih kontrolü kaybolur → 🔴 ~2.4 GB süresiz yükleme |
| 5 | `handle()`'a `bool $isGuest` parametresi eklemek | Bir gün yanlış geçirilir; H9 çağıranın dürüstlüğüne bağlanır |
| 6 | Route-model binding ile `Invitation` almak | Yayınlanmamış davetiye de çözülür; görünürlük Action'ın dışına kaçar (Faz 5, 5.10) |
| 7 | Honeypot eklemeye çalışmak | Dosya yüklemede görünmez alan diye bir şey yok; yanlış güven duygusu |
| 8 | Yüklemeyi "LCV gönderimiyle birlikte" tek uca almak | 15 saniye kuralı kırılır: 20 MB video + LCV tek istekte timeout'a girer |

8 numaralı satır bir tasarım sorusunun cevabı: *neden medya ayrı bir uçta?*
Çünkü `api.ts` timeout'u **15 saniye** ve video yüklemesi tek başına onu
zorlayabilir. Ayırmak, LCV metninin hızlı gitmesini garanti eder.

---

## 10. Kendin dene

```php
// php artisan tinker
use App\Actions\Media\StoreGuestMediaAction;
use App\Enums\MediaKind;
use App\Models\Invitation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

Storage::fake('public');

$action = app(StoreGuestMediaAction::class);

$inv = Invitation::factory()->published()->create([
    'show_rsvp' => true,
    'rsvp_deadline' => null,
]);

$file = UploadedFile::fake()->image('anı.jpg', 800, 600);

$media = $action->handle($inv->id, MediaKind::RsvpPhoto, $file);
$media->kind;        // MediaKind::RsvpPhoto
$media->url();       // .../storage/media/rsvp_photo/<rastgele>.jpg

// 🔴 1) Misafir galeriye yükleyebilir mi?
$action->handle($inv->id, MediaKind::Gallery, $file);
// LogicException: Media kind [gallery] cannot be uploaded by a guest.

// 2) Süresi dolmuş davetiye?
$inv->update(['rsvp_deadline' => now()->subDay()]);
$action->handle($inv->id, MediaKind::RsvpPhoto, $file);
// RsvpDeadlinePassedException → 403

// 3) LCV modülü kapalı?
$inv->update(['rsvp_deadline' => null, 'show_rsvp' => false]);
$action->handle($inv->id, MediaKind::RsvpPhoto, $file);
// ModelNotFoundException → 404
```

### Mutasyon tablosu (kural 14)

| # | Mutasyon | Kırılması gereken test |
|---|---|---|
| 1 | Tür izni `if`'ini sil | "misafir gallery türünü yükleyemez" — 🔴 **Action seviyesinde**, HTTP üzerinden değil (FormRequest onu zaten eler, yani HTTP testi bu mutasyonu **öldüremez**) |
| 2 | `resolveOpenInvitation` → `resolvePublic` | "LCV kapalıyken misafir yükleyemez" **ve** "süresi dolmuşken yükleyemez" |
| 3 | `LogicException` → `RuntimeException` | ⚠️ Muhtemelen hiçbiri — ikisi de 500 üretir. Bu bir **okunabilirlik** kararı |
| 4 | Kota aşımını `forOwner()` yapmak | "misafire dönen kota hatası `params` taşımaz" (**H9**) |

🔴 1 numaralı satır bu tablonun asıl dersi: **bir savunmayı yalnızca HTTP
üzerinden test edersen, HTTP'den önce duran katman onu gizler.** O testin
Action'ı doğrudan çağırması gerekir (**T15**: zinciri halkalara ayır).

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Invariant (değişmez)** | Bir sınıfın her zaman doğru tutmak zorunda olduğu koşul |
| **Composition** | Bir sınıfın işini başka sınıflara devrederek kurulması |
| **`LogicException`** | Programcı hatasını bildiren PHP exception ailesi |
| **`RuntimeException`** | Çalışma anı durumunu bildiren PHP exception ailesi |
| **Güven sınırı** | Verinin "doğrulanmamış"tan "doğrulanmış"a geçtiği nokta |
| **Yetim medya** | Yüklenmiş ama hiçbir kayda bağlanmamış dosya |
| **Defense in depth** | Üst üste binen, tek başına yeterli olmayan savunmalar |

---

## 12. Sırada ne var?

**6.15 — `PublicMediaController`.** Misafirin ucu:
`POST /api/public/invitations/{invitation}/media`.

`MediaController` (6.11) ile karşılaştırıldığında her karar tersine dönecek:

| | `MediaController` | `PublicMediaController` |
|---|---|---|
| Auth | `auth:sanctum` | ❌ yok — **K12** grubu |
| Rota parametresi | `Invitation` (binding) | `string` — görünürlük sorgu kapsamı |
| Yetki | `Gate::authorize('update')` | ❌ yok — **P5**: üst kaynağa devredildi |
| Action | `StoreUploadedMediaAction` | `StoreGuestMediaAction` |
| Kabul edilen tür | `gallery` | `rsvp_photo` \| `rsvp_video` |

| İlgili | Nerede |
|---|---|
| Saklama Action'ı | [`StoreUploadedMediaAction.md`](StoreUploadedMediaAction.md) |
| Açıklık kuralı | [`../Rsvp/ResolveOpenRsvpInvitationAction.md`](../Rsvp/ResolveOpenRsvpInvitationAction.md) |
| Kardeş Action | [`../Rsvp/SubmitRsvpAction.md`](../Rsvp/SubmitRsvpAction.md) |
| İstek | [`../../Http/Requests/Media/StorePublicMediaRequest.md`](../../Http/Requests/Media/StorePublicMediaRequest.md) |
| Kota exception'ı | [`../../Exceptions/MediaQuotaExceededException.md`](../../Exceptions/MediaQuotaExceededException.md) |
| Faz özeti | [`../../../fazlar/FAZ-6.md`](../../../fazlar/FAZ-6.md) |
