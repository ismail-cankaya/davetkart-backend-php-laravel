<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Auth\DeleteAccountAction;
use App\Actions\Invitation\DeleteInvitationAction;
use App\Enums\DeletionSubject;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\DeletionLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** K233 (B-E1): silme günlüğü ve geri yüklemeden sonra yeniden uygulama. Ayrıntı: docs/rehber/tests/Feature/DeletionLogTest.md */
final class DeletionLogTest extends TestCase
{
    use RefreshDatabase;

    private const BACKUP_AT = '2026-11-20T03:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Config::string('davetkart.media.disk'));
        $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00:00', 'UTC'));
    }

    #[Test]
    public function user_initiated_deletions_are_recorded_with_only_type_id_and_time(): void
    {
        $invitation = Invitation::factory()->create();
        $rsvp = Rsvp::factory()->for($invitation)->create();
        $user = User::factory()->create();

        app(DeleteInvitationAction::class)->handle($invitation);
        $this->travel(1)->seconds();
        $rsvp->delete();
        $this->travel(1)->seconds();
        app(DeleteAccountAction::class)->handle($user);

        $entries = app(DeletionLog::class)->since(CarbonImmutable::parse(self::BACKUP_AT));
        $this->assertSame(
            [[DeletionSubject::Invitation, $invitation->id], [DeletionSubject::Rsvp, $rsvp->id], [DeletionSubject::Account, (string) $user->id]],
            array_map(fn (array $e): array => [$e['subject'], $e['id']], $entries),
        );

        $file = Storage::disk(Config::string('deletion_log.disk'))->allFiles('deletion-log')[0];
        $this->assertSame(['subject', 'id', 'at'], array_keys((array) json_decode((string) Storage::disk(Config::string('deletion_log.disk'))->get($file), true)));
    }

    #[Test]
    public function a_rolled_back_deletion_is_not_recorded(): void
    {
        $rsvp = Rsvp::factory()->create();

        try {
            DB::transaction(function () use ($rsvp): void {
                $rsvp->delete();
                throw new RuntimeException('geri al');
            });
        } catch (RuntimeException) {
        }

        $this->assertModelExists($rsvp);
        $this->assertSame([], app(DeletionLog::class)->since(CarbonImmutable::parse(self::BACKUP_AT)));
    }

    #[Test]
    public function deletions_since_the_backup_are_reapplied_after_a_restore(): void
    {
        $invitation = Invitation::factory()->create();
        $rsvp = Rsvp::factory()->for($invitation)->create();
        $user = User::factory()->create();
        $log = app(DeletionLog::class);

        // "Geri yükleme": satırlar duruyor ama günlükte yedekten sonra silindikleri yazılı.
        $this->travelTo(CarbonImmutable::parse('2026-11-21 13:00:00', 'UTC'));
        $log->record(DeletionSubject::Invitation, $invitation->id);
        $log->record(DeletionSubject::Rsvp, $rsvp->id);
        $log->record(DeletionSubject::Account, (string) $user->id);
        $log->record(DeletionSubject::Rsvp, 'zaten-yok');
        $this->travelTo(CarbonImmutable::parse('2026-11-22 09:00:00', 'UTC'));

        $this->assertSame(0, Artisan::call('data:reapply-deletions', ['--since' => self::BACKUP_AT, '--dry-run' => true]));
        $this->assertStringContainsString('4 kayit okundu: 3 yeniden silindi (yazilmadi), 1 zaten yoktu.', Artisan::output());
        $this->assertModelExists($rsvp);

        $this->assertSame(0, Artisan::call('data:reapply-deletions', ['--since' => self::BACKUP_AT]));

        $this->assertModelMissing($rsvp);
        $this->assertModelMissing($user);
        $trashed = Invitation::onlyTrashed()->findOrFail($invitation->id);
        $this->assertSame('2026-11-21 13:00:00', $trashed->deleted_at?->utc()->format('Y-m-d H:i:s'), 'çöp kutusu zamanı günlükteki');
    }

    #[Test]
    public function entries_before_the_backup_are_ignored(): void
    {
        $rsvp = Rsvp::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 23:00:00', 'UTC'));
        app(DeletionLog::class)->record(DeletionSubject::Rsvp, $rsvp->id);

        Artisan::call('data:reapply-deletions', ['--since' => self::BACKUP_AT]);

        $this->assertModelExists($rsvp);
    }

    #[Test]
    public function a_missing_or_invalid_since_fails_closed(): void
    {
        $this->assertSame(1, Artisan::call('data:reapply-deletions'));
        $this->assertSame(1, Artisan::call('data:reapply-deletions', ['--since' => 'dun']));
    }

    #[Test]
    public function a_gallery_item_leaves_the_invitation_gallery_too(): void
    {
        $invitation = Invitation::factory()->create();
        $media = Media::factory()->for($invitation)->create();
        $invitation->forceFill(['gallery_media_ids' => [$media->id]])->save();
        app(DeletionLog::class)->record(DeletionSubject::Media, $media->id);

        Artisan::call('data:reapply-deletions', ['--since' => self::BACKUP_AT]);

        $this->assertModelMissing($media);
        $this->assertSame([], $invitation->refresh()->galleryMediaIds());
    }

    #[Test]
    public function the_purge_drops_log_days_older_than_the_backup_window(): void
    {
        $log = app(DeletionLog::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        $log->record(DeletionSubject::Rsvp, 'eski');
        $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00:00', 'UTC'));
        $log->record(DeletionSubject::Rsvp, 'yeni');

        Artisan::call('data:purge');

        $ids = array_column($log->since(CarbonImmutable::parse('2026-01-01')), 'id');
        $this->assertSame(['yeni'], $ids);
    }
}
