<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Davetiyenin TASARIM verisi — types.ts -> Invitation ile birebir.
 *
 * Sunucu ustverisi (id, status, updatedAt) burada DEGIL, InvitationResource'ta.
 * Ayrim isteklerdeki { invitation: {...} } sarmaliyla simetriktir.
 *
 * 🔴 Bu SAHIBIN gordugu bicimdir. Misafire acik surum Faz 4'te ayri bir sinif
 * olacak; hediye ve LCV verisi orada kapali modulde MASKELENIR.
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/InvitationPayloadResource.md
 *
 * @mixin Invitation
 */
final class InvitationPayloadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => $this->title ?? '',
            'subtitle' => $this->subtitle ?? '',
            'names' => $this->names ?? '',

            // Frontend <input type="datetime-local"> okuyor: 'Y-m-d\TH:i'.
            // ISO-8601 (saat dilimli) gonderirsek input degeri REDDEDER.
            'date' => $this->event_at?->format('Y-m-d\TH:i') ?? '',

            'venue' => $this->venue ?? '',
            'mapUrl' => $this->map_url ?? '',

            // K41: kolonu yok, preset_id'den turetilir.
            'phoneBackground' => $this->preset_id,
            'imageTheme' => $this->preset_id,

            'categoryId' => $this->category_id,
            'palette' => $this->palette,

            'showEnvelope' => $this->show_envelope,
            'showTimer' => $this->show_timer,
            'showTimeline' => $this->show_timeline,
            'showGallery' => $this->show_gallery,
            'showGift' => $this->show_gift,
            'showRSVP' => $this->show_rsvp,

            'bankName' => $this->bank_name ?? '',
            'accountHolder' => $this->account_holder ?? '',
            'iban' => $this->iban ?? '',
            'giftOptions' => $this->gift_options ?? [],

            'rsvpDeadline' => $this->rsvp_deadline?->format('Y-m-d') ?? '',
            'askMenuPreference' => $this->ask_menu_preference,

            // whenLoaded KULLANILMIYOR: sozlesme bu anahtari ZORUNLU kilar.
            // Iliski yuklenmemisse yerelde LazyLoadingViolation firlar — sessiz
            // yanlis veri yerine gurultulu hata. Gerekcesi kilavuz §5'te.
            'timelineEvents' => TimelineEventResource::collection($this->timelineEvents),

            // Faz 6: media tablosundan dolacak.
            'galleryImages' => [],
        ];
    }
}
