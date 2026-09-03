<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invitation;
use App\Models\User;

/**
 * Davetiye sahiplik kontrolu — IDOR savunmasinin tek karar yeri.
 *
 * 🔴 Reddin 404'e cevrilmesi burada DEGIL, ApiExceptionRenderer'da yapilir (H7):
 * sozlesme karari tek yerde durur, her policy metodunda tekrarlanmaz.
 * Ayrintili aciklama: docs/rehber/app/Policies/InvitationPolicy.md
 */
final class InvitationPolicy
{
    /** Liste herkese acik; sorgu zaten kullanicinin kendi kayitlariyla sinirli. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Faz 7: plan kotasi kontrolu (K43) buraya gelecek. */
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invitation $invitation): bool
    {
        return $this->owns($user, $invitation);
    }

    public function update(User $user, Invitation $invitation): bool
    {
        return $this->owns($user, $invitation);
    }

    /**
     * Yayinlama ve o davetiye icin odeme baslatma yetkisi (Faz 7).
     *
     * 🔴 YALNIZCA SAHIPLIK sorar — plan yeterliligi BURADA sorulmaz.
     * Gerekce: Policy'nin cevabi bir BOOL'dur ve reddi H7 geregi 404'e
     * cevrilir. Paywall reddi ise 402 olmali ve `requiredTier` tasimali;
     * bir bool bu bilgiyi tasiyamaz.
     *
     * Kural boylece iki katmana dogru yerlerinden bolundu:
     *   Policy -> "bu kayit senin mi?"        (yoksa 404, kaynak gizlenir)
     *   Action -> "planin yetiyor mu?"        (yoksa 402, ne almasi gerektigi soylenir)
     *
     * Ayni yetenek CHECKOUT ucunda da kullanilir: bir davetiye icin plan
     * satin almak, yalnizca yayinlayabilecegin davetiye icin anlamlidir.
     */
    public function publish(User $user, Invitation $invitation): bool
    {
        return $this->owns($user, $invitation);
    }

    public function delete(User $user, Invitation $invitation): bool
    {
        return $this->owns($user, $invitation);
    }

    /**
     * Sahiplik yalnizca burada tanimlidir — tek dogruluk kaynagi.
     *
     * Katı karsilastirma guvenli: User::getCasts() birincil anahtari otomatik
     * int'e cevirir, Invitation ise 'user_id' => 'integer' cast'ini tasir.
     */
    private function owns(User $user, Invitation $invitation): bool
    {
        return $user->id === $invitation->user_id;
    }
}
