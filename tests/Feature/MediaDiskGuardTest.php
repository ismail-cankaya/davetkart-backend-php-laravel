<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\MediaKind;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ARC-04 · K183: üretimde medya diski `s3` değilse yükleme 503, okuma çalışır.
 * Ayrintili aciklama: docs/rehber/app/Support/MediaDiskGuard.md
 */
final class MediaDiskGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function production_refuses_the_public_media_disk_for_uploads(): void
    {
        Config::set('davetkart.guest_uploads.enabled', true);
        $this->app->detectEnvironment(fn (): string => 'production');
        Config::set('davetkart.media.disk', 'public');
        Storage::fake('public');
        $logger = Log::spy();

        $user = User::factory()->create();
        $invitation = $this->invitationOf($user);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('invitations.media.store', $invitation), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->image('foto.jpg'),
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', ErrorCode::ProviderUnavailable->value);

        $this->postJson(route('public.invitations.media.store', $invitation), [
            'kind' => MediaKind::RsvpPhoto->value,
            'file' => UploadedFile::fake()->image('ani.jpg'),
        ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', ErrorCode::ProviderUnavailable->value);

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('media', 0);
        $logger->shouldHaveReceived('critical', ['Media upload refused: production media disk is not s3', ['disk' => 'public']]);
    }

    #[Test]
    public function local_environment_still_uses_the_public_disk(): void
    {
        Config::set('davetkart.media.disk', 'public');
        Storage::fake('public');

        $user = User::factory()->create();
        $invitation = $this->invitationOf($user);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('invitations.media.store', $invitation), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->image('foto.jpg'),
            ])
            ->assertCreated();

        $this->assertSame('public', Media::query()->sole()->disk);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function production_with_s3_accepts_uploads(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        Config::set('davetkart.media.disk', 's3');
        Storage::fake('s3');

        $user = User::factory()->create();
        $invitation = $this->invitationOf($user);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('invitations.media.store', $invitation), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->image('foto.jpg'),
            ])
            ->assertCreated();

        $this->assertSame('s3', Media::query()->sole()->disk);
        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    #[Test]
    public function read_endpoints_keep_working_in_production_with_the_public_disk(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        Config::set('davetkart.media.disk', 'public');
        Storage::fake('public');

        $invitation = $this->invitationOf(User::factory()->create());
        $media = Media::factory()->create(['invitation_id' => $invitation->id, 'disk' => 'public']);
        $invitation->gallery_media_ids = [$media->id];
        $invitation->save();

        $this->getJson(route('public.invitations.show', $invitation))->assertOk();
    }

    #[Test]
    public function about_command_shows_the_media_disk_and_the_refusal(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        Config::set('davetkart.media.disk', 'public');

        $this->about()
            ->expectsOutputToContain('UPLOADS REFUSED')
            ->assertSuccessful();

        Config::set('davetkart.media.disk', 's3');

        $this->about()
            ->doesntExpectOutputToContain('UPLOADS REFUSED')
            ->assertSuccessful();
    }

    private function about(): PendingCommand
    {
        $command = $this->artisan('about', ['--only' => 'davetkart']);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function invitationOf(User $user): Invitation
    {
        return Invitation::factory()->published()->create([
            'user_id' => $user->id,
            'show_rsvp' => true,
            'rsvp_deadline' => null,
        ]);
    }
}
