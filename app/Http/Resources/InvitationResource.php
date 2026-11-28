<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Invitation\PendingChanges;
use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Davetiye kaydi (types.ts -> InvitationRecord): sunucu ustverisi + `invitation` altinda tasarim + taslak (K323).
 * 🔴 YALNIZCA sahibin Resource'u; tarihler ve taslak misafir yanitinda yok (C5).
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/InvitationResource.md
 *
 * @mixin Invitation
 */
final class InvitationResource extends JsonResource
{
    /** Faz 10 (10.66b): imza kararı ResolveBrandingAction'dan gelir; varsayılan göster. */
    /** Taslak kolonu → istek alanı (Faz 27.3). */
    private const PENDING_FIELDS = [
        'category_id' => 'categoryId',
        'preset_id' => 'imageTheme',
        'palette' => 'palette',
        'title' => 'title',
        'subtitle' => 'subtitle',
        'names' => 'names',
        'venue' => 'venue',
        'map_url' => 'mapUrl',
        'event_at' => 'date',
        'timezone' => 'timezone',
        'show_envelope' => 'showEnvelope',
        'show_timer' => 'showTimer',
        'show_timeline' => 'showTimeline',
        'show_gallery' => 'showGallery',
        'show_gift' => 'showGift',
        'show_rsvp' => 'showRSVP',
        'bank_name' => 'bankName',
        'account_holder' => 'accountHolder',
        'iban' => 'iban',
        'gift_options' => 'giftOptions',
        'rsvp_deadline' => 'rsvpDeadline',
        'ask_menu_preference' => 'askMenuPreference',
        'locale' => 'locale',
        'text_overrides' => 'textOverrides',
    ];

    private bool $showBranding = true;

    /** Önizlemede "DavetKart ile hazırlandı" imzası gösterilsin mi? */
    public function withBranding(bool $show): self
    {
        $this->showBranding = $show;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pending = PendingChanges::of($this->resource);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'updatedAt' => $this->updated_at?->toIso8601String(),

            // Faz 10 (10.21): silme uyarisi (frontend F7.2) kesin tarih
            // verebilsin. `updatedAt` vekil OLAMAZ: yayindan sonraki tek bir
            // duzenleme onu tazeler ve kullaniciya yanlis guvence verirdi.
            'publishedAt' => $this->published_at?->toIso8601String(),

            // Bu andan ONCE silinirse tekil siparisin hakki serbest kalir,
            // sonra silinirse yanar (K82). Kural modelde, burada yalnizca
            // gosteriliyor; frontend "3 gun"u bilmek zorunda kalmiyor.
            'releasableUntil' => $this->releaseWindowEndsAt()?->toIso8601String(),

            'invitation' => (new InvitationPayloadResource($this->resource))->withBranding($this->showBranding),

            // Faz 27.3 (K323): yayınlanmamış değişiklikler (yalnızca sahibine; misafir yanıtı bunu taşımaz).
            'hasPendingChanges' => ! $pending->isEmpty(),
            'pendingChanges' => $pending->isEmpty() ? null : [
                'invitation' => (object) $this->pendingAttributes($pending->attributes),
                'timelineEvents' => $pending->timelineEvents,
            ],
        ];
    }

    /**
     * Taslaktaki kolonlar istek alanlarının adlarıyla (açık eşleme; InvitationRequest::COLUMN_MAP'in tersi).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @return array<string, mixed>
     */
    private function pendingAttributes(array $attributes): array
    {
        $fields = [];
        foreach ($attributes as $column => $value) {
            if (isset(self::PENDING_FIELDS[$column])) {
                $fields[self::PENDING_FIELDS[$column]] = $value;
            }
        }

        return $fields;
    }
}
