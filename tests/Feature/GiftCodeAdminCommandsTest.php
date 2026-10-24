<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SubscriptionTier;
use App\Models\GiftCode;
use App\Models\GiftCodeRedemption;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 11: gift-codes:list ve gift-codes:disable.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/ListGiftCodes.md
 */
final class GiftCodeAdminCommandsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------- gift-codes:list

    #[Test]
    public function it_lists_codes_with_their_derived_active_counts(): void
    {
        $code = GiftCode::factory()->withCode('DUGUN2026')->percent(20)->maxUses(5)->create(['label' => 'Instagram']);
        GiftCodeRedemption::factory()->for($code)->forOrder(Order::factory()->paid()->create())->create();
        GiftCodeRedemption::factory()->for($code)->forOrder(Order::factory()->create())->create();               // pending
        GiftCodeRedemption::factory()->for($code)->forOrder(Order::factory()->failed()->create())->create();     // iade
        GiftCodeRedemption::factory()->for($code)->forOrder(Order::factory()->create(['status' => OrderStatus::Expired]))->create();

        $row = $this->rowOf($this->listOutput(), $code->id);

        foreach (['…2026 · Instagram', '%20', 'hepsi', '2/5', 'suresiz', 'aktif'] as $cell) {
            $this->assertStringContainsString($cell, $row);
        }
    }

    #[Test]
    public function it_formats_every_benefit_and_status(): void
    {
        $free = GiftCode::factory()->withCode('FREE0001')->free(SubscriptionTier::Gold)->create();
        $fixed = GiftCode::factory()->withCode('SABIT001')->fixed(15050)->disabled()->create();
        $old = GiftCode::factory()->withCode('ESKI0001')->expired()->create();
        $used = GiftCode::factory()->withCode('BITTI001')->maxUses(1)->create();
        GiftCodeRedemption::factory()->for($used)->forOrder(Order::factory()->paid()->create())->create();

        $output = $this->listOutput();

        $this->assertStringContainsString('ucretsiz', $this->rowOf($output, $free->id));
        $this->assertStringContainsString('gold', $this->rowOf($output, $free->id));
        $this->assertStringContainsString('150,50 TL', $this->rowOf($output, $fixed->id));
        $this->assertStringContainsString('kapali', $this->rowOf($output, $fixed->id));
        $this->assertStringContainsString('suresi doldu', $this->rowOf($output, $old->id));
        $this->assertStringContainsString('tukendi', $this->rowOf($output, $used->id));
    }

    #[Test]
    public function the_active_filter_hides_disabled_expired_and_exhausted_codes(): void
    {
        $active = GiftCode::factory()->withCode('AKTIF001')->create();
        $disabled = GiftCode::factory()->withCode('KAPALI01')->disabled()->create();
        $expired = GiftCode::factory()->withCode('ESKI0001')->expired()->create();
        $exhausted = GiftCode::factory()->withCode('BITTI001')->maxUses(1)->create();
        GiftCodeRedemption::factory()->for($exhausted)->forOrder(Order::factory()->paid()->create())->create();

        $this->command('gift-codes:list', ['--active' => true])
            ->expectsOutputToContain($active->id)
            ->doesntExpectOutputToContain($disabled->id)
            ->doesntExpectOutputToContain($expired->id)
            ->doesntExpectOutputToContain($exhausted->id)
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function the_limit_applies_after_the_active_filter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00'));
        $older = GiftCode::factory()->withCode('ESKIAKTIF')->create();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00'));
        $newerExhausted = GiftCode::factory()->withCode('YENIBITTI')->maxUses(1)->create();
        GiftCodeRedemption::factory()->for($newerExhausted)->forOrder(Order::factory()->paid()->create())->create();

        $this->command('gift-codes:list', ['--active' => true, '--limit' => '1'])
            ->expectsOutputToContain($older->id)
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function the_batch_filter_shows_only_that_batch(): void
    {
        $batch = strtolower((string) Str::ulid());
        $inBatch = GiftCode::factory()->create(['batch_id' => $batch]);
        $other = GiftCode::factory()->create();

        $this->command('gift-codes:list', ['--batch' => strtoupper($batch)])
            ->expectsOutputToContain($inBatch->id)
            ->doesntExpectOutputToContain($other->id)
            ->assertExitCode(Command::SUCCESS);

        $this->command('gift-codes:list', ['--batch' => 'batch-degil'])
            ->assertExitCode(Command::INVALID);
    }

    /** N+1 yok: kod sayısından bağımsız, sabit sayıda sorgu. */
    #[Test]
    public function counting_runs_in_a_single_query_regardless_of_the_code_count(): void
    {
        foreach (range(1, 15) as $i) {
            $code = GiftCode::factory()->create();
            GiftCodeRedemption::factory()->for($code)->forOrder(Order::factory()->paid()->create())->create();
        }

        DB::enableQueryLog();
        $this->command('gift-codes:list')->assertExitCode(Command::SUCCESS);

        $selects = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains((string) $query['query'], 'gift_code'),
        );
        $this->assertCount(1, $selects);
    }

    #[Test]
    public function control_characters_in_the_label_are_made_visible(): void
    {
        GiftCode::factory()->create(['label' => "Kampanya\e[2J"]);

        $this->command('gift-codes:list')
            ->expectsOutputToContain('Kampanya\u001b[2J')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function an_empty_list_says_so(): void
    {
        $this->command('gift-codes:list')
            ->expectsOutputToContain('Kod yok')
            ->assertExitCode(Command::SUCCESS);
    }

    // ------------------------------------------------- gift-codes:disable

    #[Test]
    public function it_disables_a_code_without_deleting_it(): void
    {
        $code = GiftCode::factory()->withCode('DUGUN2026')->create();
        GiftCodeRedemption::factory()->for($code)->forOrder(Order::factory()->paid()->create())->create();

        $this->command('gift-codes:disable', ['id' => strtoupper($code->id)])
            ->expectsOutputToContain('Kod kapatildi: …2026')
            ->assertExitCode(Command::SUCCESS);

        $code->refresh();
        $this->assertTrue($code->isDisabled());
        $this->assertSame(1, GiftCode::query()->count());
        $this->assertSame(1, GiftCodeRedemption::query()->count());

        // Ödenmiş sipariş yerinde.
        $this->assertSame(OrderStatus::Paid, Order::query()->sole()->status);
    }

    #[Test]
    public function disabling_twice_keeps_the_first_timestamp(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', 'UTC'));
        $code = GiftCode::factory()->create();
        $this->command('gift-codes:disable', ['id' => $code->id])->assertExitCode(Command::SUCCESS);
        $first = $code->refresh()->disabled_at;

        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00', 'UTC'));
        $this->command('gift-codes:disable', ['id' => $code->id])
            ->expectsOutputToContain('zaten kapali')
            ->assertExitCode(Command::SUCCESS);

        $this->assertEquals($first, $code->refresh()->disabled_at);
    }

    #[Test]
    public function an_unknown_or_malformed_id_fails(): void
    {
        $this->command('gift-codes:disable', ['id' => (string) Str::ulid()])
            ->expectsOutputToContain('Bu kimlikle bir kod yok')
            ->assertExitCode(Command::FAILURE);

        $this->command('gift-codes:disable', ['id' => 'DUGUN2026'])
            ->assertExitCode(Command::FAILURE);
    }

    // ------------------------------------------------- yardımcı

    /** Tablonun tamamı tek metin: bir satırdaki birden çok hücre birlikte sınanabilsin. */
    private function listOutput(): string
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('gift-codes:list'));

        return Artisan::output();
    }

    private function rowOf(string $output, string $id): string
    {
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, $id)) {
                return $line;
            }
        }

        $this->fail("Satir yok: {$id}");
    }

    /** @param  array<string, mixed>  $parameters */
    private function command(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
