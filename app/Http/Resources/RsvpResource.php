<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Rsvp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bir LCV yaniti — types.ts -> RSVPResponse.
 *
 * 🔴 C1: Resource bir BEYAZ LISTEDIR. `ip_hash` ve `invitation_id` burada
 * gecmiyor ve gecmeyecek:
 *   - ip_hash kisisel veriden turetilmis bir izdir; sahibin de gormesi gereken
 *     bir sey degil (KVKK amac sinirlamasi).
 *   - invitation_id zaten URL'de; govdede tekrarlamak bilgi eklemez.
 *
 * Bu sinif IKI okuyucuya birden hizmet eder — gonderimi yapan misafire (201)
 * ve listeyi ceken sahibe (200). C4 (ayri okuyucu, ayri Resource) burada
 * gerekmiyor: iki taraf da AYNI alanlari gormeli, cunku misafir zaten kendi
 * yazdigi veriyi geri aliyor.
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/RsvpResource.md
 *
 * @mixin Rsvp
 */
final class RsvpResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'guestName' => $this->guest_name,
            'guestCount' => $this->guest_count,

            // Frontend zorunlu string bekliyor (LiveRsvpPanel `|| 'Belirtilmedi'`
            // ile kendi varsayilanini koyuyor); null bos metne donusur.
            'menuPreference' => $this->menu_preference ?? '',

            // Enum degil ham deger: sozlesme metni degil KOD tasir (K21/K49).
            'status' => $this->status->value,

            // 🔴 whenNotNull: alan yoksa ANAHTAR DA YOK. types.ts `message?: string`
            // diyor, yani `string | undefined` — `null` gondermek tip sozlesmesini
            // kirardi. C6'nin ayni ailesi: bos bir alan hala bir alandir.
            'message' => $this->whenNotNull($this->message),

            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
