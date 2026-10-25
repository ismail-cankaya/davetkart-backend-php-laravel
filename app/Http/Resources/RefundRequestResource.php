<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RefundRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * İade talebinin istemciye açık yüzü (Faz 14, 14.5). `admin_note` ve `user_id` gitmez.
 *
 * @mixin RefundRequest
 */
final class RefundRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->order_id,
            'status' => $this->status->value,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
