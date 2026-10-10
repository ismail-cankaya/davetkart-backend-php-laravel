# DavetKart Frontend — Gözden Geçirme (9 Ekim 2026)

> **Tarih:** 9 Ekim 2026 · **Depo:** `davetkart_frontend` · **Dal:** `new-desing` @ `35a95b1`
> (commit edilmemiş Ufuk şablonlarıyla birlikte). Dosya yolları frontend deposuna göredir.
> **Yöntem:** Salt okunur kod okuması, `npm run lint` ve `npm run verify`. `npm install` ve `npm run build` çalıştırılmadı.
> **Eş rapor:** `claude/GOZDEN-GECIRME-2026-10-09-BACKEND.md`.

## Genel değerlendirme

Frontend, CLAUDE.md'deki mimari kurallara büyük ölçüde uyuyor:

- Store'lar alan bazında ayrılmış: auth, invitation, rsvp, subscription, ui, wizard.
- Bütün istekler interceptor'lı tek bir axios istemcisinden geçiyor.
- Kodda `any`, `@ts-ignore` ve raw `fetch` yok.
- Sayfalar `React.lazy` ile yükleniyor, private route korunuyor.

Kaynak kodda, `vite.config.ts`'te ve `dist/` içinde secret yok. `define` bloğu bulunmuyor, `@google/genai` kodda hiç kullanılmıyor, asistan istekleri backend proxy'sinden geçiyor. Autosave kuyruğu, belge kuşağı ve 402 rollback mantığı özenle yazılmış.

Asıl sorunlar dört yerde toplanıyor:

1. Misafir tarafındaki hatalar kullanıcıya hiç gösterilmiyor.
2. Ödeme dönüş akışı kopuk.
3. Tüm şablonlar ana bundle'a gömülü.
4. TypeScript strict kapalı.

**Kritik seviyede bulgu yok.**

---

## Bulgular

### Yüksek

#### 1. Misafir LCV hataları ekranda hiç görünmüyor

**Yer:** `src/App.tsx:57`, `src/components/layout/AppLayout.tsx:44`, `src/components/templates/shared/RSVPForm.tsx:66-67`

**Sorun:** `<Toaster />` yalnızca `AppLayout` içinde mount ediliyor. `/invite/:id` sayfası bu layout'un dışında, `RSVPForm` ise hataları sadece `toast()` ile bildiriyor. Misafir sayfasında bu bildirimi dinleyen kimse yok.

**Senaryo:** Misafir son tarih geçtikten sonra (`RSVP_DEADLINE_PASSED`), kota dolduğunda, 429 aldığında ya da ağ koptuğunda gönder düğmesine basıyor ve hiçbir şey olmuyor. Ne hata mesajı ne başarı ekranı görüyor. LCV ev sahibine ulaşmıyor.

**Bu durumu kesinleştiren uyumsuzluk:** Form en fazla 12 kişi seçtiriyor (`RSVPForm.tsx:211`, `Math.min(12, …)`). Backend'in sınırı 10 (`StoreRsvpRequest.php:80`, `config/davetkart.php:39`). 11 ya da 12 seçen her misafir sessizce 422 alıyor.

**Düzeltme:**
- Hata formun içinde gösterilsin, ya da `InvitePage` kendi Toaster'ını mount etsin.
- Kişi sınırı 10'a indirilsin, mümkünse değer config'den okunsun.

#### 2. Ödeme dönüşü ana sayfaya düşüyor ve mükerrer siparişe açık

**Yer:** `src/App.tsx:61`, `src/components/payment/PaywallModal.tsx:82`, `src/stores/useSubscriptionStore.ts:118-126`

**Sorun:** Backend ödeme sonrası `/odeme/basarili?order=…` adresine yönlendiriyor (`FakeGateway.php:59`). Frontend'de bu rota yok, catch-all `*` kullanıcıyı `/`'a atıyor. Hata URL'i backend'de hiç kullanılmıyor.

**Senaryo:**
- Kullanıcı ödemenin sonucunu öğrenemiyor.
- Paywall'ı yeniden açıp "Satın al"a basınca yeni bir sipariş oluşuyor. Sebebi şu: `openPaywall` her açılışta `pendingOrder`'ı sıfırlıyor, `isProcessing` ise yalnızca istek sürerken koruma sağlıyor.
- Backend de mükerrer siparişe açık (`StartCheckoutAction.php:61-69`).

