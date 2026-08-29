<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\RsvpStatus;
use App\Http\Requests\Rsvp\StoreRsvpRequest;
use App\Models\Invitation;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 5'in kaniti: auth'suz yazma yolu, katmanli savunma ve sahibin paneli.
 *
 * 🔴 Bu dosyanin en onemli testleri YANITA DEGIL ETKIYE bakar (T14). Honeypot
 * 201 doner, kota reddi govdeye sayi koymaz, IDOR 404 doner — ucunde de yanit
 * tek basina hicbir sey KANITLAMAZ. assertDatabaseCount / assertDatabaseHas
 * olmadan bu testler susten ibaret olurdu.
 *
 * T13: her ikinci kimlikli istekten once forgetAuthState().
 * Ayrintili aciklama: docs/rehber/tests/Feature/RsvpTest.md
 */
final class RsvpTest extends TestCase
{
    use RefreshDatabase;

    private const YOK_OLAN_ULID = '01arz3ndektsv4rrffq69g5fav';

    // ------------------------------------------------------- GORUNURLUK

    #[Test]
    public function guest_can_submit_an_rsvp_to_a_published_invitation(): void
    {
        $inv = $this->published();

        $this->postJson($this->url($inv), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.guestName', 'Can Dogan')
            ->assertJsonPath('data.guestCount', 2)
            ->assertJsonPath('data.status', RsvpStatus::Attending->value);

        // T14: yanit degil ETKI.
        $this->assertDatabaseHas('rsvps', [
            'invitation_id' => $inv->id,
            'guest_name' => 'Can Dogan',
            'guest_count' => 2,
        ]);
    }

    /** 🔴 Taslak davetiyeye LCV yazilamaz — gorunurluk yazma yolunda da gecerli. */
    #[Test]
    public function rsvp_to_an_unpublished_invitation_is_rejected(): void
    {
        $inv = Invitation::factory()->create(['show_rsvp' => true]);

        $this->postJson($this->url($inv), $this->payload())
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        $this->assertDatabaseCount('rsvps', 0);
    }

    /** Modul kapaliysa uc "yok" sayilir (C6): 403 degil 404. */
    #[Test]
    public function rsvp_is_rejected_when_the_module_is_closed(): void
    {
        $inv = $this->published(['show_rsvp' => false]);

        $this->postJson($this->url($inv), $this->payload())
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        $this->assertDatabaseCount('rsvps', 0);
    }

    /** T11: ayirt edilemezlik HAM GOVDE karsilastirmasiyla dogrulanir. */
    #[Test]
    public function closed_module_and_missing_invitation_are_indistinguishable(): void
    {
        $kapali = $this->published(['show_rsvp' => false]);

        $a = $this->postJson($this->url($kapali), $this->payload());
        $b = $this->postJson(
            route('public.invitations.rsvps.store', self::YOK_OLAN_ULID),
            $this->payload(),
        );

        $this->assertSame($a->getStatusCode(), $b->getStatusCode());
        $this->assertSame($this->body($a), $this->body($b));
    }

    /** Bicimsiz kimlik veritabanina HIC gitmez; 404 rota katmaninda verilir (O6). */
    #[Test]
    public function a_malformed_invitation_id_never_reaches_the_database(): void
    {
        DB::enableQueryLog();

        $this->postJson('/api/public/invitations/bu-bir-ulid-degil/rsvps', $this->payload())
            ->assertNotFound();

        $this->assertSame([], DB::getQueryLog());
    }

    // -------------------------------------------------------- DOGRULAMA

    #[Test]
    public function guest_name_is_required(): void
    {
        $inv = $this->published();

        $response = $this->postJson($this->url($inv), $this->payload(['guestName' => null]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);

        $this->assertSame('required', $response->json('error.fields.guestName.0.rule'));
        $this->assertDatabaseCount('rsvps', 0);
    }

    /** 🔴 D6: kural adi 'in' olmali — framework sinif adi sozlesmeye sizmamali. */
    #[Test]
    public function status_must_be_a_known_value(): void
    {
        $inv = $this->published();

        $response = $this->postJson($this->url($inv), $this->payload(['status' => 'Katiliyor']))
            ->assertUnprocessable();

        $this->assertSame('in', $response->json('error.fields.status.0.rule'));
    }

    #[Test]
    public function guest_count_is_capped_by_configuration(): void
    {
        config(['davetkart.rsvp.max_guests_per_entry' => 10]);
        $inv = $this->published();

        $response = $this->postJson($this->url($inv), $this->payload(['guestCount' => 11]))
            ->assertUnprocessable();

        $this->assertSame('max', $response->json('error.fields.guestCount.0.rule'));
        $this->assertSame(10, $response->json('error.fields.guestCount.0.params.max'));
    }

    #[Test]
    public function guest_count_must_be_at_least_one(): void
    {
        $inv = $this->published();

        $this->postJson($this->url($inv), $this->payload(['guestCount' => 0]))
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.guestCount.0.rule', 'min');
    }

    // --------------------------------------------------------- HONEYPOT

    #[Test]
    public function honeypot_submission_looks_successful(): void
    {
        $inv = $this->published();

        $this->postJson($this->url($inv), $this->botPayload())
            ->assertCreated()
            ->assertJsonPath('data.guestName', 'Can Dogan');
    }

    /** 🔴 FAZIN EN KRITIK TESTI: yanit 201 ama SATIR YAZILMAMALI (T14). */
    #[Test]
    public function honeypot_submission_is_not_persisted(): void
    {
        $inv = $this->published();

        $this->postJson($this->url($inv), $this->botPayload())->assertCreated();

        $this->assertDatabaseCount('rsvps', 0);
    }

    /** Bot ile insan ayni SEKILDE yanit almali; yoksa savunma bir kez kullanilip olur. */
    #[Test]
    public function honeypot_response_has_the_same_shape_as_a_real_one(): void
    {
        $inv = $this->published();

        $gercek = $this->postJson($this->url($inv), $this->payload())->assertCreated();
        $bot = $this->postJson($this->url($inv), $this->botPayload())->assertCreated();

        $this->assertSame(
            array_keys((array) $gercek->json('data')),
            array_keys((array) $bot->json('data')),
        );

        // Yalnizca gercek gonderim yazildi.
        $this->assertDatabaseCount('rsvps', 1);
    }

    // -------------------------------------------------------- SON TARIH

    /** 🔴 Son gun DAHIL: isPast() yazilsaydi bu test kirilirdi. */
    #[Test]
    public function rsvp_is_accepted_on_the_deadline_day(): void
    {
        $inv = $this->published(['rsvp_deadline' => now()->toDateString()]);

        $this->postJson($this->url($inv), $this->payload())->assertCreated();

        $this->assertDatabaseCount('rsvps', 1);
    }

    #[Test]
    public function rsvp_is_rejected_after_the_deadline(): void
    {
        $inv = $this->published(['rsvp_deadline' => now()->subDay()->toDateString()]);

        $this->postJson($this->url($inv), $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpDeadlinePassed->value);

        $this->assertDatabaseCount('rsvps', 0);
    }

    /** N4 ailesi: null "sinir yok" demektir, "bugun" degil. */
    #[Test]
    public function a_missing_deadline_means_no_limit(): void
    {
        $inv = $this->published(['rsvp_deadline' => null]);

        $this->postJson($this->url($inv), $this->payload())->assertCreated();
    }

    // ------------------------------------------------------------- KOTA

    /**
     * 🔴 Kota KAYIT degil MISAFIR sayar.
     *
     * COUNT(*) ile olculseydi: 1 kayit + 1 = 2 <= 5, yani bu test GECERDI.
     * SUM ile: 4 + 2 = 6 > 5, reddedilir.
     */
    #[Test]
    public function quota_counts_guests_not_rows(): void
    {
        config(['davetkart.tiers.standart.rsvp_limit' => 5]);
        $inv = $this->published();
        Rsvp::factory()->for($inv)->guests(4)->create();

        $this->postJson($this->url($inv), $this->payload(['guestCount' => 2]))
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpQuotaExceeded->value);

        $this->assertDatabaseCount('rsvps', 1);
    }

    /** K50: gelmeyecegini bildiren misafir masada yer kaplamaz. */
    #[Test]
    public function declined_rsvps_do_not_consume_quota(): void
    {
        config(['davetkart.tiers.standart.rsvp_limit' => 5]);
        $inv = $this->published();
        Rsvp::factory()->for($inv)->declined()->guests(5)->create();

        $this->postJson($this->url($inv), $this->payload(['guestCount' => 3]))
            ->assertCreated();

        $this->assertDatabaseCount('rsvps', 2);
    }

    /** K50: kararsiz misafir yer kaplar — temkinli taraf. */
    #[Test]
    public function pending_rsvps_consume_quota(): void
    {
        config(['davetkart.tiers.standart.rsvp_limit' => 5]);
        $inv = $this->published();
        Rsvp::factory()->for($inv)->pending()->guests(5)->create();

        $this->postJson($this->url($inv), $this->payload(['guestCount' => 1]))
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpQuotaExceeded->value);
    }

