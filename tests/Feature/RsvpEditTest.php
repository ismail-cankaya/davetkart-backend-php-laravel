<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\RsvpStatus;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Order;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\RsvpEditCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 10 (10.59 · K101): misafir kendi LCV'sini düzenleme koduyla günceller.
 *
 * Önceden aynı misafirin ikinci gönderimi ikinci bir satır açıyor ve kişi
 * sayısı kotadan iki kez düşüyordu.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/RsvpEditTest.md
 */
final class RsvpEditTest extends TestCase
{
    use RefreshDatabase;

    private const string SIMDI_UTC = '2026-09-20 12:00:00';

    private const string BULUNAMADI_GOVDESI = '{"error":{"code":"RESOURCE_NOT_FOUND"}}';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::SIMDI_UTC, 'UTC'));
    }

    #[Test]
    public function the_first_reply_hands_out_a_code_stored_only_as_a_hash(): void
    {
        $davetiye = $this->davetiye();

        $data = $this->gonder($davetiye)->assertCreated()->json('data');

        $this->assertIsString($data['editCode']);
        $this->assertSame(RsvpEditCode::LENGTH, strlen($data['editCode']));

        $this->assertDatabaseHas('rsvps', [
            'id' => $data['id'],
            'edit_code_hash' => hash('sha256', $data['editCode']),
        ]);
        $this->assertDatabaseMissing('rsvps', ['edit_code_hash' => $data['editCode']]);
    }

    /** 🔴 Asıl hata: ikinci gönderim yeni satır açmaz, eskisini değiştirir. */
    #[Test]
    public function the_same_guest_updates_the_reply_instead_of_adding_a_row(): void
    {
        $davetiye = $this->davetiye();
        $ilk = $this->gonder($davetiye)->assertCreated()->json('data');

        $guncel = $this->guncelle($davetiye, $ilk['id'], $ilk['editCode'], [
            'guestCount' => 2,
            'status' => RsvpStatus::Attending->value,
            'message' => 'Oğuz gelemiyor, Defne ile geliyoruz.',
        ])->assertOk()->json('data');

        $this->assertSame($ilk['id'], $guncel['id']);
        $this->assertSame($ilk['editCode'], $guncel['editCode']);
        $this->assertSame(2, $guncel['guestCount']);

        $this->assertDatabaseCount('rsvps', 1);
        $this->assertDatabaseHas('rsvps', [
            'id' => $ilk['id'],
            'guest_count' => 2,
            'message' => 'Oğuz gelemiyor, Defne ile geliyoruz.',
        ]);
    }

    /** H7: yanlış kod ile var olmayan yanıt AYNI 404'ü alır; satır değişmez. */
    #[Test]
    public function a_wrong_code_is_indistinguishable_from_a_missing_reply(): void
    {
        $davetiye = $this->davetiye();
        $ilk = $this->gonder($davetiye)->assertCreated()->json('data');

        $yanlisKod = $this->guncelle($davetiye, $ilk['id'], str_repeat('x', RsvpEditCode::LENGTH), ['guestCount' => 1])
            ->assertNotFound();
        $yokOlan = $this->guncelle($davetiye, '01jbz8q4n6r2w3x5y7t9v0kd1m', $ilk['editCode'], ['guestCount' => 1])
            ->assertNotFound();

        $this->assertSame(self::BULUNAMADI_GOVDESI, $yanlisKod->getContent());
        $this->assertSame(self::BULUNAMADI_GOVDESI, $yokOlan->getContent());
        $this->assertDatabaseHas('rsvps', ['id' => $ilk['id'], 'guest_count' => 3]);
    }

    /** Aidiyet URL'den: başka bir davetiyenin adresiyle doğru kod bile işe yaramaz. */
    #[Test]
    public function a_reply_cannot_be_updated_through_another_invitation(): void
    {
        $davetiye = $this->davetiye();
        $baska = $this->davetiye();
        $ilk = $this->gonder($davetiye)->assertCreated()->json('data');

        $this->guncelle($baska, $ilk['id'], $ilk['editCode'], ['guestCount' => 1])->assertNotFound();

        $this->assertDatabaseHas('rsvps', ['id' => $ilk['id'], 'guest_count' => 3]);
    }

    /** Faz 10'dan önceki yanıtların kodu yok; hiçbir kodla güncellenemezler. */
    #[Test]
    public function a_reply_without_a_code_cannot_be_updated(): void
    {
        $davetiye = $this->davetiye();
        $eski = Rsvp::factory()->for($davetiye)->create();

        $this->guncelle($davetiye, $eski->id, str_repeat('a', RsvpEditCode::LENGTH), ['guestCount' => 1])
            ->assertNotFound();
    }

    #[Test]
    public function an_update_after_the_deadline_is_refused(): void
    {
        $davetiye = $this->davetiye();
        $ilk = $this->gonder($davetiye)->assertCreated()->json('data');

        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));

        $this->guncelle($davetiye, $ilk['id'], $ilk['editCode'], ['guestCount' => 1])
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpDeadlinePassed->value);

        $this->assertDatabaseHas('rsvps', ['id' => $ilk['id'], 'guest_count' => 3]);
    }

    /**
     * 🔴 Kota: güncellenen yanıtın ESKİ kişi sayısı sayılmaz.
     *
     * Kota 5; başkaları 2 kişi, bu misafir 3 kişi: tam dolu. Aynı 3 kişiyle
     * güncelleme geçmeli (eski sayı da sayılsaydı 8 olurdu), 4'e çıkarmak
     * geçmemeli (2 + 4 = 6).
     */
    #[Test]
    public function the_update_counts_the_guest_once_against_the_quota(): void
    {
        Config::set('davetkart.tiers.standart.rsvp_limit', 5);
        $davetiye = $this->davetiye();
        Rsvp::factory()->for($davetiye)->guests(2)->create();
        $ilk = $this->gonder($davetiye)->assertCreated()->json('data');

        $this->guncelle($davetiye, $ilk['id'], $ilk['editCode'], ['guestCount' => 3, 'message' => 'Saat kaçta?'])
            ->assertOk();

        $this->guncelle($davetiye, $ilk['id'], $ilk['editCode'], ['guestCount' => 4])
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpQuotaExceeded->value);

        $this->assertDatabaseHas('rsvps', ['id' => $ilk['id'], 'guest_count' => 3]);
    }

    /** C1: kod yalnızca misafirin kendi yanıtında; sahibin listesinde ne kod ne özet var. */
    #[Test]
    public function the_owner_never_sees_the_code(): void
    {
        $sahip = User::factory()->create();
        $davetiye = $this->davetiye($sahip);
        $ilk = $this->gonder($davetiye)->assertCreated()->json('data');

        $liste = $this->withToken($sahip->createToken('api')->plainTextToken)
            ->getJson(route('invitations.rsvps.index', $davetiye))
            ->assertOk();

        $this->assertArrayNotHasKey('editCode', (array) $liste->json('data.0'));
        $this->assertStringNotContainsString($ilk['editCode'], (string) $liste->getContent());
        $this->assertStringNotContainsString(hash('sha256', $ilk['editCode']), (string) $liste->getContent());
    }

    /**
     * Faz 10 (10.64): misafirin KENDİ yanıtına bağlı fotoğraf güncellemede
     * korunur. "Başka bir yanıta bağlı medya düşer" kuralı kendi yanıtını
     * hariç tutmasaydı, misafir yanıtını her güncellediğinde fotoğrafını
     * kaybederdi.
     */
    #[Test]
    public function the_update_keeps_the_guests_own_photo(): void
    {
        $davetiye = $this->davetiye();
        $foto = Media::factory()->rsvpPhoto()->create(['invitation_id' => $davetiye->id]);

        $ilk = $this->postJson(
            route('public.invitations.rsvps.store', $davetiye),
            $this->form(['photoMediaId' => $foto->id]),
        )->assertCreated()->json('data');

        $this->guncelle($davetiye, $ilk['id'], $ilk['editCode'], ['guestCount' => 2, 'photoMediaId' => $foto->id])
            ->assertOk()
            ->assertJsonPath('data.photoUrl', $foto->url());

        $this->assertDatabaseHas('rsvps', ['id' => $ilk['id'], 'photo_media_id' => $foto->id]);
    }

    #[Test]
    public function the_update_requires_the_code(): void
    {
        $davetiye = $this->davetiye();
        $ilk = $this->gonder($davetiye)->assertCreated()->json('data');

        $this->putJson(
            route('public.invitations.rsvps.update', [$davetiye, $ilk['id']]),
            $this->form(),
        )
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.editCode.0.rule', 'required');
    }

    /** Güncelleme de bir yazma: LCV'nin hız kovasıyla aynı sınır. */
    #[Test]
    public function the_update_is_rate_limited_like_a_new_reply(): void
    {
        $route = Route::getRoutes()->getByName('public.invitations.rsvps.update');

        $this->assertNotNull($route);
        $this->assertContains('throttle:rsvp', $route->gatherMiddleware());
    }

    // ------------------------------------------------------- YARDIMCILAR

    private function davetiye(?User $sahip = null): Invitation
    {
        $davetiye = Invitation::factory()->published()->for($sahip ?? User::factory()->create())->create([
            'names' => 'Gülşah & Emre',
            'event_at' => '2026-10-17 19:30:00',
            'timezone' => 'Europe/Istanbul',
            'show_rsvp' => true,
            'rsvp_deadline' => '2026-10-10',
        ]);

        Order::factory()->tier(SubscriptionTier::Standart)->forInvitation($davetiye)->paid()->create();

        return $davetiye;
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'guestName' => 'Şeyma Şen',
            'guestCount' => 3,
            'status' => RsvpStatus::Attending->value,
            ...$overrides,
        ];
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function gonder(Invitation $davetiye): TestResponse
    {
        return $this->postJson(route('public.invitations.rsvps.store', $davetiye), $this->form());
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function guncelle(Invitation $davetiye, string $rsvpId, string $kod, array $overrides): TestResponse
    {
        return $this->putJson(
            route('public.invitations.rsvps.update', [$davetiye, $rsvpId]),
            [...$this->form($overrides), 'editCode' => $kod],
        );
    }
}
