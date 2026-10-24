<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\GiftCode\GenerateGiftCodeBatchAction;
use App\Actions\GiftCode\GiftCodeSpec;
use App\Enums\GiftCodeBenefit;
use App\Enums\SubscriptionTier;
use App\Models\GiftCode;
use App\Support\GiftCodes\GiftCodeFormat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 11: gift-codes:create ve gift-codes:generate.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/CreateGiftCode.md
 */
final class GiftCodeCreateCommandsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------- gift-codes:create

    #[Test]
    public function it_creates_a_percent_code_without_storing_the_plain_code(): void
    {
        $this->command('gift-codes:create', [
            'code' => 'dugun-2026',
            '--benefit' => 'percent',
            '--percent' => '20',
            '--max-uses' => '50',
            '--label' => 'Instagram cekilisi',
        ])->assertExitCode(Command::SUCCESS);

        $code = GiftCode::query()->sole();
        $this->assertSame(GiftCodeFormat::hash('DUGUN2026'), $code->code_hash);
        $this->assertSame('2026', $code->code_hint);
        $this->assertSame(GiftCodeBenefit::Percent, $code->benefit);
        $this->assertNull($code->tier);
        $this->assertSame(20, $code->percent_off);
        $this->assertSame(50, $code->max_redemptions);
        $this->assertSame('Instagram cekilisi', $code->label);

        // 🔴 D3: düz kod (hiçbir yazılışıyla) satırda yok.
        $row = (string) json_encode(DB::table('gift_codes')->get());
        $this->assertStringNotContainsString('DUGUN2026', $row);
        $this->assertStringNotContainsString('dugun-2026', $row);
    }

    #[Test]
    public function it_creates_a_free_code_for_a_tier_with_a_single_use_by_default(): void
    {
        $this->command('gift-codes:create', ['code' => 'HEDIYE01', '--benefit' => 'free', '--tier' => 'gold'])
            ->assertExitCode(Command::SUCCESS);

        $code = GiftCode::query()->sole();
        $this->assertSame(SubscriptionTier::Gold, $code->tier);
        $this->assertSame(1, $code->max_redemptions);
        $this->assertNull($code->expires_at);
    }

    /** @return array<string, array{string, int}> */
    public static function amounts(): array
    {
        return [
            'tam lira' => ['150', 15000],
            'noktalı kuruş' => ['150.50', 15050],
            'virgüllü kuruş' => ['150,50', 15050],
            'tek ondalık' => ['150.5', 15050],
            '0.10 + 0.20 sorunu yok' => ['0.30', 30],
            'bir kuruş' => ['0.01', 1],
        ];
    }

    /** 🔴 Float yok: "150.50" kuruşa tam sayı aritmetiğiyle çevrilir. */
    #[Test]
    #[DataProvider('amounts')]
    public function fixed_amounts_are_parsed_to_kurus_without_floats(string $amount, int $minor): void
    {
        $this->command('gift-codes:create', ['code' => 'SABIT001', '--benefit' => 'fixed', '--amount' => $amount])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame($minor, GiftCode::query()->sole()->amount_off_minor);
    }

    #[Test]
    public function the_expiry_is_the_end_of_that_day_in_istanbul(): void
    {
        Config::set('davetkart.default_timezone', 'Europe/Istanbul');
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00', 'UTC'));

        $this->command('gift-codes:create', ['code' => 'SURELI01', '--benefit' => 'percent', '--percent' => '10', '--expires' => '2026-12-31'])
            ->assertExitCode(Command::SUCCESS);

        // İstanbul 31 Aralık 23:59:59 = UTC 20:59:59.
        $expires = GiftCode::query()->sole()->expires_at;
        $this->assertNotNull($expires);
        $this->assertSame('2026-12-31 20:59:59', $expires->utc()->format('Y-m-d H:i:s'));

        $this->travelTo(CarbonImmutable::parse('2026-12-31 20:59:58', 'UTC'));
        $this->assertFalse(GiftCode::query()->sole()->isExpired());
        $this->travelTo(CarbonImmutable::parse('2026-12-31 21:00:00', 'UTC'));
        $this->assertTrue(GiftCode::query()->sole()->isExpired());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function invalidOptions(): array
    {
        return [
            'fayda yok' => [['--percent' => '10']],
            'bilinmeyen fayda' => [['--benefit' => 'bogo']],
            'free + percent' => [['--benefit' => 'free', '--tier' => 'gold', '--percent' => '10']],
            'free + amount' => [['--benefit' => 'free', '--tier' => 'gold', '--amount' => '10']],
            'free plansız' => [['--benefit' => 'free']],
            'percent + amount' => [['--benefit' => 'percent', '--percent' => '10', '--amount' => '10']],
            'percent yok' => [['--benefit' => 'percent']],
            'percent 0' => [['--benefit' => 'percent', '--percent' => '0']],
            'percent 101' => [['--benefit' => 'percent', '--percent' => '101']],
            'percent ondalık' => [['--benefit' => 'percent', '--percent' => '12.5']],
            'fixed + percent' => [['--benefit' => 'fixed', '--amount' => '10', '--percent' => '10']],
            'fixed tutarsız' => [['--benefit' => 'fixed']],
            'fixed 0' => [['--benefit' => 'fixed', '--amount' => '0']],
            'fixed 3 ondalık' => [['--benefit' => 'fixed', '--amount' => '1.005']],
            'fixed eksi' => [['--benefit' => 'fixed', '--amount' => '-5']],
            'fixed bilimsel' => [['--benefit' => 'fixed', '--amount' => '1e3']],
            'bilinmeyen plan' => [['--benefit' => 'percent', '--percent' => '10', '--tier' => 'platin']],
            'max 0' => [['--benefit' => 'percent', '--percent' => '10', '--max-uses' => '0']],
            'max metin' => [['--benefit' => 'percent', '--percent' => '10', '--max-uses' => 'cok']],
            'tarih biçimi' => [['--benefit' => 'percent', '--percent' => '10', '--expires' => '31.12.2026']],
            'olmayan gün' => [['--benefit' => 'percent', '--percent' => '10', '--expires' => '2027-02-31']],
            'geçmiş tarih' => [['--benefit' => 'percent', '--percent' => '10', '--expires' => '2020-01-01']],
            'uzun etiket' => [['--benefit' => 'percent', '--percent' => '10', '--label' => str_repeat('x', 121)]],
        ];
    }

    /** @param  array<string, string>  $options */
    #[Test]
    #[DataProvider('invalidOptions')]
    public function invalid_option_combinations_are_rejected_with_a_message(array $options): void
    {
        $this->command('gift-codes:create', ['code' => 'KOD12345', ...$options])
            ->assertExitCode(Command::INVALID);

        $this->assertSame(0, GiftCode::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function invalidCustomCodes(): array
    {
        return ['kısa' => ['ABC'], 'Türkçe harf' => ['DÜĞÜN26'], 'alt çizgi' => ['DUGUN_26'], 'uzun' => [str_repeat('A', 33)]];
    }

    #[Test]
    #[DataProvider('invalidCustomCodes')]
    public function a_malformed_custom_code_is_rejected(string $code): void
    {
        $this->command('gift-codes:create', ['code' => $code, '--benefit' => 'percent', '--percent' => '10'])
            ->assertExitCode(Command::INVALID);

        $this->assertSame(0, GiftCode::query()->count());
    }

    #[Test]
    public function an_existing_code_in_any_spelling_is_rejected(): void
    {
        $this->command('gift-codes:create', ['code' => 'DUGUN2026', '--benefit' => 'percent', '--percent' => '10'])
            ->assertExitCode(Command::SUCCESS);

        $this->command('gift-codes:create', ['code' => 'dugun 2026', '--benefit' => 'fixed', '--amount' => '50'])
            ->expectsOutputToContain('zaten var')
            ->assertExitCode(Command::INVALID);

        $this->assertSame(1, GiftCode::query()->count());
    }

    // ------------------------------------------------- gift-codes:generate

    #[Test]
    public function it_generates_single_use_codes_into_a_csv_and_prints_none_of_them(): void
    {
        Storage::fake('local');

        $this->command('gift-codes:generate', [
            '--count' => '25',
            '--benefit' => 'free',
            '--tier' => 'elit',
            '--label' => 'Fuar 2026',
        ])
            ->expectsOutputToContain('25 tek kullanimlik kod uretildi')
            ->doesntExpectOutputToContain('DK-')
            ->assertExitCode(Command::SUCCESS);

        $codes = GiftCode::query()->get();
        $this->assertCount(25, $codes);
        $this->assertCount(1, $codes->pluck('batch_id')->unique());

        foreach ($codes as $code) {
            $this->assertSame(1, $code->max_redemptions);
            $this->assertSame(GiftCodeBenefit::Free, $code->benefit);
            $this->assertSame(SubscriptionTier::Elit, $code->tier);
        }

        $batchId = (string) $codes->first()?->batch_id;
        $path = GenerateGiftCodeBatchAction::DIRECTORY.'/'.$batchId.'.csv';
        Storage::disk('local')->assertExists($path);

        $lines = array_values(array_filter(explode("\n", (string) Storage::disk('local')->get($path))));
        $this->assertSame('code,benefit,tier,percent_off,amount_off_minor,expires_at_utc,label,batch_id', $lines[0]);
        $this->assertCount(26, $lines);

        // CSV'deki her kod veritabanındaki bir özete karşılık gelir; düz hali veritabanında yok.
        $hashes = $codes->pluck('code_hash')->all();
        $dump = (string) json_encode(DB::table('gift_codes')->get());

        foreach (array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line, escape: '');
            $plain = (string) $cells[0];
            $this->assertMatchesRegularExpression('/^DK-[2-9A-HJKMNP-TV-Z]{4}(-[2-9A-HJKMNP-TV-Z]{4}){2}$/', $plain);
            $this->assertContains(GiftCodeFormat::hash($plain), $hashes);
            $this->assertStringNotContainsString(GiftCodeFormat::normalize($plain), $dump);
            $this->assertSame(['free', 'elit', '', '', '', 'Fuar 2026', $batchId], array_slice($cells, 1));
        }
    }

    #[Test]
    public function generated_fixed_codes_carry_the_amount_in_kurus(): void
    {
        Storage::fake('local');

        $this->command('gift-codes:generate', ['--count' => '2', '--benefit' => 'fixed', '--amount' => '99.90'])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame([9990, 9990], GiftCode::query()->pluck('amount_off_minor')->all());
    }

    /** @return array<string, array{string}> */
    public static function invalidCounts(): array
    {
        return ['sıfır' => ['0'], 'sınırın üstü' => ['1001'], 'metin' => ['elli'], 'eksi' => ['-3']];
    }

    #[Test]
    #[DataProvider('invalidCounts')]
    public function the_count_is_bounded(string $count): void
    {
        Storage::fake('local');

        $this->command('gift-codes:generate', ['--count' => $count, '--benefit' => 'percent', '--percent' => '10'])
            ->assertExitCode(Command::INVALID);

        $this->assertSame(0, GiftCode::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** CSV yazılamazsa kimsenin bilmediği kodlar veritabanında kalmaz. */
    #[Test]
    public function a_failed_csv_write_rolls_the_codes_back(): void
    {
        // put() false döner: disk dolu ya da izin yok.
        Storage::set('local', Mockery::mock(Filesystem::class, ['put' => false, 'delete' => true]));

        try {
            app(GenerateGiftCodeBatchAction::class)->handle(
                3,
                GiftCodeSpec::fromOptions('percent', null, '10', null, '1', null, null, 'Europe/Istanbul'),
            );
            $this->fail('RuntimeException bekleniyordu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CSV yazilamadi', $e->getMessage());
        }

        $this->assertSame(0, GiftCode::query()->count());
    }

    #[Test]
    public function a_spreadsheet_formula_in_the_label_is_neutralized(): void
    {
        Storage::fake('local');

        $this->command('gift-codes:generate', ['--count' => '1', '--benefit' => 'percent', '--percent' => '10', '--label' => '=HYPERLINK("x")'])
            ->assertExitCode(Command::SUCCESS);

        $file = Storage::disk('local')->allFiles()[0];
        $this->assertStringContainsString("'=HYPERLINK", (string) Storage::disk('local')->get($file));
    }

    // ------------------------------------------------- yardımcı

    /** @param  array<string, mixed>  $parameters */
    private function command(string $name, array $parameters): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
