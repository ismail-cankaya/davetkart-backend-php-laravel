<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Media\StoreGuestMediaAction;
use App\Enums\ErrorCode;
use App\Enums\MediaKind;
use App\Enums\RsvpStatus;
use App\Jobs\OptimizeUploadedImage;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 6'nin kaniti: sistemin DOSYA KABUL EDEN yolu.
 *
 * 🔴 Bu dosyanin en onemli testleri YANITA DEGIL ETKIYE bakar (T14):
 *   - Gecersiz medya kimligi 201 doner ve SESSIZCE dusurulur -> kaniti kolon
 *   - Misafirin tur izni FormRequest'te elenir -> Action seviyesinde ayri test
 *   - Yetim dosya telafisi yanitta gorunmez -> kaniti diskteki dosya
 *
 * Storage::fake() her testte gercek diski taklit eder; dosyalar test sonunda
 * kaybolur ve depo kirletilmez.
 * Ayrintili aciklama: docs/rehber/tests/Feature/MediaTest.md
 */
final class MediaTest extends TestCase
{
    use RefreshDatabase;

    private const YOK_OLAN_ULID = '01arz3ndektsv4rrffq69g5fav';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Config::string('davetkart.media.disk'));
    }

    // ------------------------------------------------- SAHIBIN GALERISI

    #[Test]
    public function owner_can_upload_a_gallery_image(): void
    {
        [$user, $inv] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->postJson($this->ownerUrl($inv), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->image('dugun.jpg', 800, 600),
            ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'url']]);

        $this->assertDatabaseHas('media', [
            'invitation_id' => $inv->id,
            'kind' => MediaKind::Gallery->value,
            'mime_type' => 'image/jpeg',
        ]);
    }

    /** 🔴 IDOR: baskasinin davetiyesine yukleme. T14 — satir OLUSMAMALI. */
    #[Test]
    public function owner_cannot_upload_to_someone_elses_invitation(): void
    {
        [, $inv] = $this->ownedInvitation();
        $intruder = User::factory()->create();

        $this->withToken($this->tokenFor($intruder))
            ->postJson($this->ownerUrl($inv), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        $this->assertDatabaseCount('media', 0);
    }

    #[Test]
    public function gallery_upload_requires_authentication(): void
    {
        [, $inv] = $this->ownedInvitation();

        $this->postJson($this->ownerUrl($inv), [
            'kind' => MediaKind::Gallery->value,
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->assertUnauthorized();

        $this->assertDatabaseCount('media', 0);
    }

    /** Sahibin ucu YALNIZCA gallery kabul eder — en az ayricalik. */
    #[Test]
    public function owner_endpoint_rejects_guest_only_kinds(): void
    {
        [$user, $inv] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->postJson($this->ownerUrl($inv), [
                'kind' => MediaKind::RsvpPhoto->value,
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);

        $this->assertDatabaseCount('media', 0);
    }

    // ------------------------------------------------- DOSYA GUVENLIGI

    /**
     * 🔴 Dogrulama UZANTIYA DEGIL MIME'e bakiyor.
     *
     * Dosyanin adi '.jpg', bildirdigi tip 'application/x-php'. `mimes:` kurali
     * (uzantiya bakan) bunu GECIRIRDI; `mimetypes:` eliyor. Yani bu test
     * 'mimetypes:' -> 'mimes:' mutasyonunu oldurur.
     *
     * ⚠️ Ne KANITLAMAZ: MIME'in dosya ICERIGINDEN okundugunu. Sebep
     * Illuminate\Http\Testing\File::getMimeType():
     *
     *     return $this->mimeTypeToReport ?: MimeType::from($this->name);
     *
     * Sahte dosya finfo'ya HIC gitmiyor — tipi ya rapor edilen degerden ya
     * DOSYA ADINDAN uretiyor. Uretimde ise Symfony\...\UploadedFile
     * MimeTypes::guessMimeType() ile gercek baytlara bakiyor.
     *
     * 🔴 Yani icerikten-MIME dogrulamasi bu test altyapisiyla DOGRULANAMAZ
     * (T15). Gercek kaniti FAZ-6-ELLE-DOGRULAMA.md adim 9'da: diske gercek
     * bir PHP dosyasi '.jpg' adiyla yuklenmeye calisiliyor.
     */
    #[Test]
    public function the_upload_is_validated_by_mime_not_extension(): void
    {
        [$user, $inv] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->postJson($this->ownerUrl($inv), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->create('kotu.jpg', 10, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);

        $this->assertDatabaseCount('media', 0);
    }

    /** 🔴 Diskteki ad ORIJINAL adi tasimaz — path traversal ve uzerine yazma. */
    #[Test]
    public function the_stored_filename_is_random(): void
    {
        [$user, $inv] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->postJson($this->ownerUrl($inv), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->image('../../gizli.jpg', 100, 100),
            ])
            ->assertCreated();

        $media = Media::query()->firstOrFail();

        $this->assertStringNotContainsString('gizli', $media->path);
        $this->assertStringNotContainsString('..', $media->path);
        $this->assertStringStartsWith('media/'.MediaKind::Gallery->value.'/', $media->path);
    }

    #[Test]
    public function an_oversized_file_is_rejected(): void
    {
        [$user, $inv] = $this->ownedInvitation();
        $limitKb = MediaKind::Gallery->maxSizeKb();

        $this->withToken($this->tokenFor($user))
            ->postJson($this->ownerUrl($inv), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->image('buyuk.jpg')->size($limitKb + 1),
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('media', 0);
    }

    // ------------------------------------------------------------ KOTA

    /** 🔴 Kota metrigi COUNT(*) — sinir "kac dosya", "kac bayt" degil. */
    #[Test]
    public function the_gallery_quota_is_enforced(): void
    {
        [$user, $inv] = $this->ownedInvitation();
        $limit = MediaKind::Gallery->maxPerInvitation();

        Media::factory()->count($limit)->create(['invitation_id' => $inv->id]);

        $this->withToken($this->tokenFor($user))
            ->postJson($this->ownerUrl($inv), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->image('fazla.jpg'),
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::MediaQuotaExceeded->value);

        $this->assertDatabaseCount('media', $limit);
    }

    /** H9: sinir SAHIBE soylenir. */
    #[Test]
    public function the_owner_learns_the_gallery_limit(): void
    {
        [$user, $inv] = $this->ownedInvitation();
        $limit = MediaKind::Gallery->maxPerInvitation();

        Media::factory()->count($limit)->create(['invitation_id' => $inv->id]);

        $this->withToken($this->tokenFor($user))
            ->postJson($this->ownerUrl($inv), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->image('fazla.jpg'),
            ])
            ->assertJsonPath('error.params.limit', $limit);
    }

    /** 🔴 H9: ic sayac MISAFIRE verilmez. */
    #[Test]
    public function the_guest_never_learns_the_quota(): void
    {
        $inv = $this->openInvitation();
        $limit = MediaKind::RsvpPhoto->maxPerInvitation();

        Media::factory()->rsvpPhoto()->count($limit)->create(['invitation_id' => $inv->id]);

        $this->postJson($this->guestUrl($inv), [
            'kind' => MediaKind::RsvpPhoto->value,
            'file' => UploadedFile::fake()->image('ani.jpg'),
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::MediaQuotaExceeded->value)
            ->assertJsonMissingPath('error.params');
    }

    /** Kota TUR BASINA sayilir; galeri dolu olsa da LCV fotografi gecer. */
    #[Test]
    public function quotas_are_counted_per_kind(): void
    {
        $inv = $this->openInvitation();

        Media::factory()
            ->count(MediaKind::Gallery->maxPerInvitation())
            ->create(['invitation_id' => $inv->id]);

        $this->postJson($this->guestUrl($inv), [
            'kind' => MediaKind::RsvpPhoto->value,
            'file' => UploadedFile::fake()->image('ani.jpg'),
        ])->assertCreated();
    }

    // -------------------------------------------------- MISAFIRIN YOLU

    #[Test]
    public function guest_can_upload_rsvp_media_without_a_token(): void
    {
        $inv = $this->openInvitation();

        $this->postJson($this->guestUrl($inv), [
            'kind' => MediaKind::RsvpPhoto->value,
            'file' => UploadedFile::fake()->image('ani.jpg', 400, 400),
        ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'url']]);

        $this->assertDatabaseHas('media', [
            'invitation_id' => $inv->id,
            'kind' => MediaKind::RsvpPhoto->value,
        ]);
    }

    /** 🔴 Misafir GALERIYE yukleyemez — FormRequest katmani. */
    #[Test]
    public function guest_cannot_upload_to_the_gallery(): void
    {
        $inv = $this->openInvitation();

        $this->postJson($this->guestUrl($inv), [
            'kind' => MediaKind::Gallery->value,
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->assertStatus(422);

        $this->assertDatabaseCount('media', 0);
    }

    /**
     * 🔴 AYNI kural, ACTION seviyesinde.
     *
     * Ustteki test FormRequest'i kanitlar; bu test Action'in KENDI degismezini
     * kanitlar. FormRequest atlanabilir (konsol komutu, kuyruk isi, yeni bir
     * uc) ve o gun bu guard tek savunma olur (T15: zinciri halkalara ayir).
     */
    #[Test]
    public function the_guest_action_refuses_owner_only_kinds(): void
    {
        $inv = $this->openInvitation();

        $this->expectException(LogicException::class);

        app(StoreGuestMediaAction::class)->handle(
            $inv->id,
            MediaKind::Gallery,
            UploadedFile::fake()->image('x.jpg'),
        );
    }

    /** Yayinda olmayan davetiyeye misafir yukleyemez. */
    #[Test]
    public function guest_cannot_upload_to_an_unpublished_invitation(): void
    {
        $inv = Invitation::factory()->create(['show_rsvp' => true]);

        $this->postJson($this->guestUrl($inv), [
            'kind' => MediaKind::RsvpPhoto->value,
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->assertNotFound();

        $this->assertDatabaseCount('media', 0);
    }

    /** LCV modulu kapaliysa medya ucu da yoktur (C6). */
    #[Test]
    public function guest_cannot_upload_when_the_rsvp_module_is_closed(): void
    {
        $inv = $this->openInvitation(['show_rsvp' => false]);

        $this->postJson($this->guestUrl($inv), [
            'kind' => MediaKind::RsvpPhoto->value,
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->assertNotFound();

        $this->assertDatabaseCount('media', 0);
    }

    /**
     * 🔴 Suresi dolmus davetiyeye yukleme YOK.
     *
     * Bu test 6.12 refactor'unun sebebi: kural kopyalansaydi burada
     * unutulurdu ve davetiye basina ~2.4 GB suresiz yukleme acilirdi.
     */
    #[Test]
    public function guest_cannot_upload_after_the_deadline(): void
    {
        $inv = $this->openInvitation(['rsvp_deadline' => now()->subDay()]);

        $this->postJson($this->guestUrl($inv), [
            'kind' => MediaKind::RsvpPhoto->value,
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpDeadlinePassed->value);

        $this->assertDatabaseCount('media', 0);
    }

    /** E8 / ders 43: son GUN hala gecerli. */
    #[Test]
    public function guest_can_upload_on_the_deadline_day(): void
    {
        $inv = $this->openInvitation(['rsvp_deadline' => now()]);

        $this->postJson($this->guestUrl($inv), [
            'kind' => MediaKind::RsvpPhoto->value,
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->assertCreated();
    }

    // ------------------------------------------- LCV'YE MEDYA BAGLAMA

    #[Test]
    public function a_guest_can_attach_their_own_media_to_an_rsvp(): void
    {
        $inv = $this->openInvitation();
        $media = Media::factory()->rsvpPhoto()->create(['invitation_id' => $inv->id]);

        $this->postJson($this->rsvpUrl($inv), $this->rsvpPayload([
            'photoMediaId' => $media->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.photoUrl', $media->url());

        $this->assertDatabaseHas('rsvps', ['photo_media_id' => $media->id]);
    }

    /**
     * 🔴 BASKA davetiyenin medyasi SESSIZCE dusurulur.
     *
     * T14: yanit 201 ve ayirt edilemez, yani KANITI kolon tasir. 403 donmek
     * saldirgana kimligin GERCEK oldugunu soylerdi (L2).
     */
    #[Test]
    public function media_from_another_invitation_is_silently_dropped(): void
    {
        $inv = $this->openInvitation();
        $other = Media::factory()->rsvpPhoto()->create();

        $this->postJson($this->rsvpUrl($inv), $this->rsvpPayload([
            'photoMediaId' => $other->id,
        ]))
            ->assertCreated()
            ->assertJsonMissingPath('data.photoUrl');

        $this->assertDatabaseHas('rsvps', [
            'invitation_id' => $inv->id,
            'photo_media_id' => null,
        ]);
    }

    /**
     * 🔴 AYNI davetiyenin GALERI fotografi da dusurulur.
     *
     * Tur kontrolu olmasaydi bu gecerdi: medya davetiyeye AIT, yani ilk kosul
     * saglaniyor. Misafir sahibin galeri gorselini kendi yanitina ilistirirdi.
     */
    #[Test]
    public function a_gallery_photo_cannot_be_attached_to_an_rsvp(): void
    {
        $inv = $this->openInvitation();
        $gallery = Media::factory()->create(['invitation_id' => $inv->id]);

        $this->postJson($this->rsvpUrl($inv), $this->rsvpPayload([
            'photoMediaId' => $gallery->id,
        ]))->assertCreated();

        $this->assertDatabaseHas('rsvps', ['photo_media_id' => null]);
    }

    /** Video kimligini foto alanina yazmak da gecmez. */
    #[Test]
    public function a_video_id_cannot_be_used_as_a_photo(): void
    {
        $inv = $this->openInvitation();
        $video = Media::factory()->rsvpVideo()->create(['invitation_id' => $inv->id]);

        $this->postJson($this->rsvpUrl($inv), $this->rsvpPayload([
            'photoMediaId' => $video->id,
        ]))->assertCreated();

        $this->assertDatabaseHas('rsvps', ['photo_media_id' => null]);
    }

    #[Test]
    public function an_unknown_media_id_is_silently_dropped(): void
    {
        $inv = $this->openInvitation();

        $this->postJson($this->rsvpUrl($inv), $this->rsvpPayload([
            'photoMediaId' => self::YOK_OLAN_ULID,
        ]))->assertCreated();

        $this->assertDatabaseHas('rsvps', ['photo_media_id' => null]);
    }

    /** C7: medya yoksa ANAHTAR DA gitmez. */
    #[Test]
    public function an_rsvp_without_media_omits_the_url_keys(): void
    {
        $inv = $this->openInvitation();

        $this->postJson($this->rsvpUrl($inv), $this->rsvpPayload())
            ->assertCreated()
            ->assertJsonMissingPath('data.photoUrl')
            ->assertJsonMissingPath('data.videoUrl');
    }

    /** Sahibin LCV listesi de medya URL'ini tasir. */
    #[Test]
    public function the_owner_list_carries_media_urls(): void
    {
        [$user, $inv] = $this->ownedInvitation(['show_rsvp' => true]);
        $media = Media::factory()->rsvpPhoto()->create(['invitation_id' => $inv->id]);

        // 🔴 create() DEGIL forceCreate(): `ip_hash` ve `photo_media_id`
        // #[Fillable] listesinde YOK (6.18) — bilerek. create() onlari sessizce
        // duserdi ve `ip_hash` NOT NULL ihlaliyle patlardi.
        // Fabrikalar Model::unguarded() icinde calistigi icin bu sorunu
        // yasamaz; ELLE create() cagiran testler yasar.
        $inv->rsvps()->forceCreate([
            'guest_name' => 'Melis',
            'guest_count' => 1,
            'status' => RsvpStatus::Attending,
            'ip_hash' => str_repeat('a', 64),
            'photo_media_id' => $media->id,
        ]);

        $this->withToken($this->tokenFor($user))
            ->getJson(route('invitations.rsvps.index', $inv))
            ->assertOk()
            ->assertJsonPath('data.0.photoUrl', $media->url());
    }

    /** 🔴 Sozlesme ic kimligi SIZDIRMAZ (C1). */
    #[Test]
    public function the_rsvp_response_never_exposes_media_ids(): void
    {
        $inv = $this->openInvitation();
        $media = Media::factory()->rsvpPhoto()->create(['invitation_id' => $inv->id]);

        $body = (string) $this->postJson($this->rsvpUrl($inv), $this->rsvpPayload([
            'photoMediaId' => $media->id,
        ]))->getContent();

        $this->assertStringNotContainsString('photoMediaId', $body);
        $this->assertStringNotContainsString('photo_media_id', $body);
    }

    // ---------------------------------------------------------- KUYRUK

    /** 15 saniye kurali: agir is kuyruga gider, istek beklemez. */
    #[Test]
    public function an_image_upload_queues_the_optimizer(): void
    {
        Queue::fake();
        [$user, $inv] = $this->ownedInvitation();

        $this->withToken($this->tokenFor($user))
            ->postJson($this->ownerUrl($inv), [
                'kind' => MediaKind::Gallery->value,
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])->assertCreated();

        Queue::assertPushed(OptimizeUploadedImage::class);
    }

    /** 🔴 T6: yoklugun testi. Video optimize EDILMEZ (ffmpeg ayri bir is). */
    #[Test]
    public function a_video_upload_does_not_queue_the_optimizer(): void
    {
        Queue::fake();
        $inv = $this->openInvitation();

        $this->postJson($this->guestUrl($inv), [
            'kind' => MediaKind::RsvpVideo->value,
            // 🔴 IKI ayri tuzak, iki ayri cozum — ikisi de vendor'dan okundu:
            //
            // 1) createWithContent(), create() DEGIL. FileFactory::create()
            //    `new File($name, tmpfile())` ile BOS bir dosya uretir ve
            //    yalnizca `sizeToReport`'u ayarlar: getSize() 512 KB der ama
            //    DISKTEKI dosya 0 bayttir. Action boyutu diskten okuyor,
            //    yani CHECK (size_bytes > 0) ihlal edilirdi.
            //
            // 2) mimeType() acikca veriliyor. createWithContent()
            //    `mimeTypeToReport`'u BOS birakir, o zaman
            //    File::getMimeType() -> MimeType::from('klip.mp4') ->
            //    Arr::first(MimeTypes::getMimeTypes('mp4')) calisir. Ve
            //    symfony/mime'da o liste soyle basliyor:
            //      'mp4' => ['application/mp4', 'video/mp4', ...]
            //    Yani ILK eleman 'application/mp4' — bizim beyaz listemizde
            //    olmayan bir tip. Uzantidan tip tahmin etmek, dogru cevabin
            //    LISTEDE OLMASINA degil BASINDA OLMASINA bagli.
            'file' => UploadedFile::fake()
                ->createWithContent('klip.mp4', str_repeat("\0", 4096))
                ->mimeType('video/mp4'),
        ])->assertCreated();

        Queue::assertNotPushed(OptimizeUploadedImage::class);
    }

    // -------------------------------------------------------- YARDIMCI

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array{0: User, 1: Invitation}
     */
    private function ownedInvitation(array $overrides = []): array
    {
        $user = User::factory()->create();

        /** @var array<string, mixed> $attributes */
        $attributes = array_merge(['user_id' => $user->id], $overrides);

        return [$user, Invitation::factory()->published()->create($attributes)];
    }

    /**
     * Yayinda, LCV acik, son tarihi olmayan davetiye.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function openInvitation(array $overrides = []): Invitation
    {
        /** @var array<string, mixed> $attributes */
        $attributes = array_merge([
            'show_rsvp' => true,
            'rsvp_deadline' => null,
        ], $overrides);

        return Invitation::factory()->published()->create($attributes);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function ownerUrl(Invitation $invitation): string
    {
        return route('invitations.media.store', $invitation);
    }

    private function guestUrl(Invitation $invitation): string
    {
        return route('public.invitations.media.store', $invitation);
    }

    private function rsvpUrl(Invitation $invitation): string
    {
        return route('public.invitations.rsvps.store', $invitation);
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function rsvpPayload(array $overrides = []): array
    {
        return array_merge([
            'guestName' => 'Melis Kaya',
            'guestCount' => 2,
            'status' => RsvpStatus::Attending->value,
        ], $overrides);
    }
}
