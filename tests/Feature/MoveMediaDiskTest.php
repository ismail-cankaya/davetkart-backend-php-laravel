<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A-E6 (K183): public diskte kalmış medya hedef diske kopyalanır, satır çevrilir, misafir önbelleği düşer. */
final class MoveMediaDiskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('s3');
    }

    #[Test]
    public function files_are_copied_to_the_target_disk_and_the_rows_follow(): void
    {
        $invitation = Invitation::factory()->published()->create();
        $media = $this->onPublic($invitation, 'media/gallery/a.jpg', 'resim-baytlari');
        $already = Media::factory()->create(['invitation_id' => $invitation->id, 'disk' => 's3', 'path' => 'media/gallery/b.jpg']);
        Cache::put(Invitation::publicCacheKey($invitation->id), ['eski' => true]);

        $this->command(['--to' => 's3'])->expectsOutputToContain('1 dosya public → s3 taşındı.')->assertSuccessful()->run();

        $this->assertDatabaseHas('media', ['id' => $media->id, 'disk' => 's3']);
        $this->assertDatabaseHas('media', ['id' => $already->id, 'disk' => 's3']);
        $this->assertSame('resim-baytlari', Storage::disk('s3')->get('media/gallery/a.jpg'));
        $this->assertTrue(Storage::disk('public')->exists('media/gallery/a.jpg'), 'Kaynak yalnızca --delete-source ile silinir.');
        $this->assertFalse(Cache::has(Invitation::publicCacheKey($invitation->id)), 'Misafir önbelleği eski URL ile kaldı.');
    }

    #[Test]
    public function the_source_is_deleted_only_when_asked(): void
    {
        $this->onPublic(Invitation::factory()->create(), 'media/gallery/c.jpg', 'x');

        $this->command(['--to' => 's3', '--delete-source' => true])->assertSuccessful()->run();

        $this->assertFalse(Storage::disk('public')->exists('media/gallery/c.jpg'));
        $this->assertTrue(Storage::disk('s3')->exists('media/gallery/c.jpg'));
    }

    #[Test]
    public function a_row_without_its_file_is_reported_and_left_alone(): void
    {
        $lost = Media::factory()->create(['disk' => 'public', 'path' => 'media/gallery/kayip.jpg']);

        $this->command(['--to' => 's3'])->expectsOutputToContain($lost->id)->assertFailed()->run();

        $this->assertDatabaseHas('media', ['id' => $lost->id, 'disk' => 'public']);
    }

    #[Test]
    public function a_dry_run_and_an_invalid_pair_change_nothing(): void
    {
        $media = $this->onPublic(Invitation::factory()->create(), 'media/gallery/d.jpg', 'x');

        $this->command(['--to' => 's3', '--dry-run' => true])->expectsOutputToContain('1 dosya public → s3 taşınacak')->assertSuccessful()->run();
        $this->command(['--to' => 'public'])->assertExitCode(2)->run();
        $this->command(['--to' => 'olmayan-disk'])->assertExitCode(2)->run();

        $this->assertDatabaseHas('media', ['id' => $media->id, 'disk' => 'public']);
        $this->assertFalse(Storage::disk('s3')->exists('media/gallery/d.jpg'));
    }

    private function onPublic(Invitation $invitation, string $path, string $contents): Media
    {
        Storage::disk('public')->put($path, $contents);

        return Media::factory()->create(['invitation_id' => $invitation->id, 'disk' => 'public', 'path' => $path, 'size_bytes' => strlen($contents)]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function command(array $options): PendingCommand
    {
        $command = $this->artisan('media:move-disk', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
