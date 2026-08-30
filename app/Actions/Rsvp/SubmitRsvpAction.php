<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Contracts\RsvpQuotaResolver;
use App\Enums\MediaKind;
use App\Enums\RsvpStatus;
use App\Exceptions\RsvpDeadlinePassedException;
use App\Exceptions\RsvpQuotaExceededException;
use App\Models\Invitation;
use App\Models\Rsvp;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Misafirin LCV yanitini kabul eder — sistemin TEK auth'suz yazma yolu.
 *
 * 🔴 Katmanli savunma (defense in depth) burada uygulanir. Her katman tek
 * basina yeterli DEGILDIR; birlikte anlam kazanirlar:
 *
 *   0. Hiz siniri      -> rota katmani (5.8), bu Action'a hic gelmez
 *   1. Honeypot        -> bot sessizce yutulur, VERITABANINA HIC GIDILMEZ
 *   2. Hedef acik mi   -> ResolveOpenRsvpInvitationAction (yayin + modul + son tarih)
 *   3. Medya aidiyeti  -> baskasinin/yanlis turdeki medya SESSIZCE dusurulur
 *   4. Kota            -> dolduysa 403 (kilitli transaction icinde)
 *   5. KVKK            -> ham IP yerine hash
 *
 * Sira tesadufi degil: en ucuz kontrol en basta. Bot trafigi tek bir sorgu
 * bile actirmadan elenir.
 *
 * 🔴 6.13 (Faz 6): 2. katman BU DOSYADAN CIKARILDI. Gorunurluk + modul + son
 * tarih ucusu artik ResolveOpenRsvpInvitationAction'da; cunku misafirin MEDYA
 * yukleme ucu de tam olarak ayni uc kosulu istiyor ve kural iki yerde
 * duramaz (C3). Davranis birebir ayni kaldi — kaniti RsvpTest'in 29 testi.
 * Ayrintili aciklama: docs/rehber/app/Actions/Rsvp/SubmitRsvpAction.md
 */
