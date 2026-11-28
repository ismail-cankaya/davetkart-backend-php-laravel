<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\TextSlots;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `contracts/text-slots.json` (Faz 24, K301): frontend'in ürettiği sözleşmenin backend kopyası.
 * Frontend `verify:text-slots` iki kopyanın birebir aynı olduğunu sınar; burada biçim sınanır.
 */
final class TextSlotsContractTest extends TestCase
{
    #[Test]
    public function the_contract_has_the_expected_shape(): void
    {
        $raw = file_get_contents(base_path('contracts/text-slots.json'));
        $this->assertIsString($raw);

        /** @var array{version: int, locales: list<string>, maxOverrides: int, slots: array<string, array<string, mixed>>} $contract */
        $contract = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['tr'], $contract['locales'], 'v2.0 yalnızca Türkçe (K302)');
        $this->assertGreaterThan(0, $contract['maxOverrides']);
        $this->assertNotEmpty($contract['slots']);

        foreach ($contract['slots'] as $key => $slot) {
            $this->assertMatchesRegularExpression('/^[a-z]+\.[A-Za-z0-9.-]+$/', $key);
            $this->assertSame(explode('.', $key)[0], $slot['group'], $key);
            $this->assertContains($slot['scope'], ['shared', 'category', 'template'], $key);
            $this->assertIsInt($slot['maxLength'], $key);
            $this->assertGreaterThan(0, $slot['maxLength'], $key);
            $this->assertIsBool($slot['multiline'], $key);
        }
    }

    #[Test]
    public function the_reader_answers_from_the_contract(): void
    {
        $this->assertSame(['tr'], TextSlots::locales());
        $this->assertContains('rsvp.submit', TextSlots::keys());
        $this->assertSame(['date'], TextSlots::slot('rsvp.deadline')['placeholders'] ?? null);
        $this->assertNull(TextSlots::slot('hero.unknown'));
    }

    #[Test]
    public function the_rule_accepts_null_and_an_empty_map(): void
    {
        $this->assertTrue(TextSlots::isValidOverrides(null));
        $this->assertTrue(TextSlots::isValidOverrides([]));
        $this->assertTrue(TextSlots::isValidOverrides(['tr' => []]));
    }

    #[Test]
    public function the_rule_caps_the_number_of_texts(): void
    {
        // Sınır dil başına değiştirilen metin sayısıdır, sözleşmedeki anahtar sayısı değil (Faz 26'da 167 anahtar).
        $texts = [];
        foreach (array_slice(TextSlots::keys(), 0, TextSlots::maxOverrides()) as $key) {
            $texts[$key] = $key === 'rsvp.deadline' ? '{date}' : 'x';
        }
        $this->assertCount(min(TextSlots::maxOverrides(), count(TextSlots::keys())), $texts);
        $this->assertTrue(TextSlots::isValidOverrides(['tr' => $texts]));

        $tooMany = [];
        for ($i = 0; $i <= TextSlots::maxOverrides(); $i++) {
            $tooMany['rsvp.submit'.str_repeat(' ', $i)] = 'x';
        }
        $this->assertFalse(TextSlots::isValidOverrides(['tr' => $tooMany]));
    }

    #[Test]
    public function normalize_drops_empty_groups(): void
    {
        $this->assertNull(TextSlots::normalize(null));
        $this->assertNull(TextSlots::normalize(['tr' => []]));
        $this->assertSame(['tr' => ['rsvp.submit' => 'x']], TextSlots::normalize(['tr' => ['rsvp.submit' => 'x']]));
    }
}
