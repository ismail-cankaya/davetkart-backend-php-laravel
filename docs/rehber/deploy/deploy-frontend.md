# `deploy/deploy-frontend.sh`

> **Ne işe yarar:** React sitesini (frontend deposu) sunucuda günceller: kodu
> çeker, derler ve **yayındaki siteyi bozmadan** yeni dosyalarla değiştirir.
> `deploy.sh` sonunda kendiliğinden çağırır; yalnızca frontend değiştiyse tek
> başına da çalıştırılabilir. Backend deposunda durur, çünkü bütün sunucu
> araçları (`deploy/`) tek yerde.

## 1. Akış

| # | Adım | Neden |
|---|---|---|
| 0 | root değil mi, çalışma ağacı temiz mi? | `deploy.sh` ile aynı güvenlik kontrolleri |
| 1 | `git pull --ff-only` | Sunucu yalnızca `main`'i çeker |
| 1b | Etiket: frontend ↔ backend | İki deponun etiketi günlüğe yazılır; etiketsiz ya da farklıysa uyarı (Faz 18, K149; §1b) |
| 2 | `npm ci --ignore-scripts` | `package-lock.json`'daki sürümler **birebir**; bağımlılıkların kurulum betikleri çalışmaz |
| 3 | `vite build --outDir dist.new` | Yeni site **ayrı klasöre** derlenir, yayındaki `dist/` o sırada sunulmaya devam eder |
| 4 | Eski asset'leri kopyala | Açık sekmeler eski JS parçalarını hâlâ yükleyebilsin (aşağıda) |
| 5 | `dist → dist.old`, `dist.new → dist` | Yer değiştirme: iki `mv`, mikrosaniyeler |
| 6 | Ana sayfa `<div id="root">` döndürüyor mu? | Gerçekten yeni site sunuluyor mu? |

## 1b. Sürüm etiketi: iki depo aynı etikette mi? (Faz 18 · `OPS-01` · K149)

```bash
TAG="$(git describe --tags --exact-match HEAD 2>/dev/null || echo etiketsiz)"
BACKEND_TAG="$(git -C "$BACKEND_DIR" describe --tags --exact-match HEAD 2>/dev/null || echo etiketsiz)"
step "Surum: $NEW ($TAG) | backend: $BACKEND_TAG"
```

Günlükte `==> [frontend] Surum: 9c1d2e3 (v1.1.0) | backend: v1.1.0` görmek istiyoruz.

| Durum | Betik ne yapar |
|---|---|
| İkisi de aynı etikette | Hiçbir şey; devam |
| Frontend etiketsiz | `UYARI: … etiketli bir surum degil`, devam |
| Etiketler farklı (ya da backend etiketsiz) | `UYARI: frontend v1.2.0, backend v1.1.0: iki depo ayni etikette degil`, devam |

**Neden burada?** Bu betik tek başına da çalıştırılabiliyor ve tehlikeli sıra tam olarak o
yoldan gelir: frontend'in yeni premium şablonları yayına çıkar, onları koruyan backend
commit'i henüz çıkmamıştır. Arayüz "Gold+" der, sunucu 402 dönmez (`OPS-01`). Backend'in
etiketi bu betiğin yanındaki depodan okunur (`BACKEND_DIR`: betiğin bir üst klasörü); böylece
uyarı, betik `deploy.sh`'tan çağrılsa da tek başına koşsa da çıkar.

**Uyarı durdurmaz** (`deploy.md` Adım 1c ile aynı karar). Kuralın kendisi yine geçerlidir:
**önce backend, sonra frontend** (K148). Uyarıyı gördüysen dur ve docs deposundaki
`operations/deployment/YAYIN-SIRASI.md`'yi izle.

Etiket yalnızca "hangi commit" sorusunu cevaplar. "Kurallar aynı mı" sorusunu frontend'in
kalite kapısı cevaplar: `npm run verify:plans`, backend'in `contracts/plan-rules.json`
sözleşmesinin kopyasıyla (`docs/rehber/app/Console/Commands/ExportPlanRules.md`).

## 2. Neden doğrudan `dist/` içine derlemiyoruz?

Vite derlemeye başlarken `dist/` klasörünü **boşaltır** ve dosyaları birer birer
yazar. Derleme 10-15 saniye sürer. O sürede gelen ziyaretçi ya boş bir klasöre
(404) ya da yarısı eski yarısı yeni bir siteye (bozuk sayfa) denk gelir. Derleme
hata verirse site **boş kalır**.

