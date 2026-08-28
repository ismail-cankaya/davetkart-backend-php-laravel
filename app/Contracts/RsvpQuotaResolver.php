<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Invitation;

/**
 * Bir davetiyenin kac misafirlik LCV kotasi oldugunu soyler.
 *
 * 🔴 Bu arayuz bir DIKIS YERIDIR (seam). Kotanin gercek kaynagi abonelik ve
 * satin alma kayitlaridir (K42) ve o kayitlar Faz 7'de dogacak. Faz 5 ise
 * kotayi bugun uygulamak zorunda.
 *
 * Arayuz olmasaydi SubmitRsvpAction bugun config'ten okurdu ve Faz 7'de
 * ACTION'IN ICI degisirdi — yani kota kurali dogru yazilmis olsa bile
 * yeniden test edilmesi gereken bir dosya olurdu. Arayuzle yalnizca BAGLAMA
 * degisir; Action'a hic dokunulmaz.
 *
 * CLAUDE.md §1: bagimliliklar arayuzler uzerinden cozulur.
 * Ayrintili aciklama: docs/rehber/app/Contracts/RsvpQuotaResolver.md
 */
interface RsvpQuotaResolver
{
    /**
     * @return int|null Kota (misafir sayisi). `null` = SINIRSIZ.
     *
     * Neden 0 veya PHP_INT_MAX degil de null? 0 "kota yok" ile "kota sifir"
     * arasinda ayrim birakmazdi; PHP_INT_MAX ise sinirsizligi bir SAYI gibi
     * gostererek "kalan kac?" hesabini anlamsizlastirirdi. null, "bu soru bu
     * plan icin gecersiz" demenin tek durust yoludur.
     */
    public function limitFor(Invitation $invitation): ?int;
}
