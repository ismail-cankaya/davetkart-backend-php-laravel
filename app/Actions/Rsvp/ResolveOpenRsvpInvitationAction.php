<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Actions\Invitation\ResolvePublicInvitationAction;
use App\Exceptions\RsvpDeadlinePassedException;
use App\Models\Invitation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;

/**
 * "Kimligi bilinmeyen biri bu davetiyeye LCV YAZABILIR MI?" — tek cevap yeri.
 *
 * Ac olmanin UC kosulu var ve ucu de birlikte sorulur:
 *   1. Davetiye YAYINDA mi        -> ResolvePublicInvitationAction (sorgu kapsami)
 *   2. LCV MODULU acik mi         -> show_rsvp
 *   3. SON TARIH gecmemis mi      -> rsvp_deadline
 *
 * 🔴 Bu Action Faz 6'da dogdu ama kural Faz 5'te yazilmisti: uc kontrol
 * SubmitRsvpAction'in govdesindeydi. Misafirin MEDYA yukleme ucu ayni uc
 * kosulu istedigi icin buraya cikarildi — C3: ayni sozlesmeyi ureten iki uc
 * tek yerden uretir.
 *
 * Kopyalasaydik su delik acilirdi: son tarih kontrolu yalnizca LCV'de kalir,
 * suresi dolmus bir davetiyeye misafir SINIRSIZ SURE boyunca dosya yuklerdi
 * (kota basina ~2.4 GB). Ve iki kopyadan birini gunceleyip digerini unutmak,
 * tek yeri yazmaktan daha olasidir (P1'in ayni ailesi).
 *
 * Ne YAPMAZ: honeypot (yalnizca LCV'ye ozgu), kota (her ucun kendi metrigi
 * var), yazma. Yalnizca "hedef acik mi" sorusunu cevaplar ve DAVETIYEYI doner.
 * Ayrintili aciklama: docs/rehber/app/Actions/Rsvp/ResolveOpenRsvpInvitationAction.md
 */
final class ResolveOpenRsvpInvitationAction
{
    public function __construct(
        private readonly ResolvePublicInvitationAction $resolvePublic,
    ) {}

    /**
     * @param  string  $invitationId  Paylasilan linkteki ULID (K40)
     *
     * @throws ModelNotFoundException Yok / yayinda degil / LCV kapali -> 404
     * @throws RsvpDeadlinePassedException Son tarih gecti -> 403
     */
    public function handle(string $invitationId): Invitation
    {
        // 1. Gorunurluk: yayinda olmayan davetiye sorgudan HIC cikmaz (P3 ailesi).
        $invitation = $this->resolvePublic->handle($invitationId);

        // 2. Modul kapaliysa uc "yok" sayilir. Bos yanit degil 404: kapali
        // modulun VARLIGI da bir bilgidir ve misafir onu zaten gormedi (C6).
        if (! $invitation->show_rsvp) {
            throw (new ModelNotFoundException)->setModel(Invitation::class, [$invitationId]);
        }

        // 3. Zaman. 404 DEGIL 403: davetiyenin varligi zaten herkese acik,
        // gizlenecek bir sey yok (H7'nin gerekcesi burada gecerli degil).
        $this->assertDeadlineNotPassed($invitation);

        return $invitation;
    }

    /**
     * `rsvp_deadline` bir TARIHTIR (saat tasimaz) ve son gun DAHILDIR.
     *
     * 🔴 E8 / ders 43: `$deadline->isPast()` YAZILAMAZ. Tarih kolonu gunun
     * 00:00'ina denk gelir; isPast() son gun boyunca true doner ve misafirler
     * BIR GUN ERKEN kapida kalirdi.
     *
     * 🔴 K63 (Faz 7): karsilastirma artik SUNUCUNUN degil DAVETIYENIN saat
     * diliminde yapiliyor. Faz 6'da B6 geregi acikca yazilmisti:
     * "farkli saat dilimindeki misafir icin sinir bir gun kayabilir". O borc
     * burada kapandi.
     *
     * Karsilastirma TARIH DIZESI uzerinden: iki tarafi da 'Y-m-d' yapip
     * kiyaslamak, tarih-tipli bir degeri saat dilimi donusumune sokmaktan
     * daha dogrudur. Bir tarihin saat dilimi yoktur — "21 Agustos" her yerde
     * 21 Agustos'tur; degisen, O ANDA hangi gunde OLUNDUGUDUR.
     */
    private function assertDeadlineNotPassed(Invitation $invitation): void
    {
        $deadline = $invitation->rsvp_deadline;

        // Son tarih belirtilmemisse sinir yok — sahip bilerek acik birakti.
        if ($deadline === null) {
            return;
        }

        $timezone = $invitation->timezone ?? Config::string('davetkart.default_timezone');

        // Davetiyenin bulundugu yerde BUGUN hangi gun?
        $today = CarbonImmutable::now($timezone)->toDateString();

        if ($deadline->toDateString() < $today) {
            throw new RsvpDeadlinePassedException;
        }
    }
}
