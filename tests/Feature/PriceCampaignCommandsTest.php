<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PromotionKind;
use App\Enums\SubscriptionTier;
use App\Models\Order;
use App\Models\PriceCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 13 (13.3): pricing:campaign:start / list / stop.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/PriceCampaignCommands.md
 */
final class PriceCampaignCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('davetkart.default_timezone', 'Europe/Istanbul');
        // Sabit saat: "bugün" ve "gün sonu" İstanbul saatine göre sınanır.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 10:00:00', 'Europe/Istanbul'));
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00:00', 'Europe/Istanbul'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------- start

    #[Test]
    public function it_starts_a_campaign_for_every_plan_until_the_end_of_the_given_day(): void
    {
        $this->command('pricing:campaign:start', ['percent' => '20', '--until' => '2027-01-07', '--label' => 'Yilbasi'])
            ->expectsOutputToContain('Kampanya acildi.')
            ->assertExitCode(Command::SUCCESS);

        $campaign = PriceCampaign::query()->sole();
        $this->assertSame(20, $campaign->percent);
        $this->assertNull($campaign->tier);
        $this->assertSame('Yilbasi', $campaign->label);
        $this->assertTrue($campaign->starts_at->equalTo(now()));
        $this->assertNotNull($campaign->ends_at);
        $this->assertSame('2027-01-07 23:59:59', $campaign->ends_at->setTimezone('Europe/Istanbul')->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_starts_a_tier_campaign_on_a_later_day(): void
    {
        $this->command('pricing:campaign:start', ['percent' => '30', '--tier' => 'gold', '--from' => '2026-11-01'])
            ->assertExitCode(Command::SUCCESS);

        $campaign = PriceCampaign::query()->sole();
        $this->assertSame(SubscriptionTier::Gold, $campaign->tier);
        $this->assertSame('2026-11-01 00:00', $campaign->starts_at->setTimezone('Europe/Istanbul')->format('Y-m-d H:i'));
        $this->assertNull($campaign->ends_at);
        $this->assertFalse($campaign->isActiveAt(now()->toImmutable()));
    }

    #[Test]
    public function it_warns_that_the_first_publish_discount_is_not_added(): void
    {
        Config::set('davetkart.pricing.first_publish_percent', 60);

        $this->command('pricing:campaign:start', ['percent' => '20'])
            ->expectsOutputToContain('toplanmaz')
            ->assertExitCode(Command::SUCCESS);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidStarts(): array
    {
        return [
            'zero' => [['percent' => '0'], 'Yuzde 1 ile 90'],
            'free is a gift code' => [['percent' => '100'], 'hediye kodu'],
            'not a number' => [['percent' => '%20'], 'Yuzde 1 ile 90'],
            'unknown tier' => [['percent' => '20', '--tier' => 'platin'], '--tier'],
            'bad date' => [['percent' => '20', '--until' => '2027-02-31'], '--until YYYY-MM-DD'],
            'past end' => [['percent' => '20', '--until' => '2026-10-24'], 'gecmiste'],
            'past start' => [['percent' => '20', '--from' => '2026-10-24'], 'gecmiste'],
            'end before start' => [['percent' => '20', '--from' => '2026-11-10', '--until' => '2026-11-01'], 'sonra olmali'],
        ];
    }

    /** @param  array<string, string>  $parameters */
    #[Test]
    #[DataProvider('invalidStarts')]
    public function it_rejects_invalid_options_without_writing_a_row(array $parameters, string $message): void
    {
        $this->command('pricing:campaign:start', $parameters)
            ->expectsOutputToContain($message)
            ->assertExitCode(Command::INVALID);

        $this->assertSame(0, PriceCampaign::query()->count());
    }

    // ------------------------------------------------- stop

    #[Test]
    public function stop_marks_the_campaign_without_deleting_it_and_is_idempotent(): void
    {
        $campaign = PriceCampaign::factory()->create();

        $this->command('pricing:campaign:stop', ['id' => strtoupper($campaign->id)])->assertExitCode(Command::SUCCESS);
        $firstStop = $campaign->refresh()->stopped_at;
        $this->assertNotNull($firstStop);

        $this->travel(5)->minutes();
        $this->command('pricing:campaign:stop', ['id' => $campaign->id])->assertExitCode(Command::SUCCESS);

        $this->assertEquals($firstStop, $campaign->refresh()->stopped_at);
        $this->assertFalse($campaign->isActiveAt(now()->toImmutable()));
    }

    #[Test]
    public function stop_reports_an_unknown_id(): void
    {
        $this->command('pricing:campaign:stop', ['id' => '01jabcdefghjkmnpqrstvwxyz0'])
            ->expectsOutputToContain('kampanya yok')
            ->assertExitCode(Command::INVALID);
    }

    // ------------------------------------------------- list

    #[Test]
    public function list_shows_the_status_and_the_paid_order_count(): void
    {
        $active = PriceCampaign::factory()->percent(25)->forTier(SubscriptionTier::Elit)->create(['label' => 'Elit haftasi']);
        $upcoming = PriceCampaign::factory()->upcoming()->create();
        $ended = PriceCampaign::factory()->ended()->create();
        $stopped = PriceCampaign::factory()->stopped()->create();

        $this->orderFrom($active, paid: true);
        $this->orderFrom($active, paid: false);

        $this->assertSame(Command::SUCCESS, Artisan::call('pricing:campaign:list'));
        $output = Artisan::output();

        foreach (['%25', 'elit', 'GECERLI', 'Elit haftasi'] as $cell) {
            $this->assertStringContainsString($cell, $this->rowOf($output, $active->id));
        }
        // Yalnızca ÖDENMİŞ sipariş sayılır: bekleyen sipariş indirimi "kullanmadı".
        $this->assertMatchesRegularExpression('/\|\s+1\s+\|\s+GECERLI/', $this->rowOf($output, $active->id));
        $this->assertStringContainsString('baslamadi', $this->rowOf($output, $upcoming->id));
        $this->assertStringContainsString('bitti', $this->rowOf($output, $ended->id));
        $this->assertStringContainsString('durduruldu', $this->rowOf($output, $stopped->id));
        $this->assertStringContainsString('Ilk yayin indirimi', $output);
    }

    #[Test]
    public function the_active_filter_shows_only_running_campaigns(): void
    {
        $active = PriceCampaign::factory()->create();
        $ended = PriceCampaign::factory()->ended()->create();
        $stopped = PriceCampaign::factory()->stopped()->create();

        $this->command('pricing:campaign:list', ['--active' => true])
            ->expectsOutputToContain($active->id)
            ->doesntExpectOutputToContain($ended->id)
            ->doesntExpectOutputToContain($stopped->id)
            ->assertExitCode(Command::SUCCESS);
    }

    private function orderFrom(PriceCampaign $campaign, bool $paid): void
    {
        $order = $paid ? Order::factory()->paid()->create() : Order::factory()->create();

        DB::table('orders')->where('id', $order->getKey())->update([
            'discount_minor' => 1000,
            'promotion' => PromotionKind::Campaign->value,
            'promotion_discount_minor' => 1000,
            'price_campaign_id' => $campaign->id,
        ]);
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
