<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\InvitationChanged;
use App\Models\Invitation;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Cache;

/**
 * Degisen davetiyenin misafire acik cache girdisini duserir.
 *
 * 🔴 KUYRUGA ALINMAZ (ShouldQueue YOK): temizleme gecikirse misafirler o sure
 * boyunca eski davetiyeyi gorur; kuyruk hic calismiyorsa TTL dolana kadar (6
 * saat) gorur. Temizleme, yazma isleminin ayrilmaz parcasidir.
 *
 * 🔴 ShouldHandleEventsAfterCommit: model olaylari transaction'in ICINDE
 * firlar. Commit'ten once temizlersek, arada gelen bir okuma cache'i ESKI
 * veriyle yeniden doldurabilir. Kilavuz §5.
 * Ayrintili aciklama: docs/rehber/app/Listeners/ClearInvitationCache.md
 */
final class ClearInvitationCache implements ShouldHandleEventsAfterCommit
{
    public function handle(InvitationChanged $event): void
    {
        // Anahtari model uretir (C3): controller'in yazdigi anahtarla birebir
        // ayni olmak zorunda, yoksa forget() sessizce hicbir sey yapmaz.
        Cache::forget(Invitation::publicCacheKey($event->invitation->id));
    }
}
