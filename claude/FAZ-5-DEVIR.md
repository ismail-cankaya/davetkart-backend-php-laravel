# DavetKart — Devir Dosyası (Faz 5 sonu)

> **Tarih:** 28 Ağustos 2026
> **Kimin için:** Bu projede **ilk kez** çalışacak bir AI asistanı
> **Ne kadar sürer:** Bu dosya + `CLAUDE.md` = ~15 dakika. Sonra çalışabilirsin.

---

## 0. Otuz saniyede proje

**DavetKart**, dijital davetiye SaaS'ı. Kullanıcı bir davetiye tasarlıyor,
yayınlıyor, linkini paylaşıyor; misafirler linkten davetiyeyi görüyor ve
"katılıyorum / katılamıyorum" diyor (LCV = RSVP).

| | |
|---|---|
| **Backend** | PHP 8.3+ · Laravel 13 · PostgreSQL 18 · Sanctum · Modüler Monolit |
| **Frontend** | React 19 · TypeScript · Vite · Zustand · ayrı depo |
| **Geliştirici** | İsmail — bilgisayar mühendisliği 3. sınıf öğrencisi |
| **Amaç** | Kod üretmek değil, **mimari vizyon öğretmek** |
| **Yöntem** | 9 fazlık dikey dilimler; her faz uçtan uca çalışan bir özellik |

**Şu an:** Faz 0-4 tamamlandı ve doğrulandı. **Faz 5 kodu yazıldı ama
doğrulanmadı.** Sıradaki iş: önce Faz 5'i doğrulamak, sonra Faz 6 (Media).

---

## 1. 🔴 İsmail'in çalışma kuralları — bunlara uy

Bunlar tercih değil, öğrenme yönteminin kendisi:

| # | Kural |
|---|---|
| 1 | **Tek dosya:** bir cevapta asla birden fazla dosya yazma |
| 2 | **Gerekçe anlat:** neden bu yaklaşım, hangi desen, güvenlik/performans kazancı ne. Amaç kodu kopyalamak değil, mimariyi öğrenmek |
| 3 | **Onay bekle:** dosyayı yazıp anlattıktan sonra DUR |
| 4 | **Onun yerine geçme:** komutları İsmail kendi makinesinde çalıştırır (Windows + Laravel Herd). Komutu ver, ne yapacağını açıkla, sonucu bekle |
| 5 | **Plandan sapma:** bir kararın yanlış olduğunu düşünüyorsan **önce söyle ve tartış**; kendi kararınla sapma |
| 6 | SOLID, Clean Code ve Laravel standartlarına katı bağlılık |
| 7 | **Türkçe**, öğrenciye açıklar gibi; teknik detayı atlama |
| 8 | **Açıklama nereye:** koda kısa yorum; detay `docs/rehber/<kod-yolu>.md` içine **eğitim dokümanı** olarak. Frontend için kendi deposunda `docs/rehber/src/<yol>.md`. Kılavuz **PHP'yi ilk kez gören biri** için yazılır: dil temelleri, tasarım kararları, sık yapılan hatalar tablosu, "kendin dene" adımları, terim sözlüğü |
| 9 | **Ritim:** komut ver → kodu yaz → kılavuzu yaz → `composer check` → DUR |
| 10 | **Her adım yeşil bitmeli:** var olmayan sınıfa referans verme; bağımlılık sırası dosya sırasını belirler |
| 11 | **Tahmin yürütme, kaynağa bak:** `vendor/` okunabilir. Hata mesajı **belirtiyi** söyler, **sebebi** değil |
| 12 | **Her faz sonunda:** `FAZ-N.md` + `FAZ-N-ELLE-DOGRULAMA.md` yaz; `claude/PHP-LARAVEL-SETUP.md`, `claude/PROMPT.md`, `docs/07` ve `docs/09` güncelle |
| 13 | **"Yeşil gördüm" demek için zincirin tamamı koşmuş olmalı.** `composer check` fail-fast: phpstan kırılırsa **testler hiç koşmaz**. Çıktının **SON** satırına bak |
| 14 | **Beklediğin yanıtı almak, beklediğin sebeple aldığın anlamına gelmez.** Test yeşilse "neden yeşil?" diye sor. Güvenlik testi yazarken **mutasyon** sor: "bu korumayı silsem hangi test kırılır?" |

> ⚠️ **Faz 5 oturumunda 1, 3 ve 4 numaralı kurallar İsmail'in açık talebiyle
> geçici olarak askıya alındı** (tek sohbette tüm fazı bitirmek istedi).
> Varsayılan yine yukarıdaki hâlidir; İsmail aksini söylemedikçe ona dön.

