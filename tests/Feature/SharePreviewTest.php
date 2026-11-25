<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FE-04 (Faz 19.23, K230): bot paylasim onizlemesi `GET /invite/{id}` (Nginx yalnizca botlari yollar).
 * Kilavuz: docs/rehber/tests/Feature/SharePreviewTest.md
 */
final class SharePreviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_bot_gets_the_couples_names_in_og_title(): void
    {
        $inv = Invitation::factory()->published()->create([
            'names' => 'Ayşe & Ali',
            'venue' => 'Kuzguncuk Bahçe',
            'event_at' => '2027-06-12 19:00:00',
            'timezone' => 'Europe/Istanbul',
        ]);

        $this->get(route('share-preview', $inv->id))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertSee('<meta property="og:title" content="Ayşe &amp; Ali — Davetiye">', false)
            ->assertSee('12 Haziran 2027, 19:00 · Kuzguncuk Bahçe', false)
            ->assertSee('<meta property="og:site_name" content="DavetKart">', false)
            ->assertSee('<meta property="og:url" content="'.config('davetkart.frontend.url').'/invite/'.$inv->id.'">', false);
    }

    /** 🔴 UA sahtelenebilir: yayinda olmayanin adlari hicbir yoldan sizmaz. */
    #[Test]
    public function an_unpublished_invitation_gets_the_generic_preview(): void
    {
        $draft = Invitation::factory()->create(['names' => 'Gizli & Taslak', 'venue' => 'Saklı Mekan']);

        foreach ([$draft->id, strtolower((string) Str::ulid())] as $id) {
            $this->get(route('share-preview', $id))
                ->assertOk()
                ->assertSee('<meta property="og:title" content="DavetKart — Dijital Davetiye">', false)
                ->assertDontSee('Gizli', false)
                ->assertDontSee('Saklı Mekan', false);
        }
    }

    #[Test]
    public function preview_values_are_html_escaped(): void
    {
        $inv = Invitation::factory()->published()->create([
            'names' => '<script>alert(1)</script>" onload="x',
            'venue' => '"><img src=x onerror=alert(2)>',
        ]);

        $this->get(route('share-preview', $inv->id))
            ->assertOk()
            ->assertDontSee('<script>alert(1)', false)
            ->assertDontSee('<img src=x', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;&quot; onload=&quot;x', false);
    }

    /** K102: Elit (logosuz) yayinda marka adi da yok. */
    #[Test]
    public function an_elite_invitation_has_no_site_name(): void
    {
        $inv = Invitation::factory()->published()->create(['names' => 'Elif & Can']);
        Order::factory()->paid()->tier(SubscriptionTier::Elit)->forInvitation($inv)->create();

        $this->get(route('share-preview', $inv->id))
            ->assertOk()
            ->assertSee('Elif &amp; Can', false)
            ->assertDontSee('og:site_name', false);
    }

    /** K235 (B-E4): galeri yoksa sablon kapagi; dizinde yoksa ya da yol beklenmedikse genel poster. */
    #[Test]
    public function an_invitation_without_a_gallery_shows_its_template_cover(): void
    {
        $index = tempnam(sys_get_temp_dir(), 'covers');
        file_put_contents($index, (string) json_encode(['moda-gece' => '/covers/moda-gece.webp', 'kotu' => 'https://baska.example/x.jpg']));
        Config::set('covers.index', $index);
        $frontend = config('davetkart.frontend.url');

        try {
            $withCover = Invitation::factory()->published()->create(['preset_id' => 'moda-gece']);
            $this->get(route('share-preview', $withCover->id))
                ->assertSee('<meta property="og:image" content="'.$frontend.'/covers/moda-gece.webp">', false);

            foreach (['yok-boyle-sablon', 'kotu'] as $preset) {
                $other = Invitation::factory()->published()->create(['preset_id' => $preset]);
                $this->get(route('share-preview', $other->id))
                    ->assertSee('<meta property="og:image" content="'.$frontend.config('davetkart.share_preview.image_path').'">', false);
            }
        } finally {
            unlink($index);
        }
    }

    /** D4: misafir okumasiyla AYNI onbellek anahtari; yayin olayi ikisini birden tazeler. */
    #[Test]
    public function the_preview_shares_the_guest_cache_entry(): void
    {
        $inv = Invitation::factory()->published()->create(['names' => 'Deniz & Can']);

        $this->get(route('share-preview', $inv->id))->assertOk();

        $this->assertTrue(Cache::has(Invitation::publicCacheKey($inv->id)));
    }
}
