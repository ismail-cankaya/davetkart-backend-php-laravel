<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Models\Invitation;
use App\Models\TimelineEvent;

/**
 * Gelen program listesini mevcut satirlarla esler: ekle / guncelle / sil.
 *
 * 🔴 Aidiyet YAPISAL olarak garanti: mevcut satirlar iliski uzerinden okunur,
 * dolayisiyla eslesme kumesinde baska davetiyenin satiri BULUNAMAZ (K44).
 * Ayrintili aciklama: docs/rehber/app/Actions/Invitation/SyncTimelineEventsAction.md
 */
final class SyncTimelineEventsAction
{
    /**
     * @param  list<array<string, mixed>>  $events
     *
     * @return bool Satir eklendi, guncellendi veya silindiyse true.
     */
    public function handle(Invitation $invitation, array $events): bool
    {
        /** @var array<int, TimelineEvent> $existing */
        $existing = $invitation->timelineEvents()->get()->keyBy('id')->all();

        $keptIds = [];
        $changed = false;

        foreach ($events as $index => $event) {
            $attributes = [
                'time' => $event['time'] ?? null,
                'title' => $event['title'] ?? null,
                'description' => $event['description'] ?? null,
                // Sira, dizideki KONUMDAN gelir; istemci sort_order gondermez.
                'sort_order' => $index,
            ];

            $current = $this->matchExisting($existing, $event['id'] ?? null);

            if ($current === null) {
                $keptIds[] = $invitation->timelineEvents()->create($attributes)->id;
                $changed = true;

                continue;
            }

            $current->fill($attributes);

            // Kisa devre yazmiyoruz: fill() her durumda calismali (A4'un dersi).
            if ($current->isDirty()) {
                $changed = true;
            }

            $current->save();
            $keptIds[] = $current->id;
        }

        // Gelen listede olmayan satirlar kullanici tarafindan silinmistir.
        // Bos $keptIds -> "1 = 1" -> hepsi silinir; istenen davranis budur.
        $deleted = $invitation->timelineEvents()->whereNotIn('id', $keptIds)->delete();

        return $changed || $deleted > 0;
    }

    /**
     * Gelen id mevcut bir satira mi karsilik geliyor?
     *
     * null, 'tl-1' gibi istemci uydurmasi veya bayat bir id -> null doner,
     * yani YENI satir olur. Hata degildir (K44).
     *
     * @param  array<int, TimelineEvent>  $existing
     */
    private function matchExisting(array $existing, mixed $id): ?TimelineEvent
    {
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        return $existing[(int) $id] ?? null;
    }
}