final class SubmitRsvpAction
{
    public function __construct(
        private readonly ResolveOpenRsvpInvitationAction $resolveOpenInvitation,
        private readonly RsvpQuotaResolver $quota,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  StoreRsvpRequest::rsvpAttributes()
     * @param  string  $ip  Ham IP — SAKLANMAZ, yalnizca hash'lenir
     * @param  bool  $honeypotTripped  Gorunmez alan dolduruldu mu (5.4)
     * @param  array{photo: ?string, video: ?string}  $mediaIds  🔴 DOGRULANMAMIS
     *
     * @throws ModelNotFoundException Davetiye yok / yayinda degil / LCV kapali -> 404
     * @throws RsvpDeadlinePassedException Son tarih gecti -> 403
     * @throws RsvpQuotaExceededException Kota doldu -> 403
     */
    public function handle(
        string $invitationId,
        array $attributes,
        string $ip,
        bool $honeypotTripped,
        array $mediaIds = ['photo' => null, 'video' => null],
    ): Rsvp {
        // 1. KATMAN — 🔴 En basta, cunku en ucuzu ve en cok isleyeni.
        // Bot ne bir sorgu actirir ne bir satir yazar; yine de basarili
        // gorunen bir yanit alir (5.4: sessizlik bir savunmadir).
        if ($honeypotTripped) {
            return $this->silentlyDiscard($attributes);
        }

        // 2. KATMAN — hedef acik mi? Uc kosul (yayinda + modul acik + son tarih
        // gecmemis) TEK yerde soruluyor; ayni uculu misafirin medya yukleme
        // ucunda da gecerli (C3).
        $invitation = $this->resolveOpenInvitation->handle($invitationId);

        $rsvp = $invitation->rsvps()->make($attributes);

        // 🔴 ip_hash #[Fillable] listesinde YOK: toplu atamayla degil, sunucu
        // kodu tarafindan atanir (E7'nin ayni gerekcesi).
        $rsvp->ip_hash = $this->hashIp($ip);

        // 🔴 MEDYA BAGLAMA (Faz 6). Kimlik istemciden geldi ve BICIMSEL olarak
        // dogrulandi ('ulid'), ama MESRU oldugu bilinmiyor. Sahiplik burada
        // soruluyor — yabanci anahtar kisiti "boyle bir medya var mi"
        // sorusunu cevaplar, "BU DAVETIYEYE ait mi" sorusunu cevaplayamaz.
        $rsvp->photo_media_id = $this->resolveGuestMedia(
            $invitation, $mediaIds['photo'], MediaKind::RsvpPhoto,
        );

        $rsvp->video_media_id = $this->resolveGuestMedia(
            $invitation, $mediaIds['video'], MediaKind::RsvpVideo,
        );

        // 4. KATMAN — kota. Kontrol ve yazma AYNI transaction icinde:
        // aralarinda baska bir istek araya girerse ikisi birden kotayi asardi.
        DB::transaction(function () use ($invitation, $rsvp): void {
            $this->assertQuotaAvailable($invitation, $rsvp->guest_count);

            $rsvp->save();
        });

        return $rsvp;
    }

    /**
     * Bot yanitini yutar ama KAYDETMEZ.
     *
     * Donen nesne gercek bir ULID ve zaman damgasi tasir, yani yanit gecerli
     * bir kayittan AYIRT EDILEMEZ — ama hicbir yere yazilmadi. HasUlids
     * kimligi veritabanina gitmeden uretebildigi icin bu mumkun.
     *
     * 🔴 Buradaki tek "kaydedilmedi" kaniti testtir (T14: yaniti degil ETKIYI
     * dogrula). Yanit 201 oldugu icin baska hicbir sey sana soylemez.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function silentlyDiscard(array $attributes): Rsvp
    {
        $rsvp = new Rsvp($attributes);

        $rsvp->id = $rsvp->newUniqueId();
        $rsvp->created_at = now();
        $rsvp->updated_at = now();

        return $rsvp;
    }

    /**
     * 🔴 Kota COUNT(*) ile DEGIL SUM(guest_count) ile olculur.
     *
     * COUNT(*) olsaydi 100 kayit x 4 kisi = 400 misafir kotayi asmadan gecerdi
     * (docs/09 §Faz 5). Frontend'in LiveRsvpPanel'i de ayni metrigi kullaniyor;
     * iki taraf ayni seyi saymak ZORUNDA.
     *
     * Hangi durumlarin sayildigini enum soyler (K50), bu sorgu degil.
     */
    private function assertQuotaAvailable(Invitation $invitation, int $incomingGuests): void
    {
        $limit = $this->quota->limitFor($invitation);

        // Sinirsiz plan: SORGU BILE ACILMAZ.
        if ($limit === null) {
            return;
        }

        // Ust kaydin satirini kilitle. Es zamanli iki gonderim ayni SUM'i okuyup
        // ikisi de "yer var" diyebilirdi (check-then-act yaris kosulu, Faz 2 E2).
        // PostgreSQL'in varsayilan READ COMMITTED seviyesinde SELECT'ler birbirini
        // beklemez; bu kilit onlari siraya sokar.
        Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

        $used = (int) $invitation->rsvps()
            ->whereIn('status', RsvpStatus::quotaConsumingValues())
            ->sum('guest_count');

        if ($used + $incomingGuests > $limit) {
            throw new RsvpQuotaExceededException;
        }
    }

    /**
     * Istemcinin verdigi medya kimligini DOGRULAR; gecersizse null doner.
     *
     * Iki kosul birden aranir ve ikisi de sorgunun KAPSAMINDA (P3 ailesi):
     *   1. Medya BU davetiyeye ait mi   -> $invitation->media() iliskisi
     *   2. Beklenen TURDE mi            -> where('kind', ...)
     *
     * 🔴 Ikincisi olmasaydi misafir kendi yukledigi rsvp_video kimligini
     * photoMediaId olarak gonderebilir, ya da (davetiyeye ait oldugu icin)
     * SAHIBIN GALERI fotografini kendi yanitina ilistirebilirdi.
     *
     * 🔴 Gecersiz kimlik EXCEPTION FIRLATMAZ, sessizce null olur. Gerekce
     * bir kolaylik degil bir savunma: 403/422 donmek, saldirgana "bu kimlik
     * gecerliydi ama senin degil" ile "bu kimlik hic yok" arasindaki farki
     * ogretirdi — yani media tablosu ULID uzayindan taranabilir hale gelirdi
     * (docs/08 §3.2'nin ayni gerekcesi). Misafir kendi gonderdigi kimligi
     * zaten biliyor; yanitta fotografin gorunmemesi ona yeterli sinyal.
     *
     * Dogrulama kurali olarak yazilamazdi: FormRequest davetiyeyi henuz
     * cozmemistir, dolayisiyla "hangi davetiye" sorusunun cevabi orada YOK.
     */
    private function resolveGuestMedia(Invitation $invitation, ?string $mediaId, MediaKind $kind): ?string
    {
        if ($mediaId === null) {
            return null;
        }

        $belongs = $invitation->media()
            ->whereKey($mediaId)
            ->where('kind', $kind)
            ->exists();

        return $belongs ? $mediaId : null;
    }

    /**
     * KVKK veri minimizasyonu: ham IP asla saklanmaz (CLAUDE.md §3).
     *
     * APP_KEY bir "pepper"dir: yalnizca sha256(ip) yazsaydik saldirgan tum
     * IPv4 uzayinin hash'ini onceden hesaplayip tabloyu geri cozebilirdi.
     * Anahtar karisima girdiginde bu sozluk saldirisi imkansizlasir.
     */
    private function hashIp(string $ip): string
    {
        return hash('sha256', $ip.Config::string('app.key'));
    }
}
