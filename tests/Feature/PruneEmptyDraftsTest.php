<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Media;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** K261 (A-E2): yalnızca boş, yayınlanmamış, siparişsiz, medyasız ve eski taslak silinir. */
final class PruneEmptyDraftsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_old_empty_drafts_without_orders_or_media_are_deleted(): void
    {
        $empty = $this->emptyDraft();
        $blankNames = $this->emptyDraft(['names' => '   ', 'venue' => null]);
        $named = $this->emptyDraft(['names' => 'Deniz & Can']);
        $dated = $this->emptyDraft(['event_at' => now()->addMonth()]);
        $recent = $this->emptyDraft(['updated_at' => now()->subDay()]);
        $published = Invitation::factory()->published()->create(['names' => '', 'venue' => '', 'event_at' => null, 'updated_at' => now()->subMonth()]);
        $withdrawn = $this->emptyDraft(['published_at' => now()->subMonths(2)]); // iade sonrası yayından kalkmış (K100)
        $withOrder = $this->emptyDraft();
        Order::factory()->forInvitation($withOrder)->create();
        $withMedia = $this->emptyDraft();
        Media::factory()->create(['invitation_id' => $withMedia->id]);

        $this->command(['--older-than' => 7])->expectsOutputToContain('2 boş taslak silindi.')->assertSuccessful()->run();

        $this->assertSoftDeleted($empty);
        $this->assertSoftDeleted($blankNames);

        foreach ([$named, $dated, $recent, $published, $withdrawn, $withOrder, $withMedia] as $kept) {
            $this->assertNotSoftDeleted($kept);
        }
    }

    #[Test]
    public function a_dry_run_counts_without_deleting(): void
    {
        $empty = $this->emptyDraft();

        $this->command(['--dry-run' => true])->expectsOutputToContain('1 boş taslak silinecek (silinmedi).')->assertSuccessful()->run();

        $this->assertNotSoftDeleted($empty);
    }

    #[Test]
    public function an_invalid_age_is_refused(): void
    {
        $empty = $this->emptyDraft();

        $this->command(['--older-than' => '0'])->assertFailed()->run();

        $this->assertNotSoftDeleted($empty);
    }

    /**
     * @param  array<model-property<Invitation>, mixed>  $overrides
     */
    private function emptyDraft(array $overrides = []): Invitation
    {
        return Invitation::factory()->create($overrides + [
            'names' => '',
            'venue' => '',
            'event_at' => null,
            'updated_at' => now()->subMonth(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function command(array $options): PendingCommand
    {
        $command = $this->artisan('invitations:prune-empty-drafts', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