`dist.new/` ile derleme bitene kadar yayındaki site hiç değişmez. Derleme
patlarsa betik durur ve site eski hâliyle çalışmaya devam eder (hata mesajı da
bunu söyler). Yer değiştirme iki `rename` çağrısıdır; aradaki boşluk mikrosaniye
düzeyindedir. Tam atomik geçiş (sembolik bağ ile) bu ölçekte gereksiz karmaşa.

## 3. Eski asset'leri neden saklıyoruz?

Vite uygulamayı parçalara böler; sayfalar ihtiyaç anında yüklenir
(`CreatePage-0SDAYOlY.js` gibi). Dosya adındaki karma (hash) içerikten üretilir.
Deploy sırasında sitesi açık bir kullanıcının tarayıcısı **eski** `index.html`'in
listesini tutar. "Davetiye oluştur"a tıkladığında eski adı ister. Eski dosyayı
silmişsek 404 alır ve sayfa açılmaz ("ChunkLoadError").

Bu yüzden yeni derlemeye bir önceki `dist/assets/` içeriği **üzerine yazmadan**
(`cp -a --update=none`; eski yazımı `cp -an`, coreutils 9.x
bunun için uyarı veriyor) eklenir. Birikmesin diye **14 günden eski kopyalar** silinir. Ayrım
dosya tarihinden yapılır: `cp -a` kopyanın eski tarihini korur, yeni derlenen
dosyalar bugünün tarihini taşır. Yani silinen yalnızca eski kopyalardır.

## 4. Derleme ayarları depoda

```bash
export VITE_API_BASE_URL=/api
```

> Faz 21.7 (`FE-08`, K271): `VITE_PLACES_SEARCH_ENABLED` kaldırıldı; frontend'de konum önerisi araması yok, bayrak
> okunmuyor.

Sunucuda takip edilmeyen bir `.env.production` dosyası yerine ayarlar betikte
durur: gözden geçirilir, geçmişi tutulur, yeni sunucuda unutulmaz. 🔴 `VITE_`
ile başlayan her değer **derlenmiş JavaScript'e gömülür** ve tarayıcıda herkes
okur. Buraya hiçbir zaman sır (API anahtarı, parola) yazılmaz.

`/api`: site ve API aynı alan adında (same-origin). Tarayıcı API'yi "başka site"
saymaz, CORS ön isteği (preflight) olmaz.

## 5. `--ignore-scripts`

npm paketleri kurulurken kendi betiklerini (`postinstall`) çalıştırabilir.
Tedarik zinciri saldırılarının çoğu buradan gelir: ele geçirilmiş bir paket
kurulduğu anda sunucuda kod çalıştırır. `--ignore-scripts` bunu kapatır. Vite,
esbuild ve Tailwind'in yerel (native) dosyaları betikle değil npm'in
"isteğe bağlı bağımlılık" mekanizmasıyla gelir; Y10.2'de bu ayarla derlemenin
çalıştığı doğrulandı.

## 6. Geri alma

Derleme ya da sağlık kontrolü hata verirse son sürüm `dist.old/` içinde durur:

```bash
cd /var/www/davetkart/frontend
mv dist dist.bad && mv dist.old dist     # bir önceki siteye anında dön
```

Kodu da eski sürüme almak gerekirse: `git reset --hard <onceki>` ve betiği
yeniden çalıştır. Nginx'e dokunmak gerekmez: dosyalar değişince bir sonraki
istek yenisini alır (`index.html` `no-cache`).

## 7. Sık hatalar

| Belirti | Sebep |
|---|---|
| `npm ci` "lock file out of sync" | `package.json` değişmiş ama `package-lock.json` commit'lenmemiş |
| Derleme `Killed` | Bellek bitti (2 GB). `free -h`; swap açık mı? |
| Sağlık kontrolü hata | `dist/index.html` yok ya da Nginx `root` yolu yanlış |
| Kullanıcıda "ChunkLoadError" | Eski asset'ler silinmiş (14 günden uzun açık kalan sekme: yenilemesi yeter) |
| Site değişmedi | Tarayıcı `index.html`'i önbellekte tutuyor: Nginx `no-cache` kuralı bozulmuş |
| `UYARI: … iki depo ayni etikette degil` | Backend henüz aynı sürüme alınmamış ya da etiket itilmemiş. Önce `bash deploy/deploy.sh` (§1b) |
