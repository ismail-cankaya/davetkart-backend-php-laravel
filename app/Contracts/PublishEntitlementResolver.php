<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\SubscriptionTier;
use App\Models\Invitation;

/**
 * "Bu davetiye icin SAHIP OLUNAN en yuksek plan nedir?"
 *
 * 🔴 K42'nin tam karsiligi: yayin hakki IKI KAYNAKTAN dogar ama TEK ARAYUZDEN
 * sorulur.
 *
 *   orders.scope = 'account'                        ->  PAKET alim (hesabin tamami)
 *   orders.scope = 'invitation' AND invitation_id = <id>  ->  TEKIL alim
 *
 * 🔴 Faz 9'a kadar bu ayrim `invitation_id IS NULL` ile yapiliyordu ve o
 * ifade IKI ayri olguyu birden anlatiyordu: "paket alindi" ve "tekil
 * siparisin davetiyesi silindi" (nullOnDelete). Silinen bir davetiyenin
 * siparisi bu yuzden SESSIZCE pakete donusup hesap geneline hak veriyordu.
 * Kapsam artik satirda DURUYOR, okumada turetilmiyor (N4).
 *
 * Ucuncu bir durum da bu ayrimla mumkun oldu:
 *   scope = 'invitation' AND invitation_id IS NULL  ->  SERBEST BIRAKILMIS
 * Hicbir davetiyeye hak vermez; sahibi onu yeni bir davetiyeye baglayana
 * kadar bekler (A2.6/A2.7).
 *
 * Arayuz olmasaydi bu iki kol, soruyu soran her yere kopyalanirdi:
 * PublishInvitationAction, SubscriptionRsvpQuotaResolver, ilerideki bir
 * "siparislerim" ekrani. Ucunden birinde paket kolu unutulsaydi, odeme yapmis
 * bir kullanici 402 alirdi ve hata "bazen" gorunurdu — hata ayiklamasi en zor
 * hata sinifi.
 *
 * Neden `?SubscriptionTier` doner, bool degil?
 * "Yayinlayabilir mi?" diye sorsaydik cevap tek bir davetiye icin gecerli
 * olurdu ve KOTA sorusu (Faz 5'in RsvpQuotaResolver'i) ayni kaynagi ikinci
 * kez sorgulamak zorunda kalirdi. Plani donmek iki tuketiciye de hizmet eder
 * (C3): biri "kapsiyor mu" diye sorar, digeri "kotasi ne" diye.
 *
 * `null` = hicbir odeme yok. 'standart' donmek YANLIS olurdu: bedava katman
 * yok, "hicbir sey almamis" ile "en ucuzunu almis" ayni sey degil (Faz 5,
 * ders 45: bir degerin yoklugunu o degerin uzayindaki bir degerle temsil etme).
 * Ayrintili aciklama: docs/rehber/app/Contracts/PublishEntitlementResolver.md
 */
interface PublishEntitlementResolver
{
    /**
     * @return SubscriptionTier|null Sahip olunan en yuksek plan; `null` = odeme yok.
     */
    public function highestTierFor(Invitation $invitation): ?SubscriptionTier;
}