**Durum:** `docs/F8-DOGRULAMA.md` içinde B1 olarak kayıtlı ve hâlâ açık. Backend'de `GET /orders(/{id})` planlı (FAZ-10 Dilim C, 10.22-10.25) ama henüz yazılmadı.

**Düzeltme:**
- Ödeme dönüş sayfası yazılsın (10.27).
- Bekleyen bir sipariş varken frontend yeni checkout başlatmasın.

#### 3. Bütün şablonlar ana bundle'da

**Yer:** `src/components/templates/TemplateRenderer.tsx:1-78, 93-381`, `src/App.tsx:7`, `src/components/preview/DeviceSimulator.tsx:5`

**Sorun:** Bağımlılık zinciri şöyle: `HomePage` eager yükleniyor, o `DeviceSimulator`'ı, `DeviceSimulator` da `TemplateRenderer`'ı çekiyor. `TemplateRenderer` 230'dan fazla şablonun hepsini statik import ediyor.

**Ölçüm (`dist`):**

| Dosya | Ham | Gzip |
|---|---|---|
| Ana JS chunk | 1.279.590 B | ~344 KB |
| CSS | 737 KB | ~77 KB |

**Senaryo:** Linki WhatsApp'tan açan mobil misafir, tek bir şablonu görmek için bu yükün tamamını indiriyor.

**Düzeltme:** `THEME_PRESETS` içindeki her şablon `React.lazy` ile yüklensin. Ana sayfa önizlemesi de lazy olsun.

#### 4. TypeScript strict kapalı, yerel lint de aslında denetim yapmıyor

**Yer:** `tsconfig.json`

**Sorun:**
- `strict` tanımlı değil. Bu, CLAUDE.md'deki "Strict TypeScript" kuralına aykırı.
- Yerel `node_modules` 2026-07-10 tarihli, yani eski. `@types/react` ve `@types/react-dom` kurulu değil. Buna karşılık package.json'da olmayan `@google/genai` kurulu.

**Sonuç:** React modülü örtük olarak `any` oluyor ve hiçbir bileşenin prop'ları denetlenmiyor. `--strict` ile çalıştırınca 4418 hata çıkıyor, bunların çoğu eksik tiplerden kaynaklanıyor.

**Düzeltme:** Önce `npm ci`, ardından `strict: true` kademeli olarak açılsın.

### Orta

#### 5. Token localStorage'da tutuluyor ve süresi yok

**Yer:** `src/services/auth.ts:76-101`, `src/stores/useAuthStore.ts:48-52`. Backend tarafı: `config/sanctum.php:55` (`expiration=null`).

**Sorun:** CSP yok. Uygulama açılışta oturumu `/auth/me` ile doğrulamıyor.

**Risk:** İleride çıkacak tek bir XSS, süresiz bir token'ı sızdırmaya yeter. Bugün aktif bir XSS vektörü bulmadım:
- `dangerouslySetInnerHTML` kullanılmıyor.
- React 19 `javascript:` URL'lerini engelliyor.
- Laravel'in `url` kuralı bu şemayı kabul etmiyor.

**Düzeltme:** Token'a süre verilsin, CSP eklensin. Uzun vadede Sanctum cookie kimliğine geçilsin.

#### 6. Son 400 ms'de yazılan metin kayboluyor

**Yer:** `src/hooks/useInvitationAutoSave.ts:33-36`, `src/hooks/useInvitationDraft.ts:131-136`, `src/components/editor/DesignerPanel.tsx:62`, `src/components/create/EditorWorkspace.tsx:51-58`

**Sorun:** React, silinen bir ağaçta effect cleanup'larını önce ebeveynde çalıştırıyor (react-dom kaynağında doğruladım). Sıra şöyle işliyor:

1. `CreatePage` cleanup'ı autosave aboneliğini kaldırıyor ve bekleyen kaydı boşaltıyor.
2. Ardından çocuk formun taslak cleanup'ı `updateField` ile store'a yazıyor.
3. Dinleyen autosave kalmadığı için bu yazım hiç PUT edilmiyor.

**İkinci senaryo:** `publishInvitation`, `DesignerPanel`'deki bekleyen taslağı boşaltmıyor. Yazdıktan hemen sonra "Yayınla"ya basan kullanıcının son harfleri yayına çıkmıyor ve kalıcı olarak kayboluyor.