---

## 2. Okuma sırası

```
1. claude/PHP-LARAVEL-SETUP.md   ← ANA GİRİŞ: 53 karar, 47 ders, doküman haritası
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-5.md  (henüz master'a işlenmediyse)
2. CLAUDE.md                      ← bağlayıcı kod standartları (kısa, mutlaka oku)
3. docs/08-HATA-SOZLESMESI.md     ← API hata sözleşmesi (K20) — her fazda geçerli
4. docs/rehber/fazlar/FAZ-0.md … FAZ-5.md   ← kural ve karar birikimi
5. docs/07-GELISTIRME-YOL-HARITASI.md · docs/09-TUM-FAZLAR-PLANI.md
```

**Gerektiğinde:** `docs/03-MIMARI-PLAN.md` (⚠️ §8 GEÇERSİZ) ·
`docs/05-KLASOR-VE-DOSYA-REFERANSI.md` · `docs/rehber/kavramlar/` ·
`docs/rehber/<kod-yolu>.md` · frontend deposunda `docs/rehber/src/`

> 🔴 `docs/04-KURULUM-VE-KLASOR-YAPISI.md` §1 ve §4 **GEÇERSİZ**: MySQL diyor,
> proje K9'/K19 ile PostgreSQL 18'e geçti.

---

## 3. Mimarinin özeti (CLAUDE.md'nin kısası)

**Repository Pattern ve Fat Service YASAK.** Yerine **Action-Based Architecture**:

```
rota → FormRequest → Controller → Action → Model → Resource → yanıt
```

| Katman | Sorumluluk | Yasak |
|---|---|---|
| `app/Http/Requests/` | Doğrulama, camelCase→snake_case eşleme | İş kuralı |
| `app/Http/Controllers/Api/V1/` | Action'a yönlendir, Resource döndür (3-8 satır) | `if`, iş mantığı |
| `app/Actions/` | Tek eylem, iş kuralı, DB + yan görevler | HTTP yanıtı, doğrulama |
| `app/Models/` | `#[Fillable]` beyaz listesi, cast, ilişki | İş kuralı |
| `app/Http/Resources/` | snake→camel, **beyaz liste** | Sihirli dönüşüm |
| `app/Policies/` | Sahiplik / IDOR | Sorgu |
| `app/Enums/` | Sihirli string yasağı | — |

**Sözleşme (ihlal edilirse frontend kırılır):**

- Rotalar `/api/...` — **`/api/v1/...` değil**; versiyon namespace'te
- Auth yanıtları **zarfsız** `{user, token}`; diğerleri `{data: ...}`
- Hata: `{error: {code, fields?, params?}}` — **metin yok** (K20/K21)
- Alan adları camelCase; dönüşüm **yalnızca** Resource'ta
- Sahiplik yoksa **404**, 403 değil (H7)

---

## 4. Bugünkü teknik durum

| | |
|---|---|
| Dal | `faz-5` (`be7fa88`) — Faz 4 kapanışı `5446bc6` üzerine **17 commit** |
| Uç nokta | 13 |
| Test | **76** (47 doğrulanmış + 29 doğrulanmamış) |
| PHPStan | level **8** (Faz 5'te 6'dan yükseltildi, doğrulanmadı) |
| Kural | 106 · **Karar** 53 · **Ders** 47 |

### Çalışan uçlar

| Method | Path | Auth | Faz |
|---|---|:---:|:---:|
| GET | `/api/ping` | — | 1 |
| POST | `/api/auth/register` · `/login` | — | 2 |
| POST | `/api/auth/logout` · GET `/api/auth/me` | ✅ | 2 |
| GET/POST/PUT/DELETE | `/api/invitations[/{id}]` | ✅ | 3 |
| GET | `/api/public/invitations/{id}` | — | 4 |
| **POST** | **`/api/public/invitations/{id}/rsvps`** | — | **5** |
| **GET** | **`/api/invitations/{id}/rsvps`** | ✅ | **5** |
| **DELETE** | **`/api/rsvps/{id}`** | ✅ | **5** |

---

## 5. 🔴 Faz 5'in durumu — ilk iş bu

**Faz 5 kodu tamamlandı, ama `composer check` HİÇ KOŞMADI.**

Sebep: Faz 5, İsmail'in geçici olarak kullandığı ikinci bir bilgisayarda
yazıldı; orada `vendor/` ve `.env` yoktu, yardımcı ortamda da ağ kapalıydı.
Koşan tek kontrol: her PHP dosyası için `php -l` (yalnızca sözdizimi).

