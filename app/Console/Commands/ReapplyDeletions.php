<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Auth\DeleteAccountAction;
use App\Actions\Invitation\DeleteInvitationAction;
use App\Actions\Media\DeleteGalleryMediaAction;
use App\Enums\DeletionSubject;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\DeletionLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * K233 (B-E1): yedekten geri yuklemeden sonra, yedek anindan beri yapilan silmeleri yeniden uygular.
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/ReapplyDeletions.md
 */
final class ReapplyDeletions extends Command
{
    protected $signature = 'data:reapply-deletions
                            {--since= : Geri yuklenen yedegin alindigi an (ISO 8601, UTC onerilir)}
                            {--dry-run : Silme, yalnizca neyin silinecegini say}';

    protected $description = 'Re-apply user deletions recorded since the restored backup (K233)';

    public function handle(
        DeletionLog $log,
        DeleteAccountAction $deleteAccount,
        DeleteInvitationAction $deleteInvitation,
        DeleteGalleryMediaAction $deleteGalleryMedia,
    ): int {
        $since = $this->since();
        if ($since === null) {
            $this->error('--since gerekli ve gecerli bir tarih olmali (ornek: --since=2026-11-20T03:00:00Z).');

            return self::FAILURE;
        }

        $entries = $log->since($since);
        $applied = $missing = 0;

        foreach ($entries as $entry) {
            $done = match ($entry['subject']) {
                DeletionSubject::Account => $this->account($entry['id'], $deleteAccount),
                DeletionSubject::Invitation => $this->invitation($entry['id'], $entry['at'], $deleteInvitation),
                DeletionSubject::Rsvp => $this->rsvp($entry['id']),
                DeletionSubject::Media => $this->media($entry['id'], $deleteGalleryMedia),
            };
            $done ? $applied++ : $missing++;
        }

        $suffix = $this->dryRun() ? ' (yazilmadi)' : '';
        $this->components->info(count($entries)." kayit okundu: {$applied} yeniden silindi{$suffix}, {$missing} zaten yoktu.");

        if (! $this->dryRun()) {
            $this->call('data:purge');
        }

        return self::SUCCESS;
    }

    private function account(string $id, DeleteAccountAction $action): bool
    {
        $user = User::query()->find($id);
        if ($user === null) {
            return false;
        }
        if (! $this->dryRun()) {
            $action->handle($user);
        }

        return true;
    }

    /** Cop kutusu zamani gunluktekiyle yazilir: 30 gunluk sure yeniden baslamaz. */
    private function invitation(string $id, CarbonImmutable $at, DeleteInvitationAction $action): bool
    {
        $invitation = Invitation::query()->find($id);
        if ($invitation === null) {
            return false;
        }
        if (! $this->dryRun()) {
            $action->handle($invitation);
            Invitation::onlyTrashed()->whereKey($id)->update(['deleted_at' => $at]);
        }

        return true;
    }

    private function rsvp(string $id): bool
    {
        $rsvp = Rsvp::query()->find($id);
        if ($rsvp === null) {
            return false;
        }
        if (! $this->dryRun()) {
            $rsvp->delete();
        }

        return true;
    }

    /** Galeri ogesi davetiyenin dizisinden de cikmali (Action); misafir dosyasi dogrudan silinir. */
    private function media(string $id, DeleteGalleryMediaAction $action): bool
    {
        $media = Media::query()->find($id);
        if ($media === null) {
            return false;
        }
        if ($this->dryRun()) {
            return true;
        }

        $invitation = Invitation::withTrashed()->find($media->invitation_id);
        if ($media->kind->isGalleryItem() && $invitation !== null) {
            try {
                $action->handle($invitation, $media->id);

                return true;
            } catch (Throwable) {
                // Dizide degilse asagidaki dogrudan silme yeterli.
            }
        }
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();

        return true;
    }

    private function since(): ?CarbonImmutable
    {
        $raw = $this->option('since');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function dryRun(): bool
    {
        return $this->option('dry-run') === true;
    }
}
