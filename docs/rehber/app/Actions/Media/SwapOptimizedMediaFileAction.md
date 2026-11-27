# `app/Actions/Media/SwapOptimizedMediaFileAction.php`

> Faz 21.12 · `ARC-10`. `OptimizeUploadedImage::swapFile()` + `newPath()`. Action, çünkü veritabanı kaydı (kilitli satır
> güncellemesi), dosya yazma, olay ve iş kuyruğu birlikte (CLAUDE.md §1).

1. Küçülmüş dosya **yeni** yola (`media/<kind>/<40 rastgele>.<uzantı>`, adı sunucu üretir) yazılır. Neden üzerine değil:
   PNG → WebP'de uzantı değişir; okuma ile yazma arasında silinen fotoğrafı sahipsiz geri yaratırdı; CDN/tarayıcı aynı URL'de
   büyük orijinali tutabilir.
2. **Davetiye** satırı `lockForUpdate` ile kilitlenir (yükleme ve galeriden silmeyle aynı ortak kilit; READ COMMITTED'da var
   olmayan satır kilitlenemez, kilitlenebilecek ortak nesne üst kayıttır). Medya satırı kilit altında yeniden okunur; yol
   değişmişse iş ikinci kez koşuyordur, hiçbir şey yapılmaz.
3. Satır yoksa ya da yol değişmişse **telafi**: yazılan dosya silinir (disk transaction'a dahil değil).
4. Galeri öğesiyse `InvitationChanged` (misafir önbelleği yeni URL'i görsün; `touch()` değil, `updated_at` kaymasın).
5. Eski dosya `DeleteReplacedMediaFile` ile `replaced_file_grace_hours` sonra silinir (editördeki açık sekme eski URL'i tutuyor).

Testler: `ImagePipelineTest` (yeni yol + eski dosya kuyrukta; satır ilerlemişse geri çekilme + dosya silme) ve
`OptimizeUploadedImageTest` (uçtan uca: yarışta silinen satır, galeri/LCV önbelleği, eski dosyanın silinmesi).

## Koddan taşınan notlar (Faz 21.12, `ARC-10`, K18)

Faz 21.12'ye kadar bu iş `app/Jobs/OptimizeUploadedImage.php` içinde, uzun yorumlarıyla duruyordu. Bölünürken kodda
1–3 satırlık özet kaldı; aşağıda o günkü hâli yorumlarıyla **aynen** (anlam kaybı olmasın diye). Davranış değişmedi.

### Eski `swapFile()` ve `newPath()`

```php
    /**
     * Kuculmus dosyayi YENI bir yola yazar ve satiri o yola cevirir.
     *
     * 🔴 Neden ayni dosyanin uzerine yazilmiyor? Uc sebep:
     *
     *   1. PNG -> WebP'de UZANTI degisiyor. Icerigi WebP olan bir `.png`
     *      dosyasi, uzantisini icerikten ureten yukleme katmanina (4. katman,
     *      StoreUploadedMediaAction) ters duserdi.
     *   2. YARIS: is dosyayi okuduktan sonra fotograf silinirse, uzerine
     *      yazmak dosyayi SAHIPSIZ olarak geri yaratir. Yeni yol + kilitli
     *      satir kontrolu bunu imkansiz kilar; yazilan dosya sahipsiz kalirsa
     *      telafi onu siler.
     *   3. ONBELLEK: CDN ve tarayici ayni URL altinda BUYUK orijinali
     *      tutabilir. Yeni yol, yeni icerige yeni bir adres verir.
     *
     * Kilit DAVETIYE satiri: StoreUploadedMediaAction ve
     * DeleteGalleryMediaAction da ayni ortak kilidi aliyor, boylece "sil" ile
     * "cevir" siraya girer. PostgreSQL'in READ COMMITTED seviyesinde var
     * olmayan satir kilitlenemedigi icin kilitlenebilecek tek ortak nesne UST
     * KAYITTIR (Faz 5 ve 6 ile ayni desen).
     */
    private function swapFile(OptimizedImage $optimized): void
    {
        $disk = $this->media->disk;
        $previousPath = $this->media->path;
        $newPath = $this->newPath($optimized->extension);

        Storage::disk($disk)->put($newPath, $optimized->bytes);

        $invitation = DB::transaction(function () use ($optimized, $previousPath, $newPath): ?Invitation {
            $invitation = Invitation::query()
                ->whereKey($this->media->invitation_id)
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                return null;
            }

            // Satir kilit ALTINDA yeniden okunuyor: elimizdeki ornek istegin
            // basindan kalma. Yol degismisse is ikinci kez kosuyor demektir
            // (kuyruk "en az bir kez" teslim eder) ve yapilacak bir sey yok.
            $media = $invitation->media()->whereKey($this->media->getKey())->first();

            if ($media === null || $media->path !== $previousPath) {
                return null;
            }

            $media->path = $newPath;
            $media->mime_type = $optimized->mimeType;
            $media->size_bytes = $optimized->sizeBytes();
            $media->optimized_at = now();
            $media->save();

            return $invitation;
        });

        if ($invitation === null) {
            // 🔴 TELAFI. Diske yazma transaction'a DAHIL DEGILDIR: rollback
            // dosyayi geri almaz. Satir gittiyse yazdigimiz dosyayi elimizle
            // sileriz, yoksa yetim kalirdi (StoreUploadedMediaAction ile ayni
            // refleks).
            Storage::disk($disk)->delete($newPath);

            return;
        }

        // Misafir onbellegi galeri URL'lerini TASIYOR (PublicInvitationResource);
        // temizlenmezse yeni yol TTL dolana kadar gorunmez ve eski URL silinen
        // dosyayi gosterir. `touch()` DEGIL olay: `updated_at` frontend'de
        // "son kaydetme" olarak gosteriliyor, arka plan isi onu kaydirmamali.
        if ($this->media->kind->isGalleryItem()) {
            event(new InvitationChanged($invitation));
        }

        // 🔴 Eski dosya HEMEN silinmez. Editordeki sekme, yukleme yanitindan
        // gelen ESKI URL'i elinde tutuyor; dosya o anda silinse acik sayfada
        // kirik gorsel olurdu. Bekleme suresi sonunda yeni is siler ve
        // silmeden once o yolu gosteren bir satir kalmadigini dogrular.
        DeleteReplacedMediaFile::dispatch($disk, $previousPath)
            ->delay(now()->addHours(
                Config::integer('davetkart.media.optimize.replaced_file_grace_hours'),
            ));
    }

    /**
     * Yeni dosyanin yolu — `store()` ile AYNI ad deseni.
     *
     * Ad yine SUNUCU tarafindan uretiliyor (Str::random(40)); kullanicinin
     * verdigi ad hicbir asamada yola girmez.
     */
    private function newPath(string $extension): string
    {
        return 'media/'.$this->media->kind->value.'/'.Str::random(40).'.'.$extension;
    }
```