Bu, projenin en pahalı hatasının bilinçli bir versiyonu: Faz 1, 3 ve 4'te
özetlere *"yeşil"* yazıldı ve **değildi** (üç kez, üç ayrı fazda). Bu kez
`FAZ-5.md`'nin durum alanına **"⚠️ DOĞRULANMADI"** yazıldı (kural **B7**).

### Yeni asistanın ilk yapacağı

1. İsmail'e `composer check`'i koşturt — **son satıra** baktır
2. Kırılan varsa düzelt (Faz 3 ve 4 de üçer kırılmayla açılmıştı — normal)
3. `docs/rehber/fazlar/FAZ-5-ELLE-DOGRULAMA.md` (16 adım) koşturt
4. `FAZ-5.md` §11 kapanış listesini işaretlet ve **durum alanını güncelle**
5. Ancak ondan sonra Faz 6'ya geç

### PHPStan level 8 patlarsa

5.14 commit'i **ayrı** tutuldu tam da bunun için:

```powershell
git log --oneline | Select-String "5.14"
git revert <hash> --no-edit
composer check
```

Fazın geri kalanı etkilenmez. 🔴 `ignoreErrors`'a toplu susturma **ekleme**
(K4: her satır gerekçe ister).

---

## 6. Faz 5 ne inşa etti?

**Amaç:** sistemin **tek auth'suz yazma yolunu** açmak ve katmanlı savunmayla
korumak.

### Katmanlı savunma (`SubmitRsvpAction`)

```
0. Hız sınırı  → rota katmanı, Action'a hiç gelmez
1. Honeypot    → bot sessizce yutulur, VERİTABANINA HİÇ GİDİLMEZ
2. Görünürlük  → yayında değil / modül kapalı → 404
3. Son tarih   → geçtiyse 403
4. Kota        → dolduysa 403 (kilitli transaction içinde)
5. KVKK        → ham IP yerine hash
```

Sıra **en ucuzdan pahalıya** (L1). Honeypot en başta ve **sorgudan önce**.

### Bilinmesi gereken üç ince nokta

1. **`isPast()` yazılamaz.** `rsvp_deadline` bir `date` ve `00:00`'a denk gelir;
   `isPast()` son gün boyunca herkesi reddeder (**E8**).
2. **Kota bir check-then-act kalıbıdır** ama bir *toplam* olduğu için `UNIQUE`
   ile ifade edilemez → tek transaction + `lockForUpdate()` (**E9**).
3. **PostgreSQL'de `UNSIGNED` yoktur.** `unsignedSmallInteger` düz `smallint`e
   düşer → açık `CHECK (guest_count >= 1)` gerekli.

### Yeni kalıcı yapılar

| Yapı | Ne işe yarar | Sonraki fazlarda |
|---|---|---|
| `HasErrorCode` arayüzü | Exception kendi kodunu kendisi söyler; `ApiExceptionRenderer` tek kol taşır | 🔴 **Faz 7 ve 8'in yeni exception'ları bunu uygulamalı** |
| `RsvpQuotaResolver` (seam) | Bugün config, Faz 7'de gerçek abonelik | Faz 7'de yalnızca bağlama satırı değişir |
| `throttle:rsvp` · `throttleApi()` | İki kovalı LCV limiti + genel API tavanı | FAZ-4 §9.2 borcu kapandı |
| Mutasyon tablosu (`RsvpTest.md` §3) | 18 satır: "şunu boz, şu test kırılmalı" | **T16**: her fazın kapanış ölçütü |

---

## 7. 🔴 Bekleyen işler

### 7.1 Hemen (Faz 5'i kapatmak için)

- [ ] `composer check` yeşil
- [ ] `FAZ-5-ELLE-DOGRULAMA.md` 16 adım
- [ ] **Frontend uyarlaması — 7 dosya** →
      [`Notlar/04-FAZ-5-FRONTEND-YAPILACAKLAR.md`](Notlar/04-FAZ-5-FRONTEND-YAPILACAKLAR.md)
      🔴 Honeypot alanı eklenmezse savunma **hiç çalışmaz** ve **hiçbir test
      bunu söylemez**
- [ ] `PHP-LARAVEL-SETUP-EK-FAZ-5.md` master dosyaya işlensin

### 7.2 İsmail'in onayını bekleyen üç karar

`FAZ-5.md` §7'de 🟡 ile işaretli — bunlar tek taraflı verildi:

