<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Davetiyenin MISAFIRE acik surumu — types.ts -> Invitation.
 *
 * 🔴 C4: sahibin surumu (InvitationResource) her alani her zaman doner; bu
 * surum KAPALI MODULUN VERISINI HIC GONDERMEZ. `status` ve `updatedAt` de yok:
 * misafirin isine yaramaz, dolayisiyla sozlesmede yeri de yok (C5).
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/PublicInvitationResource.md
 *
 * @mixin Invitation
 */
final class PublicInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invitation' => $this->design(),
        ];
    }

    /**
     * Misafirin gordugu tasarim verisi.
     *
     * @return array<string, mixed>
     */
    private function design(): array
    {
        $design = [
            'title' => $this->title ?? '',
            'subtitle' => $this->subtitle ?? '',
            'names' => $this->names ?? '',

            // Duvar saati: '2026-08-21T19:00', saat dilimi TASIMAZ. Acik soru
            // olarak duruyor — kilavuz §6.
            'date' => $this->event_at?->format('Y-m-d\TH:i') ?? '',

            'venue' => $this->venue ?? '',
            'mapUrl' => $this->map_url ?? '',

            // K41: kolonu yok, preset_id'den turetilir.
            'phoneBackground' => $this->preset_id,
            'imageTheme' => $this->preset_id,

            'categoryId' => $this->category_id,
            'palette' => $this->palette,

            // Bayraklar HER ZAMAN gider: sablon neyi cizecegine bunlara bakarak
            // karar veriyor (InvitationComposition.tsx:128-138).
            'showEnvelope' => $this->show_envelope,
            'showTimer' => $this->show_timer,
            'showTimeline' => $this->show_timeline,
            'showGallery' => $this->show_gallery,
            'showGift' => $this->show_gift,
            'showRSVP' => $this->show_rsvp,
        ];

        // 🔴 C4: kapali modulun VERISI govdeye hic girmez — bos string olarak
        // degil, anahtar olarak da yok. Gerekce ve tehdit modeli: kilavuz §3.
        if ($this->show_timeline) {
            $design['timelineEvents'] = PublicTimelineEventResource::collection($this->timelineEvents);
        }

        if ($this->show_gallery) {
            $design['galleryImages'] = [];   // Faz 6: media tablosundan dolacak
        }

        if ($this->show_gift) {
            $design += [
                'bankName' => $this->bank_name ?? '',
                'accountHolder' => $this->account_holder ?? '',
                'iban' => $this->iban ?? '',
                'giftOptions' => $this->gift_options ?? [],
            ];
        }

        if ($this->show_rsvp) {
            $design += [
                'rsvpDeadline' => $this->rsvp_deadline?->format('Y-m-d') ?? '',
                'askMenuPreference' => $this->ask_menu_preference,
            ];
        }

        return $design;
    }
}
