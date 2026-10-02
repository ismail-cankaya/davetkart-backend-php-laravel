<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Enums\MediaKind;
use App\Exceptions\RsvpDeadlinePassedException;
use App\Exceptions\RsvpQuotaExceededException;
use App\Models\Rsvp;
use App\Support\IpHasher;
use App\Support\RsvpEditCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
 *
 * Faz 10 (10.59 · K101): 3. ve 4. katman ResolveGuestMediaAction ve
 * EnsureRsvpQuotaAction'a tasindi (guncelleme de kullaniyor). Yanit artik
 * misafirin duzenleme kodunu da tasiyor (RsvpSubmission).
 * Ayrintili aciklama: docs/rehber/app/Actions/Rsvp/SubmitRsvpAction.md
 */
final class SubmitRsvpAction
{
    public function __construct(
        private readonly ResolveOpenRsvpInvitationAction $resolveOpenInvitation,
        private readonly ResolveGuestMediaAction $guestMedia,
        private readonly EnsureRsvpQuotaAction $quota,
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
    ): RsvpSubmission {
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
        $rsvp->ip_hash = IpHasher::hash($ip);

        // Faz 10 (10.59): misafire bir kez gosterilen kod; satira yalnizca
        // ozeti yazilir.
        $editCode = RsvpEditCode::generate();
        $rsvp->edit_code_hash = RsvpEditCode::hash($editCode);

        // 🔴 MEDYA BAGLAMA (Faz 6). Kimlik istemciden geldi ve BICIMSEL olarak
        // dogrulandi ('ulid'), ama MESRU oldugu bilinmiyor. Sahiplik burada
        // soruluyor — yabanci anahtar kisiti "boyle bir medya var mi"
        // sorusunu cevaplar, "BU DAVETIYEYE ait mi" sorusunu cevaplayamaz.
        $rsvp->photo_media_id = $this->guestMedia->handle(
            $invitation, $mediaIds['photo'], MediaKind::RsvpPhoto,
        );

        $rsvp->video_media_id = $this->guestMedia->handle(
            $invitation, $mediaIds['video'], MediaKind::RsvpVideo,
        );

        // 4. KATMAN — kota. Kontrol ve yazma AYNI transaction icinde:
        // aralarinda baska bir istek araya girerse ikisi birden kotayi asardi.
        DB::transaction(function () use ($invitation, $rsvp): void {
            $this->quota->handle($invitation, $rsvp->guest_count);

            $rsvp->save();
        });

        return new RsvpSubmission($rsvp, $editCode);
    }

    /**
     * Bot yanitini yutar ama KAYDETMEZ.
     *
     * Donen nesne gercek bir ULID ve zaman damgasi tasir, yani yanit gecerli
     * bir kayittan AYIRT EDILEMEZ — ama hicbir yere yazilmadi. HasUlids
     * kimligi veritabanina gitmeden uretebildigi icin bu mumkun. Faz 10:
     * duzenleme kodu da gercek bicimde uretiliyor; kodsuz bir yanit bota
     * "yakalandin" derdi.
     *
     * 🔴 Buradaki tek "kaydedilmedi" kaniti testtir (T14: yaniti degil ETKIYI
     * dogrula). Yanit 201 oldugu icin baska hicbir sey sana soylemez.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function silentlyDiscard(array $attributes): RsvpSubmission
    {
        $rsvp = new Rsvp($attributes);

        $rsvp->id = $rsvp->newUniqueId();
        $rsvp->created_at = now();
        $rsvp->updated_at = now();

        return new RsvpSubmission($rsvp, RsvpEditCode::generate());
    }
}
