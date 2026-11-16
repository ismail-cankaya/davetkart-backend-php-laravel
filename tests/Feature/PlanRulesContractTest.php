<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 18 (18.1 · OPS-01 · K146): plans:export ve contracts/plan-rules.json.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/PlanRulesContractTest.md
 */
final class PlanRulesContractTest extends TestCase
{
    /** Git'in görmediği bir klasör (storage/framework/cache/.gitignore). */
    private const SCRATCH = 'storage/framework/cache/plan-rules-contract-test.json';

    protected function tearDown(): void
    {
        File::delete(base_path(self::SCRATCH));

        parent::tearDown();
    }

    // ------------------------------------------------- depodaki dosya

    /** Kapı: config değişip sözleşme yenilenmediyse bu test (ve composer check) kırmızı. */
    #[Test]
    public function the_plan_rules_contract_is_up_to_date(): void
    {
        $this->plans(['--check' => true])
            ->expectsOutputToContain('Plan kurallari sozlesmesi guncel.')
            ->assertExitCode(Command::SUCCESS);
    }

    /** Fiyat sözleşmeye girmez: frontend onu API'den okur (K128). */
    #[Test]
    public function the_committed_contract_carries_no_price(): void
    {
        $this->assertStringNotContainsString('price', File::get(base_path('contracts/plan-rules.json')));
    }

    // ------------------------------------------------- --check

    /** @return iterable<string, array{string, string|int}> */
    public static function ruleChanges(): iterable
    {
        yield 'yeni bir premium şablon' => ['davetkart.preset_tiers.yeni-sablon', 'gold'];
        yield 'bir şablonun planı' => ['davetkart.preset_tiers.dugun-gokyuzu', 'elit'];
        yield 'bir modülün planı' => ['davetkart.module_tiers.show_gallery', 'gold'];
        yield 'LCV sınırı' => ['davetkart.tiers.standart.rsvp_limit', 250];
        yield 'plan sırası' => ['davetkart.tiers.standart.rank', 5];
        yield 'logosuz yayın planı' => ['davetkart.branding.white_label_tier', 'gold'];
    }

    #[Test]
    #[DataProvider('ruleChanges')]
    public function check_fails_when_a_rule_changes_and_the_file_is_not_regenerated(string $key, string|int $value): void
    {
        $this->export();

        Config::set($key, $value);

        $this->plans(['--check' => true, '--path' => self::SCRATCH])
            ->expectsOutputToContain('guncel degil')
            ->assertExitCode(Command::FAILURE);
    }

    /** OPS-01'in senaryosu: bir premium şablon satırı kaybolur. */
    #[Test]
    public function check_fails_when_a_premium_preset_row_is_removed(): void
    {
        $this->export();

        Config::set('davetkart.preset_tiers', array_slice(Config::array('davetkart.preset_tiers'), 1, null, true));

        $this->plans(['--check' => true, '--path' => self::SCRATCH])
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function check_passes_right_after_an_export(): void
    {
        $this->export();

        $this->plans(['--check' => true, '--path' => self::SCRATCH])
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function check_fails_when_the_file_is_missing(): void
    {
        $this->plans(['--check' => true, '--path' => self::SCRATCH])
            ->expectsOutputToContain('Sozlesme dosyasi yok')
            ->assertExitCode(Command::FAILURE);
    }

    /** --check yalnızca okur: bayat dosyayı sessizce düzeltmez. */
    #[Test]
    public function check_never_writes_the_file(): void
    {
        $this->plans(['--check' => true, '--path' => self::SCRATCH])
            ->assertExitCode(Command::FAILURE);

        $this->assertFileDoesNotExist(base_path(self::SCRATCH));
    }

    // ------------------------------------------------- çıktı

    /** Biçim de sözleşmedir: anahtarlar sıralı, plan sırası rank'e göre, sonda satır sonu. */
    #[Test]
    public function the_export_writes_sorted_rules_without_price_or_timestamp(): void
    {
        Config::set('davetkart.tiers', [
            'standart' => ['rank' => 0, 'price' => 249, 'rsvp_limit' => 100],
            'gold' => ['rank' => 1, 'price' => 399, 'rsvp_limit' => null],
            'elit' => ['rank' => 2, 'price' => 549, 'rsvp_limit' => null],
        ]);
        Config::set('davetkart.module_tiers', ['show_timer' => 'standart', 'show_gallery' => 'elit']);
        Config::set('davetkart.preset_tiers', ['zeta' => 'gold', 'alfa' => 'elit']);
        Config::set('davetkart.branding.white_label_tier', 'elit');

        $this->export();

        $expected = <<<'JSON'
        {
            "moduleTiers": {
                "show_gallery": "elit",
                "show_timer": "standart"
            },
            "presetTiers": {
                "alfa": "elit",
                "zeta": "gold"
            },
            "rsvpLimits": {
                "elit": null,
                "gold": null,
                "standart": 100
            },
            "tierOrder": [
                "standart",
                "gold",
                "elit"
            ],
            "whiteLabelTier": "elit"
        }

        JSON;

        $this->assertSame($expected, File::get(base_path(self::SCRATCH)));
    }

    /** Aynı kurallar, config'teki sıra ve saat ne olursa olsun, bayt bayt aynı dosyayı üretir. */
    #[Test]
    public function the_export_is_deterministic(): void
    {
        $this->export();
        $first = File::get(base_path(self::SCRATCH));

        Config::set('davetkart.preset_tiers', array_reverse(Config::array('davetkart.preset_tiers'), true));
        Config::set('davetkart.module_tiers', array_reverse(Config::array('davetkart.module_tiers'), true));
        $this->travel(3)->days();

        $this->export();

        $this->assertSame($first, File::get(base_path(self::SCRATCH)));
    }

    /** Plan sırası enum'un yazılış sırasından değil config'teki rank'ten gelir. */
    #[Test]
    public function the_tier_order_follows_the_configured_rank(): void
    {
        Config::set('davetkart.tiers.standart.rank', 5);

        $this->export();

        /** @var array{tierOrder: list<string>} $contract */
        $contract = json_decode(File::get(base_path(self::SCRATCH)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['gold', 'elit', 'standart'], $contract['tierOrder']);
    }

    // ------------------------------------------------- bozuk config

    /** @return iterable<string, array{string}> */
    public static function tierKeys(): iterable
    {
        yield 'modül' => ['davetkart.module_tiers.show_gallery'];
        yield 'şablon' => ['davetkart.preset_tiers.dugun-gokyuzu'];
        yield 'logosuz yayın' => ['davetkart.branding.white_label_tier'];
    }

    /** Tanınmayan plan adı sözleşmeye yazılmaz; TierResolver ile aynı refleks. */
    #[Test]
    #[DataProvider('tierKeys')]
    public function an_unknown_tier_in_the_config_fails_loudly(string $key): void
    {
        Config::set($key, 'platin');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Configuration error: {$key} is not a valid tier.");

        $this->plans(['--path' => self::SCRATCH])->run();
    }

    private function export(): void
    {
        $this->plans(['--path' => self::SCRATCH])->assertExitCode(Command::SUCCESS);
    }

    /** @param  array<string, mixed>  $parameters */
    private function plans(array $parameters): PendingCommand
    {
        $command = $this->artisan('plans:export', $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