**Düzeltme:** Store'a kayıtlı ortak bir flush mekanizması kurulsun. Publish ve unmount öncesinde önce taslaklar, sonra autosave boşaltılsın.

#### 7. Ana sayfa vitrini editördeki belgeyi değiştiriyor

**Yer:** `src/components/preview/TemplateGrid.tsx:55-58`, `src/pages/DashboardPage.tsx:225-226`

**Sorun:** Vitrin kartı `updateField('categoryId')` ve `selectTemplate` çağırıyor. Bu çağrılar paylaşılan editör belgesini değiştiriyor ve `editRevision` değerini artırıyor.

**Senaryo:**
1. Kullanıcı panelden yayındaki X davetiyesi için "devam et" diyor.
2. Ana sayfaya gidip bir vitrin kartına tıklıyor.
3. "Davetiye Tasarla" ile `/create`'e dönüp bir düzenleme yapıyor.
4. Autosave, X'in şablonunu ve kategorisini canlı sayfada değiştiriyor.

**Düzeltme:** Önizleme ayrı bir showcase durumu kullansın, editör belgesine dokunmasın.

#### 8. Paywall modalı erişilebilir değil

**Yer:** `src/components/payment/PaywallModal.tsx:103-136`

**Sorun:**
- `role="dialog"`, `aria-modal` ve `aria-labelledby` yok.
- Modal açılınca odak içeri taşınmıyor ve içeride tutulmuyor.
- Kapat düğmesinin yalnızca `<X/>` ikonu var, erişilebilir bir adı yok.

**Senaryo:** Ekran okuyucu kullanıcısı modalı "düğme" olarak duyuyor. Tab tuşu arkadaki sayfaya kaçıyor.

**Düzeltme:** ConfirmDialog'daki kalıp kullanılsın; odak tuzağı ve `aria-label` eklensin.

#### 9. Dil seçici neredeyse işlevsiz

**Yer:** `src/i18n.ts:53-84`, `src/hooks/useDocumentDirection.ts:16`

**Sorun:** Seçicide 10 dil sunuluyor, ama çeviri dosyaları 31 satırdan ibaret: yalnızca menü ve hero çevrilmiş. `fallbackLng` değeri `en`.

**Senaryo:**
- Arapça tarayıcıda sayfa `rtl` düzene geçiyor ama içeriğin yaklaşık %95'i Türkçe kalıyor.
- Tarayıcısı en-US olan Türk kullanıcı karışık dilde bir sayfa görüyor.

**Düzeltme:** `fallbackLng: 'tr'` yapılsın. Seçici şimdilik yalnızca tr ve en ile sınırlansın.

#### 10. İş mantığı UI bileşenlerinin içinde

**Yer:** `src/components/create/GalleryUploader.tsx:64-120`, `src/components/create/EditorWorkspace.tsx:51-84`

**Sorun:** Bu iki karar bileşenin içinde duruyor:
- Yükleme orkestrasyonu: önce kaydet, sonra sırayla yükle, ilk hatada dur.
- Hata yönlendirmesi: 402 gelince paywall'ı aç, 409 gelince panele git.

Bu, Separation of Concerns kuralına aykırı. Bu mantık bileşen olmadan test edilemiyor ve başka yerde kullanılamıyor.

**Düzeltme:** Bu mantık store aksiyonlarına taşınsın.

#### 11. Anonim taslak sayfa yenilenince kayboluyor

**Yer:** `src/components/create/EditorWorkspace.tsx:89, 192`

**Sorun:** Arayüz "Tasarımınız güvende" diyor, ama taslak yalnızca bellekte duruyor.

**Senaryo:** Mobil tarayıcı arka plandaki sekmeyi kapatırsa, kullanıcı sayfayı yenilerse ya da e-posta doğrulaması yeni sekmede açılırsa tasarım kayboluyor.

**Düzeltme:** Taslak `zustand/persist` ile sessionStorage'a yazılsın, ya da arayüz metni dürüstçe düzeltilsin.

### Düşük

#### 12. Çıkışta bekleyen düzenleme kayboluyor

**Yer:** `src/stores/sessionActions.ts:39-44`

**Sorun:** `signOut` önce `logout` ve `resetInvitation` çağırıyor, bekleyen autosave sonra boşaltılıyor. Sonuçta son 1,5 saniyedeki düzenleme kayboluyor ve boş belge için token'sız bir `POST /invitations` gönderilip 401 alınıyor.

