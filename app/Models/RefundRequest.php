<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundRequestStatus;
use Database\Factories\RefundRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kullanılmamış bir hakkın iade (iptal) talebi (Faz 14, 14.2 · K138).
 *
 * 🔴 #[Fillable] boş (E7): satırı yalnızca RequestRefundAction açar, kararı
 * yalnızca ResolveRefundRequestAction yazar.
 * Ayrıntılı açıklama: docs/rehber/app/Models/RefundRequest.md
 */
#[Fillable([])]
class RefundRequest extends Model
{
    /** @use HasFactory<RefundRequestFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // HasUlids yüzünden anahtar cast'i otomatik gelmiyor (Faz 3, ders 29).
            'user_id' => 'integer',
            'status' => RefundRequestStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * Karar bekleyen talepler. Kural enum'da (isOpen), kapsam onu SQL'e çevirir.
     *
     * @param  Builder<RefundRequest>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $open = array_values(array_filter(
            RefundRequestStatus::cases(),
            static fn (RefundRequestStatus $status): bool => $status->isOpen(),
        ));

        $query->whereIn('status', array_column($open, 'value'));
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
