<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Rsvp;
use App\Models\User;

/**
 * LCV yanitlarinin erisim kontrolu.
 *
 * 🔴 P1: sahiplik kurali BURADA TANIMLANMAZ. Bir LCV yaniti kimseye "ait"
 * degildir — bagli oldugu DAVETIYE birine aittir. Bu yuzden her karar
 * InvitationPolicy'ye devredilir.
 *
 * Kurali kopyalasaydik ($user->id === $rsvp->invitation->user_id) iki yer
 * olurdu; Faz 3'te ogrenildigi gibi bes kopyanin dordunu dogru yazip birini
 * unutmak, tek yeri yazmaktan daha olasidir.
 *
 * Reddin 404'e cevrilmesi yine burada DEGIL, ApiExceptionRenderer'da (P2/H7).
 * Ayrintili aciklama: docs/rehber/app/Policies/RsvpPolicy.md
 */
final class RsvpPolicy
{
    public function __construct(
        private readonly InvitationPolicy $invitations,
    ) {}

    /**
     * Tek bir yaniti okuma.
     *
     * Bugun cagiran yok (liste ucu koleksiyonu sorguyla koruyor, P3). Yine de
     * yaziliyor cunku bu bir OLU KOD degil bir SOZLESME BOSLUGU olurdu:
     * Gate::authorize('view', $rsvp) yazan biri, metot yoksa sessizce
     * AuthorizationException alir ve sebebini aramaya baslar.
     */
    public function view(User $user, Rsvp $rsvp): bool
    {
        $invitation = $rsvp->invitation;

        // Davetiye soft-delete edilmisse iliski null doner (bkz. delete()).
        return $invitation !== null && $this->invitations->view($user, $invitation);
    }

    /**
     * Bir yaniti silme (sahip moderasyonu).
     *
     * Davetiye uzerinde 'view' degil 'update' yetkisi soruluyor: bir misafirin
     * yanitini silmek, davetiyenin verisini DEGISTIRMEKTIR. Bugun ikisi ayni
     * sonucu veriyor, ama Faz 7'de "yayinlanmis davetiye kilitlenir" (K6 /
     * INVITATION_LOCKED) kurali geldiginde ayrisacaklar — ve o gun bu satirin
     * hangi soruyu sordugu onem kazanacak.
     */
    public function delete(User $user, Rsvp $rsvp): bool
    {
        $invitation = $rsvp->invitation;

        // 🔴 Invitation SoftDeletes kullaniyor: davetiye silinince satir kalir
        // ama iliski NULL doner. Kontrol olmasa InvitationPolicy::update()
        // TypeError firlatir ve yetki hatasi 500'e donusurdu.
        // Bu bir kisa devre AMA A4'un yasakladigi tur degil: sag taraf
        // "her durumda calismali mi?" sorusunun cevabi burada HAYIR (ders 27).
        return $invitation !== null && $this->invitations->update($user, $invitation);
    }
}
