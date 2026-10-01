<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Siparis sahiplik kontrolu (Faz 10, 10.22) — InvitationPolicy'nin kardesi.
 *
 * Yalnizca OKUMA: siparisi olusturan checkout ucu, durumunu degistiren
 * webhook'tur (sistem). Kullanicinin bir siparisi guncellemesi ya da silmesi
 * diye bir yetenek yok ve olmamali — o bir muhasebe kaydidir (K82).
 *
 * 🔴 Reddin 404'e cevrilmesi burada DEGIL, ApiExceptionRenderer'da (H7):
 * baskasinin siparisi ile var olmayan siparis ayirt edilemez.
 * Ayrintili aciklama: docs/rehber/app/Policies/OrderPolicy.md
 */
final class OrderPolicy
{
    /** Liste herkese acik; sorgu zaten kullanicinin kendi kayitlariyla sinirli (P3). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    /**
     * Kati karsilastirma guvenli: Order 'user_id' => 'integer' cast'ini
     * tasiyor, User'in birincil anahtari zaten int (InvitationPolicy ile ayni).
     */
    private function owns(User $user, Order $order): bool
    {
        return $user->id === $order->user_id;
    }
}
