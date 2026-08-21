<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 3'un kaniti: davetiye CRUD, sahiplik ve program senkronizasyonu.
 *
 * T6: bir davranisin hem VARLIGI hem YOKLUGU test edilir.
 * T13: her kimlikli istek arasinda forgetAuthState() — yoksa guard onbellegi
 * IDOR testlerini sessizce BOS YESIL yakar.
 * Ayrintili aciklama: docs/rehber/tests/Feature/InvitationTest.md
 */
final class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private const YOK_OLAN_ULID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // ------------------------------------------------------------ KIMLIK

    #[Test]
    public function guest_cannot_list_invitations(): void
    {
        $this->getJson(route('invitations.index'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    #[Test]
    public function guest_cannot_create_an_invitation(): void
    {
        $this->postJson(route('invitations.store'), $this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    // ------------------------------------------------------------- LISTE

    /** 🔴 Listede sahiplik korumasi Policy'de degil, SORGUDA. */
    #[Test]
    public function index_returns_only_the_owners_invitations(): void
    {
        $ayse = User::factory()->create();
        $mehmet = User::factory()->create();

        $ayseninki = Invitation::factory()->for($ayse)->create();
        Invitation::factory()->for($mehmet)->create();

        $this->withToken($this->tokenFor($ayse))
            ->getJson(route('invitations.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ayseninki->id);
    }

    /** Program iliskisi yuklu gelmeli; aksi halde Resource sessizce eksik doner. */
    #[Test]
    public function index_includes_the_timeline_program(): void
    {
        $ayse = User::factory()->create();
        Invitation::factory()->for($ayse)->withTimeline(2)->create();

        $this->withToken($this->tokenFor($ayse))
            ->getJson(route('invitations.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data.0.invitation.timelineEvents');
    }

    // --------------------------------------------------------- OLUSTURMA

    #[Test]
    public function store_creates_an_invitation_for_the_authenticated_user(): void
    {
        $ayse = User::factory()->create();

        $response = $this->withToken($this->tokenFor($ayse))
            ->postJson(route('invitations.store'), $this->payload([
                'timelineEvents' => [
                    ['id' => null, 'time' => '19:00', 'title' => 'Nikah'],
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.status', InvitationStatus::Saved->value)
            ->assertJsonPath('data.invitation.title', 'Dugunumuz')
            ->assertJsonCount(1, 'data.invitation.timelineEvents');

        $this->assertDatabaseHas('invitations', [
            'id' => $response->json('data.id'),
            'user_id' => $ayse->id,
        ]);
    }

    /** 🔴 Sunucunun sahip oldugu alanlar istekten YAZILAMAZ (mass assignment). */
    #[Test]
    public function store_ignores_server_owned_fields(): void
    {
        $ayse = User::factory()->create();
        $mehmet = User::factory()->create();

        $response = $this->withToken($this->tokenFor($ayse))
            ->postJson(route('invitations.store'), [
                'invitation' => [
                    'categoryId' => 'dugun',
                    'imageTheme' => 'moda-gece',
                    'palette' => 'midnight',
                    'status' => 'published',
                    'user_id' => $mehmet->id,
                ],
                'status' => 'published',
                'user_id' => $mehmet->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', InvitationStatus::Saved->value);

        $this->assertDatabaseHas('invitations', [
            'id' => $response->json('data.id'),
            'user_id' => $ayse->id,
            'status' => InvitationStatus::Saved->value,
            'published_at' => null,
        ]);
    }

    #[Test]
    public function store_requires_the_catalog_keys(): void
    {
        $ayse = User::factory()->create();

        $response = $this->withToken($this->tokenFor($ayse))
            ->postJson(route('invitations.store'), ['invitation' => ['title' => 'Eksik']])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);

        $this->assertArrayHasKey('invitation.categoryId', $response->json('error.fields'));
    }

    // ------------------------------------------------------- SAHIPLIK 🔴

    #[Test]
    public function owner_can_read_their_own_invitation(): void
    {
        $ayse = User::factory()->create();
        $ayseninki = Invitation::factory()->for($ayse)->create();

        $this->withToken($this->tokenFor($ayse))
            ->getJson(route('invitations.show', $ayseninki))
            ->assertOk()
            ->assertJsonPath('data.id', $ayseninki->id);
    }

    /** 🔴 IDOR: gecerli token + baskasinin kimligi = 404, 403 DEGIL (H7). */
    #[Test]
    public function owner_cannot_read_another_users_invitation(): void
    {
        $ayse = User::factory()->create();
        $mehmetinki = Invitation::factory()->for(User::factory())->create();

        $this->withToken($this->tokenFor($ayse))
            ->getJson(route('invitations.show', $mehmetinki))
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);
    }

    #[Test]
    public function owner_cannot_update_another_users_invitation(): void
    {
        $ayse = User::factory()->create();
        $mehmetinki = Invitation::factory()->for(User::factory())->create(['title' => 'Dokunma']);

        $this->withToken($this->tokenFor($ayse))
            ->putJson(route('invitations.update', $mehmetinki), $this->payload(['title' => 'Ele gecirildi']))
            ->assertNotFound();

        $this->assertDatabaseHas('invitations', ['id' => $mehmetinki->id, 'title' => 'Dokunma']);
    }

    #[Test]
    public function owner_cannot_delete_another_users_invitation(): void
    {
        $ayse = User::factory()->create();
        $mehmetinki = Invitation::factory()->for(User::factory())->create();

        $this->withToken($this->tokenFor($ayse))
            ->deleteJson(route('invitations.destroy', $mehmetinki))
            ->assertNotFound();

        $this->assertNotSoftDeleted($mehmetinki);
    }

    /**
     * 🔴 T11: ayirt edilemezlik HAM GOVDE karsilastirmasiyla dogrulanir.
     * Yok olan kayit ile baskasinin kaydi tek bit farkla bile ayrilmamali.
     */
    #[Test]
    public function missing_and_forbidden_invitations_are_indistinguishable(): void
    {
        config(['app.debug' => false]);

        $ayse = User::factory()->create();
        $mehmetinki = Invitation::factory()->for(User::factory())->create();
        $token = $this->tokenFor($ayse);

        $yokOlan = $this->withToken($token)
            ->getJson(route('invitations.show', self::YOK_OLAN_ULID));

        $this->forgetAuthState();

        $baskasinin = $this->withToken($token)
            ->getJson(route('invitations.show', $mehmetinki));

        $this->assertSame($yokOlan->getStatusCode(), $baskasinin->getStatusCode());
        $this->assertSame($yokOlan->getContent(), $baskasinin->getContent());
    }

    // ------------------------------------------------ PROGRAM SENKRONU 🔴

    /** Ekle + guncelle + sil: uc yol tek istekte. */
    #[Test]
    public function update_syncs_the_timeline_program(): void
    {
        $ayse = User::factory()->create();
        $inv = Invitation::factory()->for($ayse)->withTimeline(3)->create();
        $ids = $inv->timelineEvents()->pluck('id')->all();

        $this->withToken($this->tokenFor($ayse))
            ->putJson(route('invitations.update', $inv), $this->payload([
                'timelineEvents' => [
                    ['id' => (string) $ids[2], 'time' => '20:00', 'title' => 'Yemek'],
                    ['id' => null, 'time' => '23:00', 'title' => 'Havai Fisek'],
                ],
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data.invitation.timelineEvents')
            ->assertJsonPath('data.invitation.timelineEvents.0.id', (string) $ids[2])
            ->assertJsonPath('data.invitation.timelineEvents.0.title', 'Yemek')
            ->assertJsonPath('data.invitation.timelineEvents.1.title', 'Havai Fisek');

        // Listede olmayanlar silindi
        $this->assertDatabaseMissing('timeline_events', ['id' => $ids[0]]);
        $this->assertDatabaseMissing('timeline_events', ['id' => $ids[1]]);

        // Sira listedeki KONUMDAN yazildi
        $this->assertDatabaseHas('timeline_events', ['id' => $ids[2], 'sort_order' => 0]);
    }

    /** 🔴 Alan hic gonderilmediyse programa DOKUNULMAZ. */
    #[Test]
    public function omitting_timeline_events_leaves_the_program_untouched(): void
    {
        $ayse = User::factory()->create();
        $inv = Invitation::factory()->for($ayse)->withTimeline(3)->create();

        $this->withToken($this->tokenFor($ayse))
            ->putJson(route('invitations.update', $inv), $this->payload(['title' => 'Yeni Baslik']))
            ->assertOk()
            ->assertJsonPath('data.invitation.title', 'Yeni Baslik')
            ->assertJsonCount(3, 'data.invitation.timelineEvents');
    }

    /** 🔴 Bos dizi "hepsini sil" demektir — null ile ayni sey DEGIL. */
    #[Test]
    public function empty_timeline_events_deletes_the_whole_program(): void
    {
        $ayse = User::factory()->create();
        $inv = Invitation::factory()->for($ayse)->withTimeline(3)->create();

        $this->withToken($this->tokenFor($ayse))
            ->putJson(route('invitations.update', $inv), $this->payload(['timelineEvents' => []]))
            ->assertOk()
            ->assertJsonCount(0, 'data.invitation.timelineEvents');

        $this->assertSame(0, $inv->timelineEvents()->count());
    }

    /**
     * 🔴 Alt kaynak IDOR'u: baskasinin program adiminin id'si gonderilse bile
     * o satira YAZILAMAZ; kendi davetiyesinde yeni satir acilir.
     */
    #[Test]
    public function a_timeline_event_of_another_invitation_cannot_be_overwritten(): void
    {
        $ayse = User::factory()->create();
        $ayseninki = Invitation::factory()->for($ayse)->create();

        $mehmetinki = Invitation::factory()->for(User::factory())->withTimeline(1)->create();
        $kurban = $mehmetinki->timelineEvents()->firstOrFail();

        $this->withToken($this->tokenFor($ayse))
            ->putJson(route('invitations.update', $ayseninki), $this->payload([
                'timelineEvents' => [
                    ['id' => (string) $kurban->id, 'time' => '01:00', 'title' => 'ELE GECIRILDI'],
                ],
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.invitation.timelineEvents');

        // Kurban satir aynen duruyor ve hala Mehmet'in davetiyesine bagli
        $this->assertDatabaseHas('timeline_events', [
            'id' => $kurban->id,
            'invitation_id' => $mehmetinki->id,
            'title' => $kurban->title,
        ]);

        // Ayse'nin davetiyesinde YENI bir satir acildi
        $this->assertSame(1, $ayseninki->timelineEvents()->count());
        $this->assertNotSame($kurban->id, $ayseninki->timelineEvents()->value('id'));
    }

    // ------------------------------------------------------------- SILME

    #[Test]
    public function destroy_soft_deletes_and_hides_it_from_the_list(): void
    {
        $ayse = User::factory()->create();
        $inv = Invitation::factory()->for($ayse)->create();
        $token = $this->tokenFor($ayse);

        $this->withToken($token)
            ->deleteJson(route('invitations.destroy', $inv))
            ->assertNoContent();

        $this->assertSoftDeleted($inv);

        $this->forgetAuthState();

        $this->withToken($token)
            ->getJson(route('invitations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // --------------------------------------------------------- SOZLESME

    /** T6: sozlesmenin hem VARLIGI hem YOKLUGU dogrulanir. */
    #[Test]
    public function response_matches_the_frontend_contract(): void
    {
        $ayse = User::factory()->create();
        $inv = Invitation::factory()->for($ayse)->withTimeline(1)->create([
            'event_at' => '2026-09-12 19:00',
            'rsvp_deadline' => '2026-09-01',
        ]);

        $data = $this->withToken($this->tokenFor($ayse))
            ->getJson(route('invitations.show', $inv))
            ->assertOk()
            ->json('data');

        $this->assertIsString($data['id']);
        $this->assertIsString($data['invitation']['timelineEvents'][0]['id']);
        $this->assertIsBool($data['invitation']['showGift']);

        // Tarih bicimleri: <input> saat dilimi KABUL ETMEZ
        $this->assertSame('2026-09-12T19:00', $data['invitation']['date']);
        $this->assertSame('2026-09-01', $data['invitation']['rsvpDeadline']);

        // K41: turetiliyor, saklanmiyor
        $this->assertSame($data['invitation']['imageTheme'], $data['invitation']['phoneBackground']);

        // Beyaz liste: bunlar SIZMAMALI
        $this->assertArrayNotHasKey('sortOrder', $data['invitation']['timelineEvents'][0]);
        $this->assertArrayNotHasKey('invitationId', $data['invitation']['timelineEvents'][0]);
        $this->assertArrayNotHasKey('userId', $data['invitation']);
        $this->assertArrayNotHasKey('publishedAt', $data['invitation']);
    }

    /** Hata, hangi program satirinin bozuk oldugunu SOYLEMELI. */
    #[Test]
    public function an_invalid_timeline_time_is_reported_with_its_index(): void
    {
        $ayse = User::factory()->create();
        $inv = Invitation::factory()->for($ayse)->create();

        $response = $this->withToken($this->tokenFor($ayse))
            ->putJson(route('invitations.update', $inv), $this->payload([
                'timelineEvents' => [
                    ['id' => null, 'time' => '19:00', 'title' => 'Gecerli'],
                    ['id' => null, 'time' => '25:99', 'title' => 'Bozuk'],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);

        $fields = $response->json('error.fields');

        $this->assertArrayHasKey('invitation.timelineEvents.1.time', $fields);
        $this->assertSame('date_format', $fields['invitation.timelineEvents.1.time'][0]['rule']);

        // T6: gecerli satir icin hata URETILMEMELI
        $this->assertArrayNotHasKey('invitation.timelineEvents.0.time', $fields);
    }

    // ------------------------------------------------------- YARDIMCILAR

    private function tokenFor(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
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
        return [
            'invitation' => array_merge([
                'categoryId' => 'dugun',
                'imageTheme' => 'moda-gece',
                'palette' => 'midnight',
                'title' => 'Dugunumuz',
            ], $overrides),
        ];
    }
}