| Konu | Soru |
|---|---|
| `app/Contracts/` klasörü | `CLAUDE.md` §1 `app/Services/` diyordu; arayüz `Contracts`'a kondu. Doğru mu? |
| `rsvps.id` ULID (K52) | K40'ın kuralı uygulandı, ama plan bir şey demiyordu |
| `hash('sha256', $ip.$key)` | `hash_hmac()` kriptografik olarak daha doğru. `CLAUDE.md` §3 formülüne sadık kalındı; değiştirmek onu da güncellemek demek |

### 7.3 Sonraki fazlara

| Konu | Ne zaman |
|---|---|
| 🔴 `event_at` ve `rsvp_deadline` **saat dilimi** | Faz 6 — `invitations.timezone` kolonu. Faz 4'ten **ikinci kez** ertelendi, artık iki alanı birden etkiliyor |
| `Jobs/SendRsvpNotification` (K53) | Faz 8 önerisi — karar İsmail'de |
| `rsvps` medya kolonları | Faz 6 (§8'e bak — plan burada eskimişti) |
| `PublishInvitationAction` boş iskeleti | Faz 7 |
| `routes/web.php` closure'ı (R1/R4) | Faz 9 — `route:cache` orada kırılır |
| `toDisplayError()` çeviri katmanı | Frontend borcu (`Notlar/03`) |

---

## 8. ⚠️ Faz 6 planında düzeltilen varsayım

`docs/09` §FAZ 6 şunu yazıyordu:

> *"Faz 5'te `rsvps.photo_media_id` kolonu nullable ve kısıtsız açılmıştı"* →
> 6.8 o kolona FK ekleyecek

**Bu doğru değil.** Faz 5 medya kolonlarını **hiç açmadı** — gerekçe ders 26:
bir faz boyunca yazanı olmayan kolon, doğru olduğu varsayılan kolondur (K48'in
aynı ailesi).

Faz 6'da adım **6.8 artık şu:** `..._add_media_columns_to_rsvps_table.php` —
kolonları **ve** FK'yi birlikte ekler. Daha temiz: iki ayrı migration yerine
tek bir tutarlı şema değişikliği.

`docs/09` bu oturumda düzeltildi.

---

## 9. Depo ve ortam

```
C:\Projeler\Kişisel\davetkart\          (İsmail'in 2. bilgisayarı — geçici)
├─ davetkart-backend-php-laravel\       git: faz-5 @ be7fa88
└─ davetkart_frontend\                  git: main @ c2b8ec7 (21 Ağu — GERİDE)
```

| Uyarı | Ayrıntı |
|---|---|
| 🔴 Frontend deposu **geride** | Faz 4'ün `publicInvitation.ts` ve `InvitePage.tsx` işi yalnızca ev bilgisayarında; GitHub'a **push edilmemiş** |
| 🔴 Frontend'de **`.gitattributes` yok** | 491 dosya sahte "değişmiş" görünüyor (CRLF/LF). Backend'inki `* text=auto eol=lf`; frontend'e de eklenmeli + `git add --renormalize` |
| `faz-5` **push edilmedi** | `origin/faz-5` hâlâ `5446bc6`'da. `git push origin faz-5` gerekli |
| `.git/_cowork_trash/` | Bu oturumun bıraktığı geçici dosyalar. Silinebilir |

**Ortam:** Windows + Laravel Herd + PostgreSQL 18. İki veritabanı zorunlu:
`davetkart` ve `davetkart_test` (**V2** — testler `RefreshDatabase` ile tabloları
siler).

---

## 10. Sık düşülen tuzaklar (bu projede yaşandı)

| Tuzak | Nerede yaşandı |
|---|---|
| Elle yazılan rota kısıtı sessizce yanlış olabilir | Faz 3'ün ULID regex'i yalnızca büyük harfti → 3 IDOR testi **boş yeşil** |
| `create()` sonrası DB varsayılanı bellekte yok | `CreateInvitationAction` `status` yazmıyordu → 500 |
| Bir aracın kurulu olması, işini yaptığı anlamına gelmez | Larastan `casts()` metodunu **hiç** okumuyordu (`parseModelCastsMethod`) |
| `actingAs()` guard'ı atlar | `withToken()` kullan; ikinci kimlikli istekten önce `forgetAuthState()` (**T13**) |
| Doğrulama kuralı **nesnesi** sözleşmeye sınıf adı sızdırır | `Password::min(8)` → `illuminate_validation_rules_password` (**D6**) |
| `composer check` fail-fast | phpstan kırılırsa testler **hiç koşmaz** — son satıra bak |

---

## 11. Sıradaki iş

Faz 5 doğrulandıktan sonra **Faz 6 — Media**.
Hazır başlangıç mesajı: [`PROMPT-FAZ-6.md`](PROMPT-FAZ-6.md)
