<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\RsvpStatus;
use App\Enums\SubscriptionTier;
use App\Http\Requests\Rsvp\StoreRsvpRequest;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\Rsvp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LCV (RSVP) yolu — gercek bir dugunun verisiyle, katman katman.
 *
 * Her yazma testi VERITABANINI, her okuma testi TAM anahtar kumesini dogrular (T14).
 * 🔴 Dort test KOD HATASI yuzunden bilerek KIRMIZI: kilavuz §4.
 * Ayrintili aciklama: docs/rehber/tests/Feature/RsvpTest.md
 */
final class RsvpTest extends TestCase
{
    use RefreshDatabase;

    /** Gecerli bicimde ama hic uretilmemis bir ULID (HasUlids kucuk harf uretir). */
    private const string YOK_OLAN_ULID = '01jbz8q4n6r2w3x5y7t9v0kd1m';

    /** Testlerin "bugun"u: 20 Eylul 2026, Istanbul'da 15:00. */
    private const string SIMDI_UTC = '2026-09-20 12:00:00';

    /** Turk Telekom blogundan bir ev baglantisi. */
    private const string MISAFIR_IP = '78.180.45.12';

    private const string BULUNAMADI_GOVDESI = '{"error":{"code":"RESOURCE_NOT_FOUND"}}';

    /** RsvpResource'un disari verdigi anahtarlar — mesajsiz ve mesajli hali (C1/C7). */
    private const array YANIT_ANAHTARLARI = ['id', 'guestName', 'guestCount', 'menuPreference', 'status', 'createdAt'];

    private const array YANIT_ANAHTARLARI_MESAJLI = [...self::YANIT_ANAHTARLARI, 'message'];

    private ?User $gulsah = null;

    protected function setUp(): void
    {
        parent::setUp();

        // T12: sonucu kostugu gune bagli test olmaz. Son tarih 10 Ekim, bugun 20 Eylul.
        $this->travelTo(CarbonImmutable::parse(self::SIMDI_UTC, 'UTC'));
    }

    // ------------------------------------------------------------ MUTLU YOL

    #[Test]
    public function a_guest_reply_is_stored_and_echoed_exactly_as_submitted(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $form = $this->lcvFormu();

        $data = $this->veri($this->lcvGonder($dugun, $form)
            ->assertCreated()
            // Kucuk harfli ULID: HasUlids uretir, whereUlid rotasi ve frontend `id: string` bunu bekler.
            ->assertJsonPath('data.id', fn (string $id): bool => preg_match('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/', $id) === 1));

        $this->assertEqualsCanonicalizing(self::YANIT_ANAHTARLARI_MESAJLI, array_keys($data));
        $this->assertSame('Şeyma Şen', $data['guestName']);
        $this->assertSame(3, $data['guestCount']);
        $this->assertSame(RsvpStatus::Attending->value, $data['status']);
        $this->assertSame($form['menuPreference'], $data['menuPreference']);
        $this->assertSame($form['message'], $data['message']);
        $this->assertSame('2026-09-20T12:00:00+00:00', $data['createdAt']);

        // T14: yanit degil ETKI — satir gonderilenin birebir aynisi.
        $this->assertDatabaseCount('rsvps', 1);
        $this->assertDatabaseHas('rsvps', [
            'id' => $data['id'],
            'invitation_id' => $dugun->id,
            'guest_name' => 'Şeyma Şen',
            'guest_count' => 3,
            'status' => RsvpStatus::Attending->value,
            'menu_preference' => $form['menuPreference'],
            'message' => $form['message'],
        ]);
    }

    /**
     * Katilamayan misafir katilan sayilirsa hem cift yaniltilir hem kota yanar.
     *
     * @return iterable<string, array{RsvpStatus, string}>
     */
    public static function misafirCevaplari(): iterable
    {
        foreach (RsvpStatus::cases() as $durum) {
            yield $durum->value => [$durum, match ($durum) {
                RsvpStatus::Attending => 'Kına gecesine de geliyoruz, hazır olun! 💃',
                RsvpStatus::Pending => 'İzin çıkarsa geleceğiz; cuma gününe kadar haber veririm.',
                RsvpStatus::Declined => "Maalesef o hafta Almanya'dayız, şimdiden çok mutluluklar! 🙏",
            }];
        }
    }

