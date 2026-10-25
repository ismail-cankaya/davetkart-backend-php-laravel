<?php

declare(strict_types=1);

namespace App\Http\Requests\Refund;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/orders/{order}/refund-request — isteğe bağlı açıklama (Faz 14, 14.5).
 *
 * Sahiplik burada DEĞİL, controller'daki Gate'te (OrderPolicy::view): başkasının
 * siparişi 404 (H7). authorize() true, çünkü 403 dönmek kaynağın varlığını sızdırırdı.
 */
final class StoreRefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** Boşluk kırpılır; boş açıklama null olur (iki ayrı "yok" temsili olmasın, N4). */
    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        if (! is_string($reason)) {
            return null;
        }

        $reason = trim($reason);

        return $reason === '' ? null : $reason;
    }
}
