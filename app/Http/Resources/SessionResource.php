<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\DeviceLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Bir oturumun (Sanctum token'ı) istemciye açık yüzü (Faz 14, 14.9 · K143).
 *
 * 🔴 Beyaz liste: `token` (hash) ve `abilities` ASLA gitmez. Ham User-Agent da
 * gitmez: tarayıcı/sistem adı yeter, ham başlık bir parmak izi.
 * `current`: bu satır isteği yapan oturum mu? — "bu cihaz" rozeti ve kapatma
 * düğmesinin yerine "çıkış yap" yazılması için.
 *
 * @mixin PersonalAccessToken
 */
final class SessionResource extends JsonResource
{
    private ?int $currentId = null;

    public function markCurrent(?int $currentId): self
    {
        $this->currentId = $currentId;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var string|null $agent */
        $agent = $this->getAttribute('user_agent');

        return [
            'id' => (string) $this->id,
            'browser' => DeviceLabel::browser($agent),
            'system' => DeviceLabel::system($agent),
            'createdAt' => $this->created_at?->toIso8601String(),
            'lastUsedAt' => $this->last_used_at?->toIso8601String(),
            'current' => $this->currentId !== null && $this->id === $this->currentId,
        ];
    }
}