**Düzeltme:** Çıkıştan önce autosave boşaltılsın ya da iptal edilsin.

#### 13. Panelde bayat kayıtla devam etme yarışı (olası)

**Yer:** `src/hooks/useDashboardData.ts:30-44`

**Sorun:** `/create`'ten çıkarken bekleyen kayıt PUT olarak boşaltılıyor. Panel listesi bu PUT tamamlanmadan dönebilir. Bu durumda "devam et" eski kaydı yükler ve bir sonraki autosave sunucudaki yeni hâlin üzerine yazar.

**Düzeltme:** Liste yüklenmeden önce kayıt kuyruğu beklensin.

#### 14. Ölü kod: `onRsvpClick` ve `RsvpModal`

**Yer:** `TemplateRenderer.tsx:403`, `InvitePage.tsx:123-127`, `DeviceSimulator.tsx:237`

**Sorun:** Hiçbir şablon `onRsvpClick` çağırmıyor, dolayısıyla `RsvpModal` hiç açılmıyor. Yine de eager olarak bundle'a giriyor ve kendi guestCount sınırıyla ikinci bir LCV yolu taşıyor.

**Düzeltme:** Prop ve modal kaldırılsın.

#### 15. Modül seviyesinde değişebilir durum

**Yer:** `src/stores/useInvitationStore.ts:108-151`

**Sorun:** `saveQueue`, `documentGeneration`, `documentOwnerId` ve `confirmedModules` store'un dışında tutuluyor. Bu, testlerde sıfırlamayı ve HMR'ı zorlaştırıyor.

**Düzeltme:** Bu değerler store içine ya da bir fabrika fonksiyonuna taşınsın.

#### 16. Büyük harfli ULID ile davetiye açılmıyor

**Yer:** `src/pages/InvitePage.tsx`, `src/services/publicInvitation.ts`

**Sorun:** `/invite/:id` içindeki id küçük harfe çevrilmiyor. QR koddan ya da elle büyük harfle girilen ULID'de backend 404 dönüyor. Uygulamanın ürettiği paylaşım linki sunucudan gelen küçük harfli id'yi kullandığı için sorun yalnızca dış kaynaklı linklerde çıkıyor.

**Düzeltme:** id istekten önce `toLowerCase()` ile normalize edilsin.

---

## API sözleşmesi uyumsuzlukları

Bu bölümdeki her maddeyi iki taraf da kodu okuyarak doğruladı.

| # | Konu | Frontend | Backend | Etki |
|---|---|---|---|---|
| 1 | Ödeme dönüşü | `/odeme/basarili` ve `/odeme/hata` rotaları yok, catch-all `/`'a atıyor | `FakeGateway.php:59` → `/odeme/basarili?order=`. Hata URL'i kullanılmıyor. Sipariş okuma ucu yok (planlı: 10.22-10.25) | Bulgu 2 |
| 2 | `guestCount` üst sınırı | 12 (`RSVPForm.tsx:211`) | 10 (`StoreRsvpRequest.php:80`) | 11 ve 12 kişi sessizce 422 alıyor (bulgu 1) |
| 3 | Galeri kotası | 8 (`GalleryUploader`) | 30 | Zararsız ama tutarsız |
| 4 | `GET /public/places/search` | Var, bayrakla kapalı (`VITE_PLACES_SEARCH_ENABLED=false`) | Yok | Bayrak açılırsa 404 |
| 5 | `publishedAt` | Panel 3 günlük iade penceresini hesaplayamıyor | `InvitationResource`'ta yok | Bilinen açık (10.21) |
| 6 | `GET /auth/me` | Hiç çağrılmıyor | Var | Geri yüklenen oturum doğrulanmıyor |
| 7 | Contact yolu | `/public/contact` kullanılıyor (doğru) | `routes/api.php:269-275` yorumu eskimiş | Doküman borcu |

**Uyumlu olanlar:**