    /** 🔴 H9: anonim misafir ic sayaclari (remaining/limit) OGRENMEMELI. */
    #[Test]
    public function quota_rejection_does_not_leak_counters(): void
    {
        config(['davetkart.tiers.standart.rsvp_limit' => 1]);
        $inv = $this->published();
        Rsvp::factory()->for($inv)->guests(1)->create();

        $response = $this->postJson($this->url($inv), $this->payload(['guestCount' => 1]))
            ->assertForbidden()
            ->assertJsonMissingPath('error.params');

        $this->assertStringNotContainsString('remaining', $this->body($response));
        $this->assertStringNotContainsString('limit', $this->body($response));
    }

    /** Sinirsiz plan: kota sorgusu hic acilmaz. */
    #[Test]
    public function an_unlimited_plan_skips_the_quota_check(): void
    {
        config(['davetkart.tiers.standart.rsvp_limit' => null]);
        $inv = $this->published();
        Rsvp::factory()->for($inv)->guests(9999)->create();

        $this->postJson($this->url($inv), $this->payload(['guestCount' => 10]))
            ->assertCreated();
    }

    // ------------------------------------------------------- HIZ SINIRI

    #[Test]
    public function rsvp_submissions_are_rate_limited(): void
    {
        config(['davetkart.rsvp.rate_limit.per_ip_per_minute' => 3]);
        $inv = $this->published();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->url($inv), $this->payload())->assertCreated();
        }

        $this->postJson($this->url($inv), $this->payload())
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);

        // T14: reddedilen istek yazilmadi.
        $this->assertDatabaseCount('rsvps', 3);
    }

    // -------------------------------------------------- SAHIBIN LISTESI

    #[Test]
    public function guest_cannot_list_rsvps(): void
    {
        $inv = $this->published();

        $this->getJson(route('invitations.rsvps.index', $inv))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    #[Test]
    public function owner_can_list_the_rsvps_of_their_invitation(): void
    {
        $ayse = User::factory()->create();
        $inv = $this->published(['user_id' => $ayse->id]);
        Rsvp::factory()->for($inv)->count(2)->create();

        $this->withToken($this->tokenFor($ayse))
            ->getJson(route('invitations.rsvps.index', $inv))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** 🔴 IDOR: baskasinin davetiyesinde 404 (H7), 403 degil. */
    #[Test]
    public function another_user_cannot_list_rsvps(): void
    {
        $ayse = User::factory()->create();
        $mehmet = User::factory()->create();
        $inv = $this->published(['user_id' => $ayse->id]);
        Rsvp::factory()->for($inv)->create(['guest_name' => 'GIZLI MISAFIR']);

        $response = $this->withToken($this->tokenFor($mehmet))
            ->getJson(route('invitations.rsvps.index', $inv))
            ->assertNotFound();

        // T14: 404 gormek yetmez — ad govdede hic gecmemeli.
        $this->assertStringNotContainsString('GIZLI MISAFIR', $this->body($response));
    }

    /** 🔴 C1 sizinti testi: ip_hash yanita ASLA girmez. */
    #[Test]
    public function ip_hash_is_never_exposed(): void
    {
        $ayse = User::factory()->create();
        $inv = $this->published(['user_id' => $ayse->id]);
        $rsvp = Rsvp::factory()->for($inv)->create();

        $response = $this->withToken($this->tokenFor($ayse))
            ->getJson(route('invitations.rsvps.index', $inv))
            ->assertOk();

        $this->assertStringNotContainsString($rsvp->ip_hash, $this->body($response));
        $this->assertStringNotContainsString('ipHash', $this->body($response));
        $this->assertStringNotContainsString('ip_hash', $this->body($response));
    }

    /** K46: Faz 4'un ETag katmani polling ucunda yeniden kullaniliyor. */
    #[Test]
    public function an_unchanged_rsvp_list_returns_304(): void
    {
        $ayse = User::factory()->create();
        $inv = $this->published(['user_id' => $ayse->id]);
        Rsvp::factory()->for($inv)->create();
        $token = $this->tokenFor($ayse);

        $ilk = $this->withToken($token)
            ->getJson(route('invitations.rsvps.index', $inv))
            ->assertOk();

        $etag = $ilk->headers->get('ETag');
        $this->assertNotNull($etag);

        // T13: ikinci kimlikli istekten ONCE guard sifirlanir.
        $this->forgetAuthState();

        $this->withToken($token)
            ->withHeader('If-None-Match', $etag)
            ->getJson(route('invitations.rsvps.index', $inv))
            ->assertStatus(304);
    }

    // ------------------------------------------------------------- SILME

    #[Test]
    public function owner_can_delete_an_rsvp(): void
    {
        $ayse = User::factory()->create();
        $inv = $this->published(['user_id' => $ayse->id]);
        $rsvp = Rsvp::factory()->for($inv)->create();

        $this->withToken($this->tokenFor($ayse))
            ->deleteJson(route('rsvps.destroy', $rsvp))
            ->assertNoContent();

        $this->assertDatabaseCount('rsvps', 0);
    }

    /** 🔴 T14: 404 donmesi, silmenin GERCEKLESMEDIGINI kanitlamaz. */
    #[Test]
    public function another_user_cannot_delete_an_rsvp(): void
    {
        $ayse = User::factory()->create();
        $mehmet = User::factory()->create();
        $inv = $this->published(['user_id' => $ayse->id]);
        $rsvp = Rsvp::factory()->for($inv)->create();

        $this->withToken($this->tokenFor($mehmet))
            ->deleteJson(route('rsvps.destroy', $rsvp))
            ->assertNotFound();

        $this->assertDatabaseHas('rsvps', ['id' => $rsvp->id]);
    }

    #[Test]
    public function deleting_a_missing_rsvp_returns_404(): void
    {
        $ayse = User::factory()->create();

        $this->withToken($this->tokenFor($ayse))
            ->deleteJson(route('rsvps.destroy', self::YOK_OLAN_ULID))
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);
    }

    // ------------------------------------------------------- YARDIMCILAR

    private function tokenFor(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    /**
     * Yayinda ve LCV modulu ACIK bir davetiye.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function published(array $overrides = []): Invitation
    {
        /** @var array<string, mixed> $attributes */
        $attributes = array_merge([
            'show_rsvp' => true,
            'rsvp_deadline' => null,
        ], $overrides);

        return Invitation::factory()->published()->create($attributes);
    }

    private function url(Invitation $invitation): string
    {
        return route('public.invitations.rsvps.store', $invitation);
    }

    /**
     * Gecerli bir istek govdesi; verilen alanlar varsayilanlari ezer.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'guestName' => 'Can Dogan',
            'guestCount' => 2,
            'status' => RsvpStatus::Attending->value,
        ], $overrides);
    }

    /**
     * Gorunmez alani doldurulmus govde — bir botun gonderecegi sey.
     *
     * @return array<string, mixed>
     */
    private function botPayload(): array
    {
        return $this->payload([StoreRsvpRequest::HONEYPOT_FIELD => 'http://spam.example']);
    }

    /** @param  TestResponse<\Illuminate\Http\Response>  $response */
    private function body(TestResponse $response): string
    {
        return (string) $response->getContent();
    }
}