    #[Test]
    #[DataProvider('misafirCevaplari')]
    public function every_answer_is_recorded_as_the_guest_gave_it(RsvpStatus $durum, string $mesaj): void
    {
        $dugun = $this->dugunDavetiyesi();

        $this->lcvGonder($dugun, $this->lcvFormu([
            'guestName' => 'Ahmet Çelik',
            'guestCount' => 2,
            'status' => $durum->value,
            'message' => $mesaj,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', $durum->value)
            ->assertJsonPath('data.message', $mesaj);

        $this->assertDatabaseHas('rsvps', [
            'guest_name' => 'Ahmet Çelik',
            'status' => $durum->value,
            'message' => $mesaj,
        ]);
    }

    /** C7: opsiyonel alan yoksa anahtari da yok; menuPreference sozlesme geregi bos metin. */
    #[Test]
    public function optional_fields_may_be_omitted_and_the_message_key_disappears(): void
    {
        $dugun = $this->dugunDavetiyesi(['ask_menu_preference' => false]);

        $data = $this->veri($this->lcvGonder($dugun, [
            'guestName' => 'Oğuz Ertürk',
            'guestCount' => 2,
            'status' => RsvpStatus::Attending->value,
            'message' => '',
        ])->assertCreated());

        $this->assertEqualsCanonicalizing(self::YANIT_ANAHTARLARI, array_keys($data));
        $this->assertSame('', $data['menuPreference']);
        $this->assertDatabaseHas('rsvps', [
            'id' => $data['id'],
            'menu_preference' => null,
            'message' => null,
        ]);
    }

    /** N1: aidiyet URL'den gelir; govdeye yazilan sunucu alanlari yok sayilir. */
    #[Test]
    public function server_owned_fields_in_the_body_are_ignored(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $baskaDugun = $this->dugunDavetiyesi(['title' => 'Selin & Kaan Evleniyor']);

        $data = $this->veri($this->lcvGonder($dugun, $this->lcvFormu([
            'id' => self::YOK_OLAN_ULID,
            'invitationId' => $baskaDugun->id,
            'invitation_id' => $baskaDugun->id,
            'ipHash' => str_repeat('0', 64),
            'ip_hash' => str_repeat('0', 64),
            'createdAt' => '2020-01-01T00:00:00+00:00',
        ]))->assertCreated());

        $this->assertNotSame(self::YOK_OLAN_ULID, $data['id']);
        $this->assertSame('2026-09-20T12:00:00+00:00', $data['createdAt']);
        $this->assertDatabaseCount('rsvps', 1);
        $this->assertDatabaseHas('rsvps', [
            'id' => $data['id'],
            'invitation_id' => $dugun->id,
            'ip_hash' => $this->beklenenIpHash(self::MISAFIR_IP),
        ]);
    }

    /** KVKK: ham IP hicbir kolonda durmaz; HMAC formulu sabitlenir (IPv4 + IPv6). */
    #[Test]
    public function the_guest_ip_is_stored_only_as_a_keyed_hmac(): void
    {
        $dugun = $this->dugunDavetiyesi();

        $this->lcvGonder($dugun, $this->lcvFormu(), '78.180.45.12')->assertCreated();
        $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => 'Zeynep Kılıç']), '2a02:e0:3f1:8c00::1c')
            ->assertCreated();

        /** @var array<string, string> $hashler */
        $hashler = DB::table('rsvps')->pluck('ip_hash', 'guest_name')->all();

        $this->assertSame($this->beklenenIpHash('78.180.45.12'), $hashler['Şeyma Şen']);
        $this->assertSame($this->beklenenIpHash('2a02:e0:3f1:8c00::1c'), $hashler['Zeynep Kılıç']);

        $satirlar = json_encode(DB::table('rsvps')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('78.180.45.12', $satirlar);
        $this->assertStringNotContainsString('2a02:e0:3f1', $satirlar);
    }

    /** Sinirlar BAYT degil KARAKTER sayar: 120 'Ğ' 240 bayttir ve kabul edilmelidir. */
    #[Test]
    public function text_limits_count_characters_not_bytes(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $isim = str_repeat('Ğ', 120);
        $menu = str_repeat('ş', 60);
        $mesaj = str_repeat('💍', 1000);

        $this->lcvGonder($dugun, $this->lcvFormu([
            'guestName' => $isim,
            'menuPreference' => $menu,
            'message' => $mesaj,
        ]))->assertCreated();

        $this->assertDatabaseHas('rsvps', [
            'guest_name' => $isim,
            'menu_preference' => $menu,
            'message' => $mesaj,
        ]);
    }

    /** Backend metni temizlemez ve bozmaz; parametreli sorgu SQL parcasini etkisiz kilar. */
    #[Test]
    public function markup_and_sql_fragments_are_stored_as_inert_text(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $isim = "Ayşe'nin Annesi <script>alert('xss')</script>";
        $mesaj = "'; DELETE FROM rsvps; --\n<img src=x onerror=alert(document.cookie)>";

        $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => $isim, 'message' => $mesaj]))
            ->assertCreated()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('data.guestName', $isim)
            ->assertJsonPath('data.message', $mesaj);

        $this->assertDatabaseCount('rsvps', 1);
        $this->assertDatabaseHas('rsvps', ['guest_name' => $isim, 'message' => $mesaj]);
    }

    // ----------------------------------------------------------- GORUNURLUK

    /** T11: taslak, kapali modul ve silinmis davetiye HAM GOVDEDE yok olandan ayirt edilemez. */
    #[Test]
    public function hidden_invitations_are_indistinguishable_from_missing_ones(): void
    {
        $taslak = Invitation::factory()->for($this->gulsah())->create(['show_rsvp' => true]);
        $kapali = $this->dugunDavetiyesi(['show_rsvp' => false]);
        $silinmis = $this->dugunDavetiyesi();
        $silinmis->delete();

        $olmayan = $this->lcvGonder(self::YOK_OLAN_ULID, $this->lcvFormu())->assertNotFound();
        $this->assertSame(self::BULUNAMADI_GOVDESI, $olmayan->getContent());

        foreach (['taslak' => $taslak, 'modul kapali' => $kapali, 'silinmis' => $silinmis] as $durum => $davetiye) {
            $yanit = $this->lcvGonder($davetiye, $this->lcvFormu());

            $this->assertSame(404, $yanit->getStatusCode(), $durum);
            $this->assertSame($olmayan->getContent(), $yanit->getContent(), $durum);
        }

        $this->assertDatabaseCount('rsvps', 0);
    }

    /** @return iterable<string, array{string}> */
    public static function bicimsizKimlikler(): iterable
    {
        yield 'davetiye adi' => ['gulsah-ve-emre-dugun'];
        yield 'SQL enjeksiyonu' => ["' OR 1=1 --"];
        yield 'tam sayi' => ['12345'];
        yield '25 karakter' => ['01jbz8q4n6r2w3x5y7t9v0kd1'];
        yield '27 karakter' => ['01jbz8q4n6r2w3x5y7t9v0kd1mm'];
        yield 'ilk karakter 7 ustu' => ['81jbz8q4n6r2w3x5y7t9v0kd1m'];
        yield 'Crockford disi harf' => ['01jbz8q4n6r2w3x5y7t9v0kd1u'];
        yield 'yol gezinme' => ['../../.env'];
    }

    /** O6: bicimsiz kimlik rota katmaninda elenir, veritabanina tek sorgu gitmez. */
    #[Test]
    #[DataProvider('bicimsizKimlikler')]
    public function a_malformed_invitation_id_is_rejected_before_any_query(string $kimlik): void
    {
        DB::enableQueryLog();

        $yanit = $this->postJson('/api/public/invitations/'.rawurlencode($kimlik).'/rsvps', $this->lcvFormu());

        $yanit->assertNotFound();
        $this->assertSame(self::BULUNAMADI_GOVDESI, $yanit->getContent());
        $this->assertSame([], DB::getQueryLog());
    }

    // ---------------------------------------------------- DOGRULAMA VE TIP

    /** @return iterable<string, array{string}> */
    public static function zorunluAlanlar(): iterable
    {
        yield 'isim' => ['guestName'];
        yield 'kisi sayisi' => ['guestCount'];
        yield 'durum' => ['status'];
    }

    #[Test]
    #[DataProvider('zorunluAlanlar')]
    public function a_missing_required_field_is_reported_by_its_rule(string $alan): void
    {
        $dugun = $this->dugunDavetiyesi();
        $form = $this->lcvFormu();
        unset($form[$alan]);

        $yanit = $this->lcvGonder($dugun, $form)->assertUnprocessable();

        $this->assertSame([$alan], array_keys($this->hataAlanlari($yanit)));
        $this->assertSame('required', $yanit->json("error.fields.{$alan}.0.rule"));
        $this->assertDatabaseCount('rsvps', 0);
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function gecersizDegerler(): iterable
    {
        yield 'isim tek harf' => ['guestName', 'Ş', 'min'];
        yield 'isim 121 karakter' => ['guestName', str_repeat('Ğ', 121), 'max'];
        yield 'isim dizi' => ['guestName', ['Şeyma', 'Oğuz'], 'string'];
        yield 'isim yerine telefon (sayi)' => ['guestName', 5_329_998_877, 'string'];
        yield 'isim yalnizca bosluk' => ['guestName', '   ', 'required'];
        yield 'isim gorunmez karakter' => ['guestName', "\u{200B}\u{200B}\u{FEFF}", 'required'];
        yield 'kisi sifir' => ['guestCount', 0, 'min'];
        yield 'kisi negatif' => ['guestCount', -3, 'min'];
        yield 'kisi ondalik' => ['guestCount', 2.5, 'integer'];
        yield 'kisi yaziyla' => ['guestCount', 'üç', 'integer'];
        yield 'kisi dizi' => ['guestCount', [2], 'integer'];
        yield 'kisi bos metin' => ['guestCount', '', 'required'];
        yield 'durum Turkce etiket (K21)' => ['status', 'Katılıyor', 'in'];
        yield 'durum buyuk harf' => ['status', 'ATTENDING', 'in'];
        yield 'durum dizi' => ['status', ['attending'], 'string'];
        yield 'menu 61 karakter' => ['menuPreference', str_repeat('ş', 61), 'max'];
        yield 'mesaj 1001 karakter' => ['message', str_repeat('💍', 1001), 'max'];
        yield 'mesaj nesne' => ['message', ['metin' => 'Tebrikler'], 'string'];
        yield 'foto kimligi SQL' => ['photoMediaId', "' OR 1=1 --", 'ulid'];
        yield 'foto kimligi sayi' => ['photoMediaId', 12345, 'string'];
        yield 'video kimligi 25 karakter' => ['videoMediaId', '01jbz8q4n6r2w3x5y7t9v0kd1', 'ulid'];
    }

    /** T6: yalnizca bozuk alan raporlanir; K20: hata metin degil kural adi tasir. */
    #[Test]
    #[DataProvider('gecersizDegerler')]
    public function an_invalid_value_is_rejected_by_its_rule_and_nothing_is_written(
        string $alan,
        mixed $deger,
        string $kural,
    ): void {
        $dugun = $this->dugunDavetiyesi();

        $yanit = $this->lcvGonder($dugun, $this->lcvFormu([$alan => $deger]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);

        $this->assertSame(['code', 'fields'], array_keys((array) $yanit->json('error')));
        $this->assertSame([$alan], array_keys($this->hataAlanlari($yanit)));
        $this->assertSame($kural, $yanit->json("error.fields.{$alan}.0.rule"));
        $this->assertDatabaseCount('rsvps', 0);
    }

    /** E6: ust sinir config'ten gelir ve hata zarfinda parametresiyle doner (H9). */
    #[Test]
    public function the_party_size_cap_comes_from_configuration(): void
    {
        Config::set('davetkart.rsvp.max_guests_per_entry', 6);
        $dugun = $this->dugunDavetiyesi();

        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 6]))->assertCreated();

        $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => 'Kalabalık Aile', 'guestCount' => 7]))
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.guestCount.0.rule', 'max')
            ->assertJsonPath('error.fields.guestCount.0.params.max', 6);

        $this->assertDatabaseCount('rsvps', 1);
    }

    /**
     * 🔴 KOD HATASI (kilavuz §4.3): Laravel'in `integer` kurali `true`yu 1 sayar.
     * Bugun 201 doner ve 1 kisilik kayit yazilir.
     */
    #[Test]
    public function a_boolean_is_not_a_party_size(): void
    {
        $dugun = $this->dugunDavetiyesi();

        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => true]))
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.guestCount.0.rule', 'integer');

        $this->assertDatabaseCount('rsvps', 0);
    }

    /** @return iterable<string, array{string, string}> */
    public static function nulBaytliMetinler(): iterable
    {
        yield 'isim' => ['guestName', "Zeynep\u{0000}Kılıç"];
        yield 'isim min kuralini atlatma' => ['guestName', "Z\u{0000}eynep"];
        yield 'mesaj' => ['message', "Çok mutlu olun\u{0000} 🎉 ailecek geliyoruz"];
        yield 'menu' => ['menuPreference', "Vegan\u{0000} glütensiz"];
    }

    /**
     * 🔴 KOD HATASI (kilavuz §4.1): PostgreSQL metni NUL'da KESER. Bugun 201 doner,
     * yanitta tam metin, satirda "Zeynep" yazar — "Z" ise min:2'yi atlatir.
     */
    #[Test]
    #[DataProvider('nulBaytliMetinler')]
    public function a_nul_byte_is_rejected_as_malformed_instead_of_being_truncated(string $alan, string $deger): void
    {
        $dugun = $this->dugunDavetiyesi();

        $this->lcvGonder($dugun, $this->lcvFormu([$alan => $deger]))
            ->assertStatus(400)
            ->assertJsonPath('error.code', ErrorCode::MalformedRequest->value);

        $this->assertDatabaseCount('rsvps', 0);
    }

    /**
     * 🔴 KOD HATASI (kilavuz §4.2): yarim kalmis JSON bugun 422 + uc 'required' alir —
     * istemciye "isim gondermedin" der, oysa gonderdi. docs/08: bicimsel bozukluk 400'dur.
     */
    #[Test]
    public function a_truncated_json_body_is_malformed_not_invalid(): void
    {
        $dugun = $this->dugunDavetiyesi();

        $yanit = $this->call(
            'POST',
            route('public.invitations.rsvps.store', $dugun),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{"guestName": "Şeyma Şen", "guestCount": 3, "status": "atten',
        );

        $yanit->assertStatus(400)->assertJsonPath('error.code', ErrorCode::MalformedRequest->value);
        $this->assertDatabaseCount('rsvps', 0);
    }

    // ------------------------------------------------------------- HONEYPOT

    /** L2: bot gercek misafirle AYNI yaniti alir (id haric her alan esit) ama satir yazilmaz. */
    #[Test]
    public function a_honeypot_hit_is_answered_like_a_real_reply_but_never_written(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $form = $this->lcvFormu();

        $gercek = $this->veri($this->lcvGonder($dugun, $form)->assertCreated());
        $bot = $this->veri($this->lcvGonder($dugun, $this->botFormu($form), '185.220.101.4')->assertCreated());

        $this->assertEqualsCanonicalizing(array_keys($gercek), array_keys($bot));
        $this->assertSame(
            array_diff_key($gercek, ['id' => true]),
            array_diff_key($bot, ['id' => true]),
        );
        $this->assertTrue(Str::isUlid($bot['id']));

        // T14: botun "kimligi" hicbir yere yazilmadi.
        $this->assertDatabaseCount('rsvps', 1);
        $this->assertDatabaseMissing('rsvps', ['id' => $bot['id']]);
    }

    /** L1: en ucuz katman en onde — bot taslak davetiyeye bile ulasmaz, tek sorgu acilmaz. */
    #[Test]
    public function the_honeypot_is_the_first_layer_and_costs_no_query(): void
    {
        $taslak = Invitation::factory()->for($this->gulsah())->create(['show_rsvp' => true]);

        DB::enableQueryLog();

        $this->lcvGonder($taslak, $this->botFormu($this->lcvFormu()))->assertCreated();

        $this->assertSame([], DB::getQueryLog());
        $this->assertDatabaseCount('rsvps', 0);
    }

    /** Bos ya da bosluklu tuzak alani durust tarayicidir (TrimStrings + ConvertEmptyStringsToNull). */
    #[Test]
    public function an_empty_or_blank_honeypot_is_not_a_trap(): void
    {
        $dugun = $this->dugunDavetiyesi();

        $this->lcvGonder($dugun, $this->lcvFormu([StoreRsvpRequest::HONEYPOT_FIELD => '']))->assertCreated();
        $this->lcvGonder($dugun, $this->lcvFormu([
            'guestName' => 'Mehmet Ali Öztürk',
            StoreRsvpRequest::HONEYPOT_FIELD => '   ',
        ]))->assertCreated();

        $this->assertDatabaseCount('rsvps', 2);
    }

    /** Frontend'in gorunmez input'u BU adla cizilir; sabit yeniden adlandirilirsa tuzak sessizce olur. */
    #[Test]
    public function the_honeypot_field_name_is_part_of_the_frontend_contract(): void
    {
        $this->assertSame('website', StoreRsvpRequest::HONEYPOT_FIELD);
    }

    // ------------------------------------------------ SON TARIH (SAAT DILIMI)

    /** @return iterable<string, array{string, string, string}> */
    public static function sonGunIcindekiAnlar(): iterable
    {
        yield 'Istanbul son gun 23:59:59' => ['Europe/Istanbul', '2026-10-10', '2026-10-10 20:59:59'];
        yield 'Istanbul artik yil 29 Subat 23:59' => ['Europe/Istanbul', '2028-02-29', '2028-02-29 20:59:00'];
        yield 'Istanbul yilbasi 23:59:59' => ['Europe/Istanbul', '2026-12-31', '2026-12-31 20:59:59'];
        yield 'Berlin yaz saati son gun 23:59:59' => ['Europe/Berlin', '2026-10-24', '2026-10-24 21:59:59'];
    }

    /** Son gun DAHILDIR ve gun, DAVETIYENIN duvar saatinde biter (K63/K71). */
    #[Test]
    #[DataProvider('sonGunIcindekiAnlar')]
    public function a_reply_on_the_last_local_day_is_accepted(string $dilim, string $sonTarih, string $utcAn): void
    {
        $dugun = $this->dugunDavetiyesi($this->sonTarihliDugun($dilim, $sonTarih));
        $this->travelTo(CarbonImmutable::parse($utcAn, 'UTC'));

        $this->lcvGonder($dugun, $this->lcvFormu())->assertCreated();

        $this->assertDatabaseCount('rsvps', 1);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function sonGunuGecenAnlar(): iterable
    {
        yield 'Istanbul gece yarisi (UTC hala son gun)' => ['Europe/Istanbul', '2026-10-10', '2026-10-10 21:00:00'];
        yield 'Istanbul artik yil 1 Mart 00:00' => ['Europe/Istanbul', '2028-02-29', '2028-02-29 21:00:00'];
        yield 'Istanbul yeni yil 00:00' => ['Europe/Istanbul', '2026-12-31', '2026-12-31 21:00:00'];
        yield 'Berlin gece yarisi (yaz saati bitmeden)' => ['Europe/Berlin', '2026-10-24', '2026-10-24 22:00:00'];
    }

    #[Test]
    #[DataProvider('sonGunuGecenAnlar')]
    public function a_reply_after_local_midnight_is_refused_and_not_written(string $dilim, string $sonTarih, string $utcAn): void
    {
        $dugun = $this->dugunDavetiyesi($this->sonTarihliDugun($dilim, $sonTarih));
        $this->travelTo(CarbonImmutable::parse($utcAn, 'UTC'));

        $this->lcvGonder($dugun, $this->lcvFormu())
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpDeadlinePassed->value);

        $this->assertDatabaseCount('rsvps', 0);
    }

    /** Dilimi bos davetiye config varsayilanini kullanir — sunucunun UTC'sini degil. */
    #[Test]
    public function a_missing_timezone_falls_back_to_the_configured_default(): void
    {
        Config::set('davetkart.default_timezone', 'Europe/Istanbul');
        $dugun = $this->dugunDavetiyesi(['timezone' => null, 'rsvp_deadline' => '2026-10-10']);

        $this->travelTo(CarbonImmutable::parse('2026-10-10 20:59:59', 'UTC'));
        $this->lcvGonder($dugun, $this->lcvFormu())->assertCreated();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 21:00:00', 'UTC'));
        $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => 'Zeynep Kılıç']))
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpDeadlinePassed->value);

        $this->assertDatabaseCount('rsvps', 1);
    }

    /** N4: son tarih null ise sinir yok — "bugun" demek degil. */
    #[Test]
    public function without_a_deadline_replies_stay_open(): void
    {
        $dugun = $this->dugunDavetiyesi(['rsvp_deadline' => null]);
        $this->travelTo(CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC'));

        $this->lcvGonder($dugun, $this->lcvFormu())->assertCreated();

        $this->assertDatabaseCount('rsvps', 1);
    }

    // ----------------------------------------------------------------- KOTA

    /** Fiyat sayfasindaki ticari soz (PHP-LARAVEL-SETUP §1): Standart en fazla 100 kisi. */
    #[Test]
    public function the_plans_promise_the_published_rsvp_limits(): void
    {
        $this->assertSame(100, SubscriptionTier::Standart->rsvpLimit());
        $this->assertNull(SubscriptionTier::Gold->rsvpLimit());
        $this->assertNull(SubscriptionTier::Elit->rsvpLimit());
    }

    /** 97 + 3 = 100 kabul (">" yerine ">=" yazan mutant burada olur), 100 + 1 red. */
    #[Test]
    public function a_standart_plan_accepts_guests_up_to_the_exact_limit_and_not_one_more(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $this->mevcutMisafirler($dugun, 97);

        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 3]))->assertCreated();

        $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => 'Zeynep Kılıç', 'guestCount' => 1]))
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpQuotaExceeded->value);

        $this->assertSame(100, $this->toplamMisafir($dugun));
        $this->assertDatabaseCount('rsvps', 11);
    }

    /** 25 aile x 4 kisi = 100 misafir ama 25 SATIR; COUNT(*) olsaydi yer var sanilirdi. */
    #[Test]
    public function the_quota_counts_guests_not_rows(): void
    {
        $dugun = $this->dugunDavetiyesi();
        Rsvp::factory()->for($dugun)->count(25)->guests(4)->create(['guest_name' => 'Yılmaz Ailesi']);

        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 1]))
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpQuotaExceeded->value);

        $this->assertDatabaseCount('rsvps', 25);
    }

    /** K50: "katilamiyoruz" diyen 100 kisi tek sandalye doldurmaz. */
    #[Test]
    public function declined_guests_do_not_take_seats(): void
    {
        $dugun = $this->dugunDavetiyesi();
        Rsvp::factory()->for($dugun)->declined()->count(10)->guests(10)->create(['guest_name' => 'Kaya Ailesi']);

        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 10]))->assertCreated();

        $this->assertDatabaseCount('rsvps', 11);
    }

    /** K50: kararsiz misafir yer tutar — temkinli taraf. */
    #[Test]
    public function undecided_guests_take_seats(): void
    {
        $dugun = $this->dugunDavetiyesi();
        Rsvp::factory()->for($dugun)->pending()->count(10)->guests(10)->create(['guest_name' => 'Demir Ailesi']);

        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 1]))
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpQuotaExceeded->value);

        $this->assertDatabaseCount('rsvps', 10);
    }

    #[Test]
    public function a_gold_plan_has_no_rsvp_quota(): void
    {
        $dugun = $this->dugunDavetiyesi(plan: SubscriptionTier::Gold);
        $this->mevcutMisafirler($dugun, 250);

        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 10]))->assertCreated();

        $this->assertSame(260, $this->toplamMisafir($dugun));
    }

    /** Iade edilen Gold'un hakki duser; bilinmeyende DAR taraf (Standart, 100) gecerli. */
    #[Test]
    public function a_refunded_plan_falls_back_to_the_narrowest_quota(): void
    {
        $dugun = $this->yayindakiDavetiye();
        $this->planSat($dugun, SubscriptionTier::Gold, iadeEdildi: true);
        $this->mevcutMisafirler($dugun, 100);

        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 1]))
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::RsvpQuotaExceeded->value);

        $this->assertSame(100, $this->toplamMisafir($dugun));
    }

    /** H9: anonim misafir kalan yeri ya da limiti OGRENMEZ — govde tam olarak bu. */
    #[Test]
    public function a_quota_rejection_reveals_no_counters(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $this->mevcutMisafirler($dugun, 100);

        $yanit = $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 1]))->assertForbidden();

        $this->assertSame('{"error":{"code":"RSVP_QUOTA_EXCEEDED"}}', $yanit->getContent());
    }

    /** Sahibin sildigi spam kayit sandalyelerini geri verir. */
    #[Test]
    public function removing_a_spam_reply_frees_its_seats(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $this->mevcutMisafirler($dugun, 96);
        $spam = Rsvp::factory()->for($dugun)->guests(4)->create(['guest_name' => 'asdasd qweqwe']);

        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 4]))->assertForbidden();

        $this->withToken($this->token($this->gulsah()))
            ->deleteJson(route('rsvps.destroy', $spam))
            ->assertNoContent();

        $this->flushHeaders();
        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 4]))->assertCreated();

        $this->assertSame(100, $this->toplamMisafir($dugun));
    }

    // ------------------------------------------------------------ HIZ SINIRI

    /** Ayni telefondan dakikada N gonderim; kova kalici bir yasak degil, bir dakika sonra bosalir. */
    #[Test]
    public function one_device_is_slowed_down_per_minute_and_recovers_afterwards(): void
    {
        Config::set('davetkart.rsvp.rate_limit.per_ip_per_minute', 3);
        $dugun = $this->dugunDavetiyesi();
        $ip = '88.241.10.7';

        foreach (['Şeyma Şen', 'Oğuz Şen', 'Defne Şen'] as $isim) {
            $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => $isim, 'guestCount' => 1]), $ip)
                ->assertCreated();
        }

        $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => 'Ayşe Şen', 'guestCount' => 1]), $ip)
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value)
            ->assertJsonPath('error.params.retryAfter', 60);
        $this->assertDatabaseCount('rsvps', 3);

        $this->travel(61)->seconds();

        $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => 'Ayşe Şen', 'guestCount' => 1]), $ip)
            ->assertCreated();
        $this->assertDatabaseCount('rsvps', 4);
    }

    /**
     * 🔴 KOD HATASI (kilavuz §4.4): docs/08 §4.1 "Retry-After basligi 429 ile gonderilir" diyor.
     * Bugun yalnizca govdede `params.retryAfter` var; ApiExceptionRenderer basliklari dusuruyor.
     */
    #[Test]
    public function a_rate_limited_reply_carries_the_retry_after_header(): void
    {
        Config::set('davetkart.rsvp.rate_limit.per_ip_per_minute', 1);
        $dugun = $this->dugunDavetiyesi();

        $this->lcvGonder($dugun, $this->lcvFormu(['guestCount' => 1]))->assertCreated();

        $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => 'Oğuz Şen', 'guestCount' => 1]))
            ->assertStatus(429)
            ->assertHeader('Retry-After', '60');
    }

    /** Botnet: her istek FARKLI IP'den gelse de davetiyenin saatlik kovasi dolar; komsu davetiye etkilenmez. */
    #[Test]
    public function one_invitation_is_protected_against_many_ips_without_affecting_others(): void
    {
        Config::set('davetkart.rsvp.rate_limit.per_invitation_per_hour', 3);
        $dugun = $this->dugunDavetiyesi();
        $kina = $this->dugunDavetiyesi(['title' => "Gülşah'ın Kına Gecesi 🪔"]);

        foreach (['78.180.45.12' => 'Şeyma Şen', '88.241.10.7' => 'Oğuz Ertürk', '176.33.12.90' => 'Zeynep Kılıç'] as $ip => $isim) {
            $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => $isim, 'guestCount' => 1]), $ip)
                ->assertCreated();
        }

        $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => 'Burak Aydın', 'guestCount' => 1]), '31.223.4.150')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);
        $this->assertDatabaseCount('rsvps', 3);

        // T7: kova davetiyeye ozel.
        $this->lcvGonder($kina, $this->lcvFormu(['guestName' => 'Burak Aydın', 'guestCount' => 1]), '31.223.4.150')
            ->assertCreated();
        $this->assertDatabaseHas('rsvps', ['invitation_id' => $kina->id, 'guest_name' => 'Burak Aydın']);
    }

    // ------------------------------------------------------ SAHIBIN LISTESI

    #[Test]
    public function a_visitor_without_a_token_cannot_list_replies(): void
    {
        $dugun = $this->dugunDavetiyesi();

        $this->getJson(route('invitations.rsvps.index', $dugun))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    /** P3'un ikinci katmani: Gate dogru olsa bile sorgu KAPSAMI baska davetiyeyi disarida tutar. */
    #[Test]
    public function the_owner_sees_only_this_invitations_replies_newest_first(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $kina = $this->dugunDavetiyesi(['title' => "Gülşah'ın Kına Gecesi 🪔"]);
        $yabanci = $this->dugunDavetiyesi(sahip: $this->kullanici('Mehmet Ali', 'Kaya', 'mehmetali.kaya@hotmail.com'));

        $ilk = Rsvp::factory()->for($dugun)->create(['guest_name' => 'Şeyma Şen', 'created_at' => '2026-09-18 07:15:00']);
        $son = Rsvp::factory()->for($dugun)->create(['guest_name' => 'İsmail Çankaya', 'created_at' => '2026-09-19 19:40:00']);
        $orta = Rsvp::factory()->for($dugun)->create(['guest_name' => 'Zeynep Kılıç', 'created_at' => '2026-09-19 05:05:00']);
        Rsvp::factory()->for($kina)->create(['guest_name' => 'Ayten Yılmaz']);
        Rsvp::factory()->for($yabanci)->create(['guest_name' => 'Burak Aydın']);

        $yanit = $this->withToken($this->token($this->gulsah()))
            ->getJson(route('invitations.rsvps.index', $dugun))
            ->assertOk();

        $this->assertSame([$son->id, $orta->id, $ilk->id], $yanit->json('data.*.id'));
        $this->assertSame(['İsmail Çankaya', 'Zeynep Kılıç', 'Şeyma Şen'], $yanit->json('data.*.guestName'));
    }

    /** H7 + T11: baskasinin davetiyesi ile olmayan davetiye ayni 404, ayni govde; mesaj sizmaz. */
    #[Test]
    public function another_account_gets_the_same_404_as_a_missing_invitation(): void
    {
        $dugun = $this->dugunDavetiyesi();
        Rsvp::factory()->for($dugun)->create([
            'guest_name' => 'Zeynep Kılıç',
            'message' => 'Kapıda beni arayın: 0532 999 88 77',
        ]);
        $token = $this->token($this->kullanici('Mehmet Ali', 'Kaya', 'mehmetali.kaya@hotmail.com'));

        $baskasinin = $this->withToken($token)
            ->getJson(route('invitations.rsvps.index', $dugun))
            ->assertNotFound();

        $this->forgetAuthState();

        $olmayan = $this->withToken($token)
            ->getJson(route('invitations.rsvps.index', self::YOK_OLAN_ULID))
            ->assertNotFound();

        $this->assertSame(self::BULUNAMADI_GOVDESI, $baskasinin->getContent());
        $this->assertSame($olmayan->getContent(), $baskasinin->getContent());
    }

    /** C1: listede yalnizca beyaz listedeki alanlar; ip_hash'in DEGERI hicbir anahtar altinda gecmez. */
    #[Test]
    public function each_listed_reply_carries_only_whitelisted_fields(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $rsvp = Rsvp::factory()->for($dugun)->create([
            'guest_name' => 'Şeyma Şen',
            'menu_preference' => 'Vegan',
            'message' => 'Mutluluklar 💍',
        ]);

        $yanit = $this->withToken($this->token($this->gulsah()))
            ->getJson(route('invitations.rsvps.index', $dugun))
            ->assertOk();

        $this->assertEqualsCanonicalizing(self::YANIT_ANAHTARLARI_MESAJLI, array_keys((array) $yanit->json('data.0')));
        $this->assertStringNotContainsString($rsvp->ip_hash, (string) $yanit->getContent());
    }

    /** K46: degismeyen liste 304; yeni bir LCV gelince AYNI ETag artik 200 + guncel liste (T6). */
    #[Test]
    public function polling_gets_304_until_a_new_reply_arrives(): void
    {
        $dugun = $this->dugunDavetiyesi();
        Rsvp::factory()->for($dugun)->create(['guest_name' => 'Şeyma Şen']);
        $token = $this->token($this->gulsah());
        $url = route('invitations.rsvps.index', $dugun);

        $etag = (string) $this->withToken($token)->getJson($url)->assertOk()->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);

        $this->forgetAuthState();
        $bos = $this->withToken($token)->withHeader('If-None-Match', $etag)->getJson($url)->assertStatus(304);
        $this->assertSame('', $bos->getContent());

        $this->flushHeaders();
        $this->lcvGonder($dugun, $this->lcvFormu(['guestName' => 'İsmail Çankaya']))->assertCreated();

        $this->forgetAuthState();
        $guncel = $this->withToken($token)->withHeader('If-None-Match', $etag)->getJson($url)->assertOk();

        $this->assertCount(2, (array) $guncel->json('data'));
        $this->assertNotSame($etag, $guncel->headers->get('ETag'));
    }

    // ---------------------------------------------------------------- SILME

    /** Tek kaydi siler; ayni davetiyenin diger cevaplarina dokunmaz. */
    #[Test]
    public function the_owner_deletes_exactly_one_reply(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $spam = Rsvp::factory()->for($dugun)->create(['guest_name' => 'asdasd qweqwe', 'message' => 'ucuz takipçi için tıkla']);
        $gercekler = Rsvp::factory()->for($dugun)->count(3)->sequence(
            ['guest_name' => 'Şeyma Şen'],
            ['guest_name' => 'Oğuz Ertürk'],
            ['guest_name' => 'Ayşe Nur Doğan'],
        )->create();

        $this->withToken($this->token($this->gulsah()))
            ->deleteJson(route('rsvps.destroy', $spam))
            ->assertNoContent();

        $this->assertDatabaseMissing('rsvps', ['id' => $spam->id]);
        $this->assertDatabaseCount('rsvps', 3);
        foreach ($gercekler as $rsvp) {
            $this->assertDatabaseHas('rsvps', ['id' => $rsvp->id]);
        }
    }

    /** T14: 404 silinmedigini kanitlamaz — satir alan alan yerinde durmali. */
    #[Test]
    public function another_account_cannot_delete_a_reply(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $rsvp = Rsvp::factory()->for($dugun)->create(['guest_name' => 'Zeynep Kılıç', 'guest_count' => 2]);
        $token = $this->token($this->kullanici('Mehmet Ali', 'Kaya', 'mehmetali.kaya@hotmail.com'));

        $yanit = $this->withToken($token)->deleteJson(route('rsvps.destroy', $rsvp))->assertNotFound();

        $this->assertSame(self::BULUNAMADI_GOVDESI, $yanit->getContent());
        $this->assertDatabaseHas('rsvps', [
            'id' => $rsvp->id,
            'invitation_id' => $dugun->id,
            'guest_name' => 'Zeynep Kılıç',
            'guest_count' => 2,
        ]);
    }

    #[Test]
    public function a_visitor_without_a_token_cannot_delete_a_reply(): void
    {
        $rsvp = Rsvp::factory()->for($this->dugunDavetiyesi())->create(['guest_name' => 'Oğuz Ertürk']);

        $this->deleteJson(route('rsvps.destroy', $rsvp))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);

        $this->assertDatabaseHas('rsvps', ['id' => $rsvp->id]);
    }

    /** Faz 6 regresyonu: silinmis davetiyede iliski null -> TypeError -> 500 olmamali. */
    #[Test]
    public function a_reply_of_a_deleted_invitation_answers_404_not_500(): void
    {
        $dugun = $this->dugunDavetiyesi();
        $rsvp = Rsvp::factory()->for($dugun)->create(['guest_name' => 'Ayşe Nur Doğan']);
        $dugun->delete();

        $this->withToken($this->token($this->gulsah()))
            ->deleteJson(route('rsvps.destroy', $rsvp))
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        $this->assertDatabaseHas('rsvps', ['id' => $rsvp->id]);
    }

    #[Test]
    public function deleting_a_missing_reply_returns_404(): void
    {
        $this->withToken($this->token($this->gulsah()))
            ->deleteJson(route('rsvps.destroy', self::YOK_OLAN_ULID))
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);
    }

    /** O6: bicimsiz LCV kimligi token dogrulamasina bile ulasmaz. */
    #[Test]
    public function a_malformed_reply_id_never_reaches_the_database(): void
    {
        DB::enableQueryLog();

        $this->deleteJson('/api/rsvps/'.rawurlencode("' OR 1=1 --"))->assertNotFound();

        $this->assertSame([], DB::getQueryLog());
    }

    // ----------------------------------------------------------- YARDIMCILAR

    /** Davetiye sahibi; ayni testte tekrar istenirse AYNI kisi (dugun + kina ayni ciftin). */
    private function gulsah(): User
    {
        return $this->gulsah ??= $this->kullanici('Gülşah', 'Yağız-Öztürk', 'gulsah.yagizozturk@gmail.com');
    }

    private function kullanici(string $ad, string $soyad, string $eposta): User
    {
        return User::factory()->create([
            'first_name' => $ad,
            'last_name' => $soyad,
            'email' => $eposta,
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('davetkart-spa')->plainTextToken;
    }

    /**
     * Yayinda, LCV'si acik ve plani ODENMIS dugun — gercekte yayindaki her davetiye boyledir.
     *
     * @param  array<model-property<Invitation>, mixed>  $overrides
     */
    private function dugunDavetiyesi(
        array $overrides = [],
        SubscriptionTier $plan = SubscriptionTier::Standart,
        ?User $sahip = null,
    ): Invitation {
        $davetiye = $this->yayindakiDavetiye($overrides, $sahip);
        $this->planSat($davetiye, $plan);

        return $davetiye;
    }

    /**
     * Siparissiz yayindaki davetiye — yalnizca iade gibi "hak dustu" senaryolari icin.
     *
     * @param  array<model-property<Invitation>, mixed>  $overrides
     */
    private function yayindakiDavetiye(array $overrides = [], ?User $sahip = null): Invitation
    {
        return Invitation::factory()->published()->for($sahip ?? $this->gulsah())->create([
            'title' => 'Gülşah & Emre Evleniyor 💍',
            'names' => 'Gülşah & Emre',
            'venue' => 'Çırağan Sarayı Kempinski, Beşiktaş/İstanbul',
            'map_url' => 'https://www.google.com/maps/search/?api=1&query=41.0438,29.0156',
            'event_at' => '2026-10-17 19:30:00',
            'timezone' => 'Europe/Istanbul',
            'show_rsvp' => true,
            'ask_menu_preference' => true,
            'rsvp_deadline' => '2026-10-10',
            ...$overrides,
        ]);
    }

    private function planSat(Invitation $davetiye, SubscriptionTier $plan, bool $iadeEdildi = false): void
    {
        $siparis = Order::factory()->tier($plan)->forInvitation($davetiye);

        ($iadeEdildi ? $siparis->refunded() : $siparis->paid())->create();
    }

    /**
     * Son tarihten bir hafta sonra, aksam 19:30'da baslayan dugun.
     *
     * @return array<model-property<Invitation>, string>
     */
    private function sonTarihliDugun(string $dilim, string $sonTarih): array
    {
        return [
            'timezone' => $dilim,
            'rsvp_deadline' => $sonTarih,
            'event_at' => CarbonImmutable::parse($sonTarih)->addWeek()->setTime(19, 30)->format('Y-m-d H:i:s'),
        ];
    }

    /** Toplam $misafir kisilik "katiliyorum" cevabi; en fazla 10'arli aileler (config ust siniri). */
    private function mevcutMisafirler(Invitation $davetiye, int $misafir): void
    {
        $kalan = $misafir;

        while ($kalan > 0) {
            $kisi = min(10, $kalan);
            Rsvp::factory()->for($davetiye)->guests($kisi)->create(['guest_name' => fake('tr_TR')->name()]);
            $kalan -= $kisi;
        }
    }

    private function toplamMisafir(Invitation $davetiye): int
    {
        return (int) $davetiye->rsvps()
            ->whereIn('status', RsvpStatus::quotaConsumingValues())
            ->sum('guest_count');
    }

    /**
     * Gercek bir misafirin formu: Turkce karakter, cok satirli mesaj, emoji, tirnak, kesme isareti.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function lcvFormu(array $overrides = []): array
    {
        return [
            'guestName' => 'Şeyma Şen',
            'guestCount' => 3,
            'status' => RsvpStatus::Attending->value,
            'menuPreference' => 'Vejetaryen (1 kişi), çocuk menüsü (1 kişi)',
            'message' => "Canım Gülşah'ım, bir ömür boyu mutluluklar! 💍\n"
                ."\"Evet\" dediğiniz günü dört gözle bekliyoruz 👰🤵\n"
                .'— Şeyma, Oğuz ve minik Defne',
            ...$overrides,
        ];
    }

    /**
     * Gorunmez alani doldurulmus form — bir botun gonderecegi sey.
     *
     * @param  array<string, mixed>  $form
     *
     * @return array<string, mixed>
     */
    private function botFormu(array $form): array
    {
        return [...$form, StoreRsvpRequest::HONEYPOT_FIELD => 'https://ucuz-takipci-satin-al.example/tr'];
    }

    /**
     * @param  array<string, mixed>  $form
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function lcvGonder(Invitation|string $davetiye, array $form, string $ip = self::MISAFIR_IP): TestResponse
    {
        $kimlik = $davetiye instanceof Invitation ? $davetiye->id : $davetiye;

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('public.invitations.rsvps.store', $kimlik), $form);
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $yanit
     *
     * @return array<string, mixed>
     */
    private function veri(TestResponse $yanit): array
    {
        /** @var array<string, mixed> $data */
        $data = $yanit->json('data');

        return $data;
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $yanit
     *
     * @return array<string, mixed>
     */
    private function hataAlanlari(TestResponse $yanit): array
    {
        /** @var array<string, mixed> $alanlar */
        $alanlar = $yanit->json('error.fields');

        return $alanlar;
    }

    /** IpHasher'i CAGIRMADAN formulu yazar — IpHasher bozulursa bu test de gorsun. */
    private function beklenenIpHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, Config::string('app.key'));
    }
}
