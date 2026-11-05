<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Pricing\TierResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 15: frontend'in 2026-11 eklediği 25 şablon kimliği (konsept şablonları
 * ve "Yedi Davetiye"). Backend şablon listesi TUTMAZ (3.2 §4: katalog
 * frontend'in malı); bu dosya o sözleşmenin yeni kimlikler için de tuttuğunu
 * kilitler:
 *
 *   1. Kimlik kaydedilir ve olduğu gibi geri okunur (sahip ve misafir ucu).
 *   2. Hiçbiri premium değildir: premium = arka planı VİDEO olan temalar
 *      (K102, İsmail'in kararı); bu şablonlarda video yok, Standart'ta yayınlanır.
 *
 * Kılavuz: docs/rehber/fazlar/FAZ-15.md
 */
final class TemplateCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const NEW_PRESETS = [
        'dugun-kitap-birinci-bolum', 'dugun-kitap-ic-kapak', 'dugun-harita-kir-yolu', 'dugun-harita-kiyi-kenti',
        'dugun-sergi-beyaz-kup', 'dugun-sergi-duvar-yazisi',
        'nisan-kitap-kurdele', 'nisan-kitap-onsoz', 'nisan-harita-kavusak', 'nisan-harita-kroki',
        'nisan-sergi-bir-omur', 'nisan-sergi-diptik',
        'dogum-gunu-kitap-masal', 'dogum-gunu-kitap-yeni-bolum', 'dogum-gunu-harita-hazine-avi',
        'dogum-gunu-harita-kutlama-hatti', 'dogum-gunu-sergi-devam-eden', 'dogum-gunu-sergi-retrospektif',
        'dugun-kitap-sayfa', 'dugun-gul-3d', 'dugun-popup', 'dugun-murekkep', 'dugun-fener',
        'dugun-holografik', 'dugun-altin-toz',
    ];

    /** @return iterable<string, array{string, string}> */
    public static function newPresets(): iterable
    {
        foreach (self::NEW_PRESETS as $preset) {
            $category = str_starts_with($preset, 'dogum-gunu-') ? 'dogum-gunu' : explode('-', $preset)[0];

            yield $preset => [$preset, $category];
        }
    }

    #[Test]
    #[DataProvider('newPresets')]
    public function a_new_preset_is_stored_and_read_back_unchanged(string $preset, string $category): void
    {
        $owner = User::factory()->create();

        $id = $this->withToken($owner->createToken('api')->plainTextToken)
            ->postJson(route('invitations.store'), [
                'invitation' => [
                    'categoryId' => $category,
                    'imageTheme' => $preset,
                    'palette' => 'midnight',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.invitation.imageTheme', $preset)
            ->assertJsonPath('data.invitation.categoryId', $category)
            ->json('data.id');

        $this->assertDatabaseHas('invitations', ['id' => $id, 'preset_id' => $preset]);
    }

    #[Test]
    #[DataProvider('newPresets')]
    public function a_new_preset_reaches_the_guest_page(string $preset, string $category): void
    {
        $invitation = Invitation::factory()->published()->create(['preset_id' => $preset, 'category_id' => $category]);

        $this->getJson(route('public.invitations.show', $invitation))
            ->assertOk()
            ->assertJsonPath('data.invitation.imageTheme', $preset);
    }

    #[Test]
    #[DataProvider('newPresets')]
    public function a_new_preset_needs_no_premium_plan(string $preset, string $category): void
    {
        $invitation = Invitation::factory()->create(['preset_id' => $preset, 'category_id' => $category]);

        $this->assertSame(SubscriptionTier::Standart, app(TierResolver::class)->requiredFor($invitation));
    }

    /** Liste değişirse (yeni kimlik premium yapılırsa) bu test bilinçli bir karar ister. */
    #[Test]
    public function no_new_preset_is_listed_as_premium(): void
    {
        $premium = array_keys(Config::array('davetkart.preset_tiers'));

        $this->assertSame([], array_values(array_intersect(self::NEW_PRESETS, $premium)));
    }

    // ------------------------------------------------- Faz 17 (on bir şablon)

    /**
     * Faz 17'nin on bir şablonu ve beklenen planları. Dokuzu premium (Gold),
     * ikisi (kapsül makinesi, kazı kazan) her planda yayınlanır.
     *
     * @var array<string, SubscriptionTier>
     */
    private const FAZ17_PRESETS = [
        'kurumsal-lansman' => SubscriptionTier::Gold,
        'kina-bugulu-ayna' => SubscriptionTier::Gold,
        'sunnet-ucan-hali' => SubscriptionTier::Gold,
        'parti-lazer' => SubscriptionTier::Gold,
        'dugun-kelebek' => SubscriptionTier::Gold,
        'dugun-karanlik-oda' => SubscriptionTier::Gold,
        'nisan-koi' => SubscriptionTier::Gold,
        'parti-kokteyl' => SubscriptionTier::Gold,
        'mezuniyet-yillik' => SubscriptionTier::Gold,
        'dogum-gunu-surpriz-makinesi' => SubscriptionTier::Standart,
        'baby-kazi-kazan' => SubscriptionTier::Standart,
    ];

    /** @return iterable<string, array{string, string, SubscriptionTier}> */
    public static function faz17Presets(): iterable
    {
        foreach (self::FAZ17_PRESETS as $preset => $tier) {
            $category = match (true) {
                str_starts_with($preset, 'dogum-gunu-') => 'dogum-gunu',
                str_starts_with($preset, 'baby-') => 'baby-shower',
                default => explode('-', $preset)[0],
            };

            yield $preset => [$preset, $category, $tier];
        }
    }

    #[Test]
    #[DataProvider('faz17Presets')]
    public function a_faz17_preset_is_stored_and_reaches_the_guest_page(string $preset, string $category, SubscriptionTier $tier): void
    {
        $owner = User::factory()->create();

        $id = $this->withToken($owner->createToken('api')->plainTextToken)
            ->postJson(route('invitations.store'), [
                'invitation' => [
                    'categoryId' => $category,
                    'imageTheme' => $preset,
                    'palette' => 'midnight',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.invitation.imageTheme', $preset)
            ->json('data.id');

        $this->assertDatabaseHas('invitations', ['id' => $id, 'preset_id' => $preset]);

        $published = Invitation::factory()->published()->create(['preset_id' => $preset, 'category_id' => $category]);
        $this->getJson(route('public.invitations.show', $published))
            ->assertOk()
            ->assertJsonPath('data.invitation.imageTheme', $preset);
    }

    /** Modülsüz bir davetiyede gereken plan yalnızca temadan gelir. */
    #[Test]
    #[DataProvider('faz17Presets')]
    public function a_faz17_preset_requires_its_plan(string $preset, string $category, SubscriptionTier $tier): void
    {
        $invitation = Invitation::factory()->create([
            'preset_id' => $preset,
            'category_id' => $category,
            'show_gallery' => false,
            'show_gift' => false,
            'show_envelope' => false,
            'show_timeline' => false,
        ]);

        $this->assertSame($tier, app(TierResolver::class)->requiredFor($invitation));
    }
}
