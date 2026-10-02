<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Events\InvitationChanged;
use App\Listeners\ClearInvitationCache;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 4'un kaniti: misafirin okuma yolu, gorunurluk, cache ve ETag.
 *
 * Bu fazda uc yerde "sessizce yanlis olabilir" dedik; ucunun de panzehiri
 * burada: otomatik kesif kopabilir, kapali modul sizabilir, taslak sizabilir.
 * T14: bir seyin YAPILMADIGINI test ederken yaniti degil ETKIYI dogrula.
 * Ayrintili aciklama: docs/rehber/tests/Feature/PublicInvitationTest.md
 */
final class PublicInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const YOK_OLAN_ULID = '01arz3ndektsv4rrffq69g5fav';

    // ------------------------------------------------------- GORUNURLUK

    #[Test]
    public function published_invitation_is_readable_without_authentication(): void
    {
        $inv = $this->published(['title' => 'Dugunumuz']);

        $this->getJson($this->url($inv))
            ->assertOk()
            ->assertJsonPath('data.id', $inv->id)
            ->assertJsonPath('data.invitation.title', 'Dugunumuz');
    }

    /** 🔴 Fazin en onemli testi: taslak MISAFIRE SIZMAZ. */
    #[Test]
    public function saved_invitation_is_not_readable(): void
    {
        $inv = Invitation::factory()->create(['title' => 'HENUZ YAYINDA DEGIL']);

        $response = $this->getJson($this->url($inv))
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        // T14: 404 gormek yetmez — baslik govdede hic gecmemeli.
        $this->assertStringNotContainsString('HENUZ YAYINDA DEGIL', $this->body($response));
    }

    /** T11: ayirt edilemezlik HAM GOVDE karsilastirmasiyla dogrulanir. */
    #[Test]
    public function unpublished_and_missing_invitations_are_indistinguishable(): void
    {
        $inv = Invitation::factory()->create();

        $yayinlanmamis = $this->getJson($this->url($inv));
        $yok = $this->getJson(route('public.invitations.show', self::YOK_OLAN_ULID));

        $this->assertSame($yayinlanmamis->getStatusCode(), $yok->getStatusCode());
        $this->assertSame($this->body($yayinlanmamis), $this->body($yok));
    }

    #[Test]
    public function soft_deleted_invitation_is_not_readable(): void
    {
        $inv = $this->published();
        $inv->delete();

        $this->getJson($this->url($inv))->assertNotFound();
    }

    /** Bicimsiz kimlik veritabanina HIC gitmez; 404 rota katmaninda verilir. */
    #[Test]
    public function a_malformed_id_never_reaches_the_database(): void
    {
        DB::enableQueryLog();

        $this->getJson('/api/public/invitations/bu-bir-ulid-degil')->assertNotFound();

        $this->assertSame([], DB::getQueryLog());
    }

    // ------------------------------------------------------ SOZLESME (C4)

    /** 🔴 C4: kapali hediye modulunun verisi govdeye HIC girmez. */
    #[Test]
    public function gift_details_are_absent_when_the_module_is_off(): void
    {
        $inv = $this->published([
            'show_gift' => false,
            'iban' => 'TR330006100519786457841326',
            'bank_name' => 'Ziraat',
            'account_holder' => 'Ayse Yilmaz',
        ]);

        $response = $this->getJson($this->url($inv))->assertOk();

        // Bos string DEGIL — ANAHTARIN KENDISI yok.
        $response->assertJsonMissingPath('data.invitation.iban')
            ->assertJsonMissingPath('data.invitation.bankName')
            ->assertJsonMissingPath('data.invitation.accountHolder')
            ->assertJsonMissingPath('data.invitation.giftOptions');

        // Bayrak yine de gider: sablon neyi cizecegine ona bakarak karar verir.
        $response->assertJsonPath('data.invitation.showGift', false);

        // T14: ham govdede IBAN'in izi bile olmamali.
        $this->assertStringNotContainsString('TR3300061005', $this->body($response));
    }

    /** T6: bir davranisin hem YOKLUGU hem VARLIGI test edilir. */
    #[Test]
    public function gift_details_are_present_when_the_module_is_on(): void
    {
        $inv = $this->published([
            'show_gift' => true,
            'iban' => 'TR330006100519786457841326',
            'gift_options' => [500, 1000],
        ]);

        $this->getJson($this->url($inv))
            ->assertOk()
            ->assertJsonPath('data.invitation.iban', 'TR330006100519786457841326')
            ->assertJsonPath('data.invitation.giftOptions', [500, 1000]);
    }

    /**
     * Faz 10 (10.47): misafir tasarimi SAKLANDIGI GIBI gorur.
     *
     * Denetimde (TEST-DENETIMI §2) `names`, `venue`, `mapUrl` bos dondurulse
     * ve `date` ISO 8601'e cevrilse bu dosya yesil kaliyordu: hicbir test bu
     * alanlara bakmiyordu. Turkce veri bilerek: cok baytli karakter ve JSON'un
     * `\uXXXX` kacisi yolun uzerinde olsun.
     */
    #[Test]
    public function the_guest_sees_the_design_as_stored(): void
    {
        $inv = $this->published([
            'title' => 'Hayatımızın En Güzel Günü',
            'subtitle' => 'Sizi de aramızda görmekten mutluluk duyarız',
            'names' => 'Ayşe & Çağrı',
            'venue' => 'Çırağan Sarayı, Beşiktaş',
            'map_url' => 'https://maps.app.goo.gl/Ciragan2026',
            'event_at' => '2026-08-21 19:00:00',
            'timezone' => 'Europe/Istanbul',
            'category_id' => 'dugun',
            'palette' => 'stone',
        ]);

        $this->getJson($this->url($inv))
            ->assertOk()
            ->assertJsonPath('data.invitation.title', 'Hayatımızın En Güzel Günü')
            ->assertJsonPath('data.invitation.subtitle', 'Sizi de aramızda görmekten mutluluk duyarız')
            ->assertJsonPath('data.invitation.names', 'Ayşe & Çağrı')
            ->assertJsonPath('data.invitation.venue', 'Çırağan Sarayı, Beşiktaş')
            ->assertJsonPath('data.invitation.mapUrl', 'https://maps.app.goo.gl/Ciragan2026')
            // 🔴 Duvar saati: dilimsiz, saniyesiz. ISO 8601 ('…T19:00:00+00:00')
            // gitseydi tarayici onu UTC okur ve geri sayim uc saat kayardi (K63).
            ->assertJsonPath('data.invitation.date', '2026-08-21T19:00')
            ->assertJsonPath('data.invitation.timezone', 'Europe/Istanbul')
            ->assertJsonPath('data.invitation.categoryId', 'dugun')
            ->assertJsonPath('data.invitation.palette', 'stone');
    }

    /**
     * Faz 10 (10.47) · C6: kapali LCV modulunun AYARLARI da govdeye girmez.
     *
     * Hediye ve program icin vardi, LCV icin yoktu: `if ($this->show_rsvp)`
     * silinse dosya yesil kaliyordu.
     */
    #[Test]
    public function rsvp_settings_are_absent_when_the_module_is_off(): void
    {
        $inv = $this->published([
            'show_rsvp' => false,
            'rsvp_deadline' => '2026-08-01',
            'ask_menu_preference' => true,
        ]);

        $response = $this->getJson($this->url($inv))
            ->assertOk()
            ->assertJsonMissingPath('data.invitation.rsvpDeadline')
            ->assertJsonMissingPath('data.invitation.askMenuPreference')
            ->assertJsonPath('data.invitation.showRSVP', false);

        $this->assertStringNotContainsString('2026-08-01', $this->body($response));
    }

    /** T6: yoklugun karsiligi. Tarih yalnizca gun: LCV gun sonuna kadar acik. */
    #[Test]
    public function rsvp_settings_are_present_when_the_module_is_on(): void
    {
        $inv = $this->published([
            'show_rsvp' => true,
            'rsvp_deadline' => '2026-08-01',
            'ask_menu_preference' => true,
        ]);

        $this->getJson($this->url($inv))
            ->assertOk()
            ->assertJsonPath('data.invitation.rsvpDeadline', '2026-08-01')
            ->assertJsonPath('data.invitation.askMenuPreference', true);
    }

    #[Test]
    public function the_timeline_is_absent_when_the_module_is_off(): void
    {
        $inv = $this->published(['show_timeline' => false]);
        $inv->timelineEvents()->create(['time' => '19:00', 'title' => 'SURPRIZ', 'sort_order' => 0]);

        $response = $this->getJson($this->url($inv))->assertOk();

        $response->assertJsonMissingPath('data.invitation.timelineEvents');
        $this->assertStringNotContainsString('SURPRIZ', $this->body($response));
    }

    /** 🔴 4.2a: misafirin program adimlari ARTAN KIMLIK tasimaz. */
    #[Test]
    public function timeline_events_do_not_expose_their_ids(): void
    {
        $inv = $this->published(['show_timeline' => true]);
        $inv->timelineEvents()->create(['time' => '19:00', 'title' => 'Nikah', 'sort_order' => 0]);

        $adim = $this->getJson($this->url($inv))
            ->assertOk()
            ->json('data.invitation.timelineEvents.0');

        $this->assertSame(['time', 'title', 'description'], array_keys($adim));
    }

    /**
     * 🔴 Galeri DIZIDEKI sirayla gelir ve kimlik tasimaz (C5).
     *
     * Sira, kayitlarin olusma sirasinin TERSI verildi: galeriyi created_at'e
     * gore siralayan bir mutasyon bu testi gecemez.
     */
    #[Test]
    public function gallery_images_follow_the_stored_order_without_ids(): void
    {
        $inv = $this->published(['show_gallery' => true]);
        $first = Media::factory()->create(['invitation_id' => $inv->id]);
        $second = Media::factory()->create(['invitation_id' => $inv->id]);

        $inv->gallery_media_ids = [$second->id, $first->id];
        $inv->save();

        $response = $this->getJson($this->url($inv))
            ->assertOk()
            ->assertJsonCount(2, 'data.invitation.galleryImages')
            ->assertJsonPath('data.invitation.galleryImages.0.url', $second->url())
            ->assertJsonPath('data.invitation.galleryImages.1.url', $first->url());

        $this->assertSame(['url'], array_keys((array) $response->json('data.invitation.galleryImages.0')));
    }

    #[Test]
    public function the_gallery_is_absent_when_the_module_is_off(): void
    {
        $inv = $this->published(['show_gallery' => false]);
        $media = Media::factory()->create(['invitation_id' => $inv->id]);

        $inv->gallery_media_ids = [$media->id];
        $inv->save();

        $this->getJson($this->url($inv))
            ->assertOk()
            ->assertJsonMissingPath('data.invitation.galleryImages');
    }

    /**
     * C5: misafirin isine yaramayan sunucu ustverisi gonderilmez.
     *
     * Faz 10 (10.21): sahibin Resource'una `publishedAt` ve `releasableUntil`
     * eklendi. Ham govdede aranir: yol bazli iddia yalnizca baktigi yeri
     * gorur, alan baska bir duzeyde sizsa da yesil kalirdi.
     */
    #[Test]
    public function server_metadata_is_not_exposed(): void
    {
        $inv = $this->published();

        $response = $this->getJson($this->url($inv))
            ->assertOk()
            ->assertJsonMissingPath('data.status')
            ->assertJsonMissingPath('data.updatedAt');

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('publishedAt', $body);
        $this->assertStringNotContainsString('releasableUntil', $body);
    }

    /**
     * 🔴 C4'un varlik sebebi: maskeleme SAHIBIN verisini silmedi.
     *
     * Tek Resource'a maskeleme koysaydik davetiye sahibi kendi IBAN'ini
     * editorunde goremezdi.
     */
    #[Test]
    public function the_owner_still_sees_what_the_guest_cannot(): void
    {
        $ayse = User::factory()->create();
        $inv = $this->published([
            'user_id' => $ayse->id,
            'show_gift' => false,
            'iban' => 'TR330006100519786457841326',
        ]);

        $this->getJson($this->url($inv))
            ->assertOk()
            ->assertJsonMissingPath('data.invitation.iban');

        // T13: ikinci kimlikli istekten once guard sifirlanir.
        $this->forgetAuthState();

        $this->withToken($ayse->createToken('api')->plainTextToken)
            ->getJson(route('invitations.show', $inv))
            ->assertOk()
            ->assertJsonPath('data.invitation.iban', 'TR330006100519786457841326');
    }

    // ------------------------------------------------------------ CACHE

    #[Test]
    public function the_second_request_does_not_touch_the_database(): void
    {
        $inv = $this->published(['show_timeline' => true]);

        $this->getJson($this->url($inv))->assertOk();

        DB::enableQueryLog();
        $this->getJson($this->url($inv))->assertOk();

        $this->assertSame([], DB::getQueryLog());
    }

    /**
     * Faz 10 (10.63): büyük harfli kimlik de aynı davetiyeyi açar.
     *
     * ULID harf duyarsız; QR okuyucular adresi büyük harfe çevirebiliyor.
     * Önceden rota biçimi geçiyor ama davetiye bulunamıyordu (404).
     * Aynı önbellek girdisine düşmeli: ayrı bir girdi açılsaydı dinleyici
     * (küçük harfli kimlikle siler) onu hiç temizlemezdi ve misafir eski
     * davetiyeyi görmeye devam ederdi.
     */
    #[Test]
    public function an_uppercase_id_opens_the_same_invitation_and_cache_entry(): void
    {
        $inv = $this->published(['names' => 'Ayşe & Çağrı']);

        $this->getJson(route('public.invitations.show', strtoupper($inv->id)))
            ->assertOk()
            ->assertJsonPath('data.id', $inv->id)
            ->assertJsonPath('data.invitation.names', 'Ayşe & Çağrı');

        $this->assertTrue(Cache::has(Invitation::publicCacheKey($inv->id)));
    }

    /**
     * 🔴 Faz 10 (10.47): her davetiyenin KENDI cache girdisi var.
     *
     * Denetimde anahtardan `$id` cikarilinca bu dosya yesil kaldi. O hatayla
     * butun davetiyeler tek girdiye duser: ilk okunan davetiye sonraki HER
     * linkte gorunur ve bir misafir baska bir ciftin davetiyesini (IBAN'i
     * dahil) okur. Mevcut testlerin hepsi TEK davetiye kullaniyordu; hata
     * ancak ikincisiyle gorunur.
     */
    #[Test]
    public function each_invitation_has_its_own_cache_entry(): void
    {
        $first = $this->published(['names' => 'Ayşe & Çağrı']);
        $second = $this->published(['names' => 'Zeynep & Emre']);

        $this->getJson($this->url($first))->assertOk();

        $this->getJson($this->url($second))
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.invitation.names', 'Zeynep & Emre');
    }

    /**
     * Zincirin 1. halkasi: model -> olay.
     *
     * 🔴 Zincir ucte bolundu cunku dinleyici ShouldHandleEventsAfterCommit;
     * RefreshDatabase her testi bir transaction'a sariyor ve o transaction
     * ROLLBACK ediliyor, yani commit geri caginimi hicbir testte kosmuyor.
     * Gerekce ve tam anlatim: kilavuz §6.
     */
    #[Test]
    public function updating_an_invitation_dispatches_the_change_event(): void
    {
        Event::fake([InvitationChanged::class]);

        $inv = $this->published();
        $inv->update(['title' => 'Yeni Baslik']);

        Event::assertDispatched(
            InvitationChanged::class,
            fn (InvitationChanged $e): bool => $e->invitation->is($inv),
        );
    }

    #[Test]
    public function deleting_an_invitation_dispatches_the_change_event(): void
    {
        Event::fake([InvitationChanged::class]);

        $this->published()->delete();

        Event::assertDispatched(InvitationChanged::class);
    }

    #[Test]
    public function restoring_an_invitation_dispatches_the_change_event(): void
    {
        $inv = $this->published();
        $inv->delete();

        Event::fake([InvitationChanged::class]);
        $inv->restore();

        Event::assertDispatched(InvitationChanged::class);
    }

    /**
     * Yalnizca program degisse bile: UpdateInvitationAction'daki touch() sayesinde.
     *
     * 🔴 travel() SART, konfor degil. touch() yalnizca `updated_at` GERCEKTEN
     * degistiginde kaydeder ve PostgresGrammar::getDateFormat() 'Y-m-d H:i:s'
     * dondurur — MIKROSANIYE YOK. Ayni saniye icinde olusturulup touch()'lanan
     * bir kayitta yeni damga eskisiyle birebir ayni string olur;
     * Model::save() isDirty() false gorur, performUpdate() cagrilmaz ve
     * 'updated' olayi HIC firlamaz.
     *
     * Uretimde bu kurgu olusmaz: UpdateInvitationAction'a gelen model DB'den
     * saniyeler once yazilmis bir damgayla gelir. Yani kusur URETIMDE degil
     * TESTIN KURGUSUNDA idi (ders 33) — ve testi uyarliyoruz, uretimi degil
     * (ders 40 / T15).
     *
     * Ayrintili aciklama: docs/rehber/tests/Feature/PublicInvitationTest.md §6.1
     */
    #[Test]
    public function touching_an_invitation_dispatches_the_change_event(): void
    {
        $inv = $this->published();

        // Zamani DETERMINISTIK olarak ilerlet — saniye sinirina denk gelmeyi
        // ummak flaky test uretirdi (T12). Laravel tearDown'da test-now'i
        // kendisi sifirliyor (InteractsWithTestCaseLifecycle), sizinti yok.
        $this->travel(1)->second();

        Event::fake([InvitationChanged::class]);
        $inv->touch();

        Event::assertDispatched(InvitationChanged::class);
    }

    /** T6'nin yoklugu: yeni kaydin cache girdisi olamaz, olay da gerekmez. */
    #[Test]
    public function creating_an_invitation_does_not_dispatch_the_change_event(): void
    {
        Event::fake([InvitationChanged::class]);

        Invitation::factory()->create();

        Event::assertNotDispatched(InvitationChanged::class);
    }

    /**
     * Zincirin 2. halkasi: olay -> dinleyici.
     *
     * 🔴 Otomatik kesif KODA BAKARAK GORUNMEZ. handle()'in tip belirtimi
     * bozulursa dinleyici sessizce hic cagrilmaz; bunu yakalayan tek sey bu.
     */
    #[Test]
    public function the_change_event_is_wired_to_the_cache_listener(): void
    {
        $listeners = Event::getRawListeners()[InvitationChanged::class] ?? [];

        $this->assertContains(ClearInvitationCache::class.'@handle', $listeners);
    }

    /** Zincirin 3. halkasi: dinleyici -> cache. Anahtar controller'inkiyle ayni mi? */
    #[Test]
    public function the_listener_drops_the_public_cache_entry(): void
    {
        $inv = $this->published();
        $key = Invitation::publicCacheKey($inv->id);

        $this->getJson($this->url($inv))->assertOk();
        $this->assertTrue(Cache::has($key));

        (new ClearInvitationCache)->handle(new InvitationChanged($inv));

        $this->assertFalse(Cache::has($key));
    }

    /**
     * Erteleme BILINCLI bir karar; kaldirilirsa transaction yarisi geri gelir.
     * Davranisi testte gozlemleyemiyoruz (yukaridaki not), o yuzden niyeti
     * kilitliyoruz.
     */
    #[Test]
    public function the_listener_waits_for_the_transaction_to_commit(): void
    {
        $this->assertInstanceOf(ShouldHandleEventsAfterCommit::class, new ClearInvitationCache);
    }

    // ------------------------------------------------------------- ETAG

    #[Test]
    public function the_response_carries_an_etag(): void
    {
        $inv = $this->published();

        $etag = $this->etag($this->getJson($this->url($inv))->assertOk());

        $this->assertMatchesRegularExpression('/^"[0-9a-f]+"$/', $etag);
    }

    /** 🔴 Fazin bitis olcutu: ikinci istek 304 ve GOVDESIZ doner. */
    #[Test]
    public function a_matching_etag_returns_304_without_a_body(): void
    {
        $inv = $this->published();
        $url = $this->url($inv);

        $etag = $this->etag($this->getJson($url));

        $response = $this->getJson($url, ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $this->assertSame('', $this->body($response));
    }

    /** RFC 7232: '*' her surumle eslesir — elle yazsaydik kacirirdik. */
    #[Test]
    public function a_wildcard_if_none_match_returns_304(): void
    {
        $inv = $this->published();

        $this->getJson($this->url($inv), ['If-None-Match' => '*'])->assertStatus(304);
    }

    #[Test]
    public function a_stale_etag_returns_the_full_body(): void
    {
        $inv = $this->published(['title' => 'Dugunumuz']);

        $this->getJson($this->url($inv), ['If-None-Match' => '"bayat"'])
            ->assertOk()
            ->assertJsonPath('data.invitation.title', 'Dugunumuz');
    }

    /** Iki katman birlikte: cache dusunce govde degisir, govde degisince ETag. */
    #[Test]
    public function the_etag_changes_after_the_invitation_changes(): void
    {
        $inv = $this->published(['title' => 'Eski']);
        $url = $this->url($inv);

        $once = $this->etag($this->getJson($url));

        $inv->update(['title' => 'Yeni']);

        // Dinleyicinin commit sonrasi yapacagi is; testte transaction yuzunden
        // kosmadigi icin (kilavuz §6) burada elle yapiliyor.
        Cache::forget(Invitation::publicCacheKey($inv->id));

        $sonra = $this->etag($this->getJson($url));

        $this->assertNotSame($once, $sonra);

        // Ve eski ETag artik 304 uretmez.
        $this->getJson($url, ['If-None-Match' => $once])->assertOk();
    }

    // ------------------------------------------------------- YARDIMCILAR

    /**
     * Yayinlanmis bir davetiye; verilen alanlar varsayilanlari ezer.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function published(array $overrides = []): Invitation
    {
        return Invitation::factory()->published()->create($overrides);
    }

    private function url(Invitation $invitation): string
    {
        return route('public.invitations.show', $invitation);
    }

    /** @param  TestResponse<\Illuminate\Http\Response>  $response */
    private function body(TestResponse $response): string
    {
        return $response->getContent() ?: '';
    }

    /**
     * TestResponse::__get sihri yerine acik public ozellik uzerinden.
     *
     * @param  TestResponse<\Illuminate\Http\Response>  $response
     */
    private function etag(TestResponse $response): string
    {
        return (string) $response->baseResponse->headers->get('ETag');
    }
}
