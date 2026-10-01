<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Models\AssistantUsage;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Order;
use App\Models\Rsvp;
use App\Models\TimelineEvent;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Hesap silme (Faz 10, 10.38–10.41 · K97 / H-1: anonimlestirme).
 *
 * T14: yanita degil ETKIYE bakilir. 204 bir sey kanitlamaz; kanit hangi
 * satirlarin ve DOSYALARIN gittigi, hangilerinin KALDIGI.
 * Ayrintili aciklama: docs/rehber/tests/Feature/AccountDeletionTest.md
 */
final class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = Config::string('davetkart.media.disk');
        Storage::fake($this->disk);
    }

    /** Kimliksiz istek hicbir seyi silemez. */
    #[Test]
    public function a_guest_cannot_delete_an_account(): void
    {
        $this->deleteJson(route('auth.destroy'), ['password' => UserFactory::PASSWORD])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    /** 🔴 Token tek basina yetmez: yanlis parola -> 422, hicbir sey silinmez. */
    #[Test]
    public function a_wrong_password_deletes_nothing(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->for($user)->create();

        $this->destroy($user, 'yanlis-parola')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value)
            ->assertJsonPath('error.fields.password.0.rule', 'current_password');

        $this->assertModelExists($user);
        $this->assertModelExists($invitation);
    }

    #[Test]
    public function the_password_is_required(): void
    {
        $user = User::factory()->create();

        $this->destroy($user, null)
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.password.0.rule', 'required');

        $this->assertModelExists($user);
    }

    /**
     * 🔴 Kisisel veri gider — satirlar VE dosyalar. Odeme kaydi kalir,
     * sahibi bosalir (K97).
     */
    #[Test]
    public function personal_data_and_files_go_while_orders_stay_anonymous(): void
    {
        $user = User::factory()->create();
        $sessionToken = $user->createToken('api')->plainTextToken;
        $user->createToken('api'); // ikinci cihaz

        $live = Invitation::factory()->for($user)->published()->create();
        $trashed = Invitation::factory()->for($user)->create();
        $trashed->delete(); // cop kutusundaki davetiye de gitmeli

        TimelineEvent::factory()->for($live)->create();
        $gallery = $this->withFile(Media::factory()->for($live)->createOne());
        $guestPhoto = $this->withFile(Media::factory()->rsvpPhoto()->for($live)->createOne());
        $rsvp = Rsvp::factory()->for($live)->create(['photo_media_id' => $guestPhoto->id]);
        $trashedPhoto = $this->withFile(Media::factory()->for($trashed)->createOne());

        $single = Order::factory()->forInvitation($live)->paid()->create();
        $package = Order::factory()->for($user)->package()->paid()->create();
        AssistantUsage::factory()->for($user)->create();
        Password::broker()->createToken($user);

        $this->destroy($user, UserFactory::PASSWORD, $sessionToken)->assertNoContent();

        // Kisisel veri: satirlar
        $this->assertModelMissing($user);
        $this->assertSame(0, Invitation::withTrashed()->where('user_id', $user->id)->count());
        $this->assertModelMissing($rsvp);
        $this->assertSame(0, Media::query()->count());
        $this->assertSame(0, TimelineEvent::query()->count());
        $this->assertSame(0, AssistantUsage::query()->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        // Kisisel veri: dosyalar (veritabani cascade'i bunlari BILMEZ)
        foreach ([$gallery, $guestPhoto, $trashedPhoto] as $media) {
            Storage::disk($this->disk)->assertMissing($media->path);
        }

        // Muhasebe kaydi: kalir, sahibi ve davetiyesi bosalir
        foreach ([$single, $package] as $order) {
            $order->refresh();
            $this->assertNull($order->user_id);
            $this->assertNull($order->invitation_id);
        }
        $this->assertSame(2, Order::query()->count());

        // Eski oturum artik gecersiz
        $this->forgetAuthState();
        $this->withToken($sessionToken)->getJson(route('auth.me'))->assertUnauthorized();
    }

    /** Baska kullaniciya DOKUNULMAZ: satiri, dosyasi, siparisi yerinde. */
    #[Test]
    public function another_users_data_is_untouched(): void
    {
        $ayse = User::factory()->create();
        $mehmet = User::factory()->create();

        $mehmetsInvitation = Invitation::factory()->for($mehmet)->published()->create();
        $mehmetsPhoto = $this->withFile(Media::factory()->for($mehmetsInvitation)->createOne());
        $mehmetsOrder = Order::factory()->forInvitation($mehmetsInvitation)->paid()->create();

        $this->destroy($ayse, UserFactory::PASSWORD)->assertNoContent();

        $this->assertModelExists($mehmet);
        $this->assertModelExists($mehmetsInvitation);
        Storage::disk($this->disk)->assertExists($mehmetsPhoto->path);
        $this->assertSame($mehmet->id, $mehmetsOrder->refresh()->user_id);
    }

    /**
     * 🔴 Davetiyeler MODEL uzerinden silinir (plan tuzak #8). Veritabani
     * cascade'ine birakilsaydi `deleted` olayi atesmez, public cache temizlenmez
     * ve silinmis davetiye 6 saat boyunca misafire acik kalirdi.
     */
    #[Test]
    public function a_cached_public_invitation_disappears_with_the_account(): void
    {
        $user = User::factory()->create();
        $invitation = Invitation::factory()->for($user)->published()->create();

        // Cache'i doldur
        $this->getJson(route('public.invitations.show', $invitation))->assertOk();

        $this->destroy($user, UserFactory::PASSWORD)->assertNoContent();

        $this->getJson(route('public.invitations.show', $invitation))->assertNotFound();
    }

    /** Parola onayi bir tahmin hedefi: throttle:auth. */
    #[Test]
    public function password_guesses_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        foreach (range(1, 5) as $ignored) {
            $this->forgetAuthState();
            $this->withToken($token)->deleteJson(route('auth.destroy'), ['password' => 'yanlis'])->assertUnprocessable();
        }

        $this->forgetAuthState();
        $this->withToken($token)->deleteJson(route('auth.destroy'), ['password' => 'yanlis'])->assertStatus(429);

        $this->assertModelExists($user);
    }

    // --------------------------------------------------------- YARDIMCILAR

    /** @return TestResponse<Response> */
    private function destroy(User $user, ?string $password, ?string $token = null): TestResponse
    {
        $this->forgetAuthState();

        return $this->withToken($token ?? $user->createToken('api')->plainTextToken)
            ->deleteJson(route('auth.destroy'), $password === null ? [] : ['password' => $password]);
    }

    /** Satirin dosyasini sahte diske yazar: silme dosyayi gercekten bulsun. */
    private function withFile(Media $media): Media
    {
        Storage::disk($this->disk)->put($media->path, 'sahte-icerik');

        return $media;
    }
}
