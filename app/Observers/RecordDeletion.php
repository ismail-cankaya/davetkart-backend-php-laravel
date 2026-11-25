<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\DeletionSubject;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\DeletionLog;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * K233 (B-E1): model silmesi COMMIT olduktan sonra gunluge yazilir; geri alinan transaction yazilmaz.
 * Ayrintili aciklama: docs/rehber/app/Observers/RecordDeletion.md
 */
final class RecordDeletion implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly DeletionLog $log) {}

    public function deleted(Model $model): void
    {
        $subject = match (true) {
            $model instanceof User => DeletionSubject::Account,
            $model instanceof Invitation => DeletionSubject::Invitation,
            $model instanceof Rsvp => DeletionSubject::Rsvp,
            $model instanceof Media => DeletionSubject::Media,
            default => null,
        };
        $key = $model->getKey();
        if ($subject === null || ! (is_string($key) || is_int($key))) {
            return;
        }

        try {
            $this->log->record($subject, (string) $key);
        } catch (Throwable $e) {
            // Silme geri alinmaz; satir log dosyasinda kalir, kitap (geri yukleme §7) bunu da okutur.
            Log::critical('Deletion log write failed', ['subject' => $subject->value, 'id' => (string) $key, 'error' => $e->getMessage()]);
        }
    }
}
