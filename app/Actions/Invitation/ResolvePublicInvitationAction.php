<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Enums\InvitationStatus;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Misafirin gordugu davetiyeyi cozer: YALNIZCA yayinlanmis kayit doner.
 *
 * Gorunurluk bir `if` degil, sorgunun KAPSAMIDIR (P3 ailesi): yayinlanmamis
 * davetiye buradan hicbir yoldan cikamaz.
 * Ayrintili aciklama: docs/rehber/app/Actions/Invitation/ResolvePublicInvitationAction.md
 */
final class ResolvePublicInvitationAction
{
    /**
     * @param  string  $id  Paylasilan linkteki ULID (K40)
     *
     * @throws ModelNotFoundException Bulunamadi VEYA yayinda degil — ikisi de 404 (H7)
     */
    public function handle(string $id): Invitation
    {
        return Invitation::query()
            ->whereKey($id)
            ->where('status', InvitationStatus::Published)
            // Faz 3 sapmasi surduruluyor: Resource iliskiye DOGRUDAN erisir.
            // Burada yuklenmezse yerelde LazyLoadingViolation firlar (3.9).
            ->with('timelineEvents')
            ->firstOrFail();
    }
}