- Hata kodu kataloğu: 21 kod iki depoda birebir aynı (yalnızca CRLF farkı var). Hepsinin tr ve en çevirisi mevcut. 409 `INVITATION_ALREADY_PUBLISHED` akış olarak ele alınıyor (`EditorWorkspace.tsx:74`). 400 `MALFORMED_REQUEST` çevrilmiş metin olarak gösteriliyor.
- Enum'lar: `OrderStatus` (`expired` dahil), `InvitationStatus`, `RsvpStatus`.
- Veri şekilleri: davetiye payload alanları, checkout zarfı (`{data:{orderId,tier,status,redirectUrl?}}`), assistant zarfı (`{data:{reply}}`), zarfsız auth yanıtı (`{user,token}`).
- Modül→plan haritası ve fiyatlar (249 / 399 / 549).
- CORS `exposed_headers: ['ETag']`.
- 402 davranışı: Backend yalnızca yayındaki davetiyede ve plan yükseltildiğinde 402 dönüyor (`UpdateInvitationAction.php:62-110`); create hiç 402 dönmüyor. Frontend'in rollback mantığı bununla tutarlı.

---

## Commit edilmemiş Ufuk değişiklikleri

> Çalışma anındaki durum (11:27–11:32). Dosyalar o sırada başka bir oturumda değişiyor olabilirdi.

**Sağlam olanlar:**

- `renderBackdrop` / `ScrollBackdrop` ayrımı temiz. Sahnesi olmayan şablonlar scroll dinleyicisi taşımıyor.
- Preset ID'leri backend'in `max:48` sınırına uyuyor.
- Kapak SVG'leri `<img>` olarak kullanılıyor, güvenli.
- `prefers-reduced-motion` desteği iyi:
  - Derinlik kaymaları `k=0` ile sıfırlanıyor.
  - Sürekli CSS döngüleri `data-atmosphere` ile duruyor.
  - Perde ve isim maskesi animasyonlarını `MotionConfig reducedMotion="user"` atlıyor.
- Lint'e yeni hata eklemedi. Ancak React tipleri eksik olduğu için gerçek bir tip denetiminden de geçmedi.

**Riskler:**

- **Mobil performans (olası):**
  - Sahnede en fazla 5 derinlik katmanı var, her katmanda 4'e kadar tam ekran SVG. Toplamda yaklaşık 20 üst üste katman ve `will-change` kullanımı oluyor.
  - Gündüz ve gece kopyaları yüzünden DOM iki katına çıkıyor. Örneğin parti kapağı 584 öğe.
  - Her bölüm kartı, sürekli yay animasyonu yapan bir sahnenin önünde `backdrop-blur-md` kullanıyor; tarayıcı bulanıklığı her karede yeniden hesaplıyor.
  - Üç sonsuz CSS döngüsü var.
  - Gerçek cihazda, özellikle iOS Safari ve düşük/orta segment Android'de profillenmeli.
- **Bundle:** `art.tsx` (1666 satır) ve 9 Ufuk şablonu da ana bundle'a giriyor. Bu, bulgu 3'ü büyütüyor.
- **Okunabilirlik (olası):** `UfukHero` içinde 8–10 px büyük harf etiketler var (`text-[8px]`, `text-[9px]`). Hero'da scrim opaklığı 0'dan başlıyor. Accent renkli küçük başlık, sıcak tonlu gökyüzünün önünde düşük kontrastta kalabilir.
- **`PRESET_PALETTES`:** Ufuk preset'leri eklenmemiş. `themeOverride` kullanıldığı için görsel etkisi yok; yalnızca kayıttaki `palette` alanı eski değerde kalıyor.

---

## `npm run lint` sonucu

**Başarısız: exit 2, 4 hata.**

```
src/components/create/location/MapPickerModal.tsx(43,26): error TS2322  (key prop'u Omit<MapPickerModalProps,"open"> tipinde yok)
src/components/editor/ThemePickerModal.tsx(39,35): error TS2322         (key prop'u Omit<ThemePickerModalProps,"open"> tipinde yok)
src/hooks/useProgressiveList.ts(23,10): error TS2503                    (Cannot find namespace 'React')
src/hooks/useProgressiveList.ts(51,16): error TS2503                    (Cannot find namespace 'React')
```

Dört hatanın dördü de yerel `node_modules`'te `@types/react` ve `@types/react-dom` eksik olduğu için çıkıyor; kod hatası değiller ve CI sonucunu yansıtmıyorlar. Kesin sonuç için önce `npm ci`, ardından `npm run lint` çalıştırılmalı.

**Diğer doğrulamalar:**

- `npm run verify`: 7 betiğin yedisi de geçti (errors, endpoints, etag, time, state, content, image). Betikler derleme çıktılarını `node_modules/.cache/` altına yazdı.
- Kaynak dosyalara dokunulmadı. `npm install` ve `npm run build` çalıştırılmadı.
