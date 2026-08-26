<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Invitation;

/**
 * Bir davetiyenin MISAFIRE GORUNEN hali degismis olabilir.
 *
 * Model tarafindan yapisal olarak firlatilir ($dispatchesEvents): guncelleme,
 * silme ve geri alma. Boylece yeni bir yazma yolu eklendiginde kimsenin olayi
 * firlatmayi hatirlamasi gerekmez.
 *
 * Olayin adi NE OLDUGUNU soyler, kimin ne yapacagini degil: cache temizleme
 * dinleyicinin karari, olayin degil.
 * Ayrintili aciklama: docs/rehber/app/Events/InvitationChanged.md
 */
final class InvitationChanged
{
    public function __construct(
        public readonly Invitation $invitation,
    ) {}
}
