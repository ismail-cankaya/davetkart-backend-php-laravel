<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Media;
use App\Models\Order;
use App\Models\Rsvp;
use App\Models\User;
use Database\Factories\UserFactory;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 9'un BAKIM isleri: zamanlanmis komutlar.
 *
 * 🔴 Bu dosyanin testleri de yanita degil ETKIYE bakar (T14): bir komutun
 * "basarili" cikis kodu dondurmesi hicbir sey kanitlamaz — kanit, hangi
 * satirlarin degistigi ve daha da onemlisi hangilerinin DEGISMEDIGIDIR.
 *
 * Her komut icin ayni dortlu sorulur:
 *   1. Hedefi vuruyor mu?
 *   2. Hedef OLMAYANI birakiyor mu?   <- asil koruma burada
 *   3. Sinirda ne oluyor?
 *   4. --dry-run gercekten yazmiyor mu?
 * Ayrintili aciklama: docs/rehber/tests/Feature/MaintenanceTest.md
 */
final class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    /** Faz 9'a kadar `İsmail.Cankaya@…` kaydinin satira yazilan hali: i + U+0307. */
    private const LEGACY_DOTTED_I = "i\u{0307}smail.cankaya@gmail.com";

    private const CANONICAL = 'ismail.cankaya@gmail.com';

    /**
     * 🔴 Storage::fake() GERCEK diski hic gormez (Faz 6'nin storage:link dersi).
     * Burada yeterli: sinanan sey dosyanin SILINDIGI, nerede durdugu degil.
     * Gercek diskteki davranis yalnizca elle dogrulamayla gorulur.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Config::string('davetkart.media.disk'));
    }

    /**
     * Bir artisan komutunu calistirir ve basarili bittigini dogrular.
     *
     * 🔴 Neden `$this->artisan(...)->assertSuccessful()` degil?
     *
     * O yardimcinin donus tipi `PendingCommand|int`:
     *
     *     public function artisan($command, $parameters = [])
     *     {
     *         if (! $this->mockConsoleOutput) {
     *             return $this->app[Kernel::class]->call($command, $parameters);  // int
     *         }
     *         return new PendingCommand($this, $this->app, $command, $parameters);
     *     }
     *
     * Yani `withoutMockingConsoleOutput()` cagrilirsa ham bir `int` doner ve
     * uzerinde `assertSuccessful()` CAGRILAMAZ. Calisma aninda biz onu hic
     * cagirmiyoruz, dolayisiyla her zaman PendingCommand geliyor — ama PHPStan
     * level 8 bunu BILEMEZ ve bilmemekte hakli: bu bir SINIF DURUMUNA bagli
     * dallanma ve gelecekteki bir setUp() onu degistirebilir.
     *
     * `Artisan::call()` ise kesin olarak `int` doner. Tipi daraltmak icin
     * assertInstanceOf yazip PHPStan'in daraltmasina guvenmek yerine, BASTAN
     * tek tipli bir API secildi.
     *
     * Ders 18'in ailesi: bir aracin hata mesaji BELIRTIYI soyler
     * ("assertSuccessful cagrilamaz"), SEBEBI degil (donus tipi birlesimdir).
     * Cozum mesaji susturmak degil, birlesimi hic uretmemek.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function runCommand(string $command, array $parameters = []): void
    {
        $this->assertSame(
            Command::SUCCESS,
            Artisan::call($command, $parameters),
            sprintf('Komut basarisiz bir cikis kodu dondurdu: %s', $command),
        );
    }

    // ------------------------------------------------- orders:expire (9.9)

    /**
     * 🔴 Faz 10 (K89): `failed` DEGIL `expired`. Faz 9'daki adi
     * `it_fails_a_pending_order_…` idi ve 10.5'te beklendigi gibi KIRMIZIYA
     * dondu — test yaniti degil etkiyi (kolonu) dogruluyormus.
     * Gec gelen odemenin bu satiri hala acabildigi: PaywallTest (10.7).
     */
    #[Test]
    public function it_expires_a_pending_order_whose_window_has_closed(): void
    {
        $order = Order::factory()->create(['expires_at' => now()->subMinute()]);

        $this->runCommand('orders:expire');

        $order->refresh();

        $this->assertSame(OrderStatus::Expired, $order->status);
        $this->assertNull($order->paid_at);
    }

    #[Test]
    public function it_leaves_a_pending_order_whose_window_is_still_open(): void
    {
        $order = Order::factory()->create(['expires_at' => now()->addMinutes(10)]);

        $this->runCommand('orders:expire');

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    /**
     * 🔴 EN ONEMLI TEST: odenmis bir siparis ASLA suresi dolmus sayilmaz.
     *
     * Webhook gecikirse `expires_at` gecmiste kalmis bir siparis 'paid'
     * olabilir. Komut `status = pending` kosulunu sorgunun KAPSAMINDA tasidigi
     * icin o satira dokunamaz. Kosul bir `if` olsaydi, dongu sirasinda gelen
     * bir webhook yaris kosulu uretirdi.
     */
    #[Test]
    public function it_never_touches_a_paid_order(): void
    {
        $order = Order::factory()->paid()->create(['expires_at' => now()->subDay()]);

        $this->runCommand('orders:expire');

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
    }

    /** N4: "sure sinirsiz" (NULL) ile "sure dolmus" ayni sey degildir. */
    #[Test]
    public function it_never_touches_an_order_without_an_expiry(): void
    {
        $order = Order::factory()->create(['expires_at' => null]);

        $this->runCommand('orders:expire');

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    #[Test]
    public function the_dry_run_option_writes_nothing(): void
    {
        $order = Order::factory()->create(['expires_at' => now()->subDay()]);

        $this->runCommand('orders:expire', ['--dry-run' => true]);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }
    // ------------------------------------------ media:prune-orphans (9.10)

    #[Test]
    public function it_deletes_an_unreferenced_guest_upload_past_the_grace_period(): void
    {
        $media = Media::factory()->rsvpPhoto()->create([
            'created_at' => now()->subDays(2),
        ]);

        $this->runCommand('media:prune-orphans');

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    /** Misafir formu doldururken dosyasi silinmemeli. */
    #[Test]
    public function it_keeps_an_unreferenced_upload_inside_the_grace_period(): void
    {
        $media = Media::factory()->rsvpPhoto()->create([
            'created_at' => now()->subMinutes(5),
        ]);

        $this->runCommand('media:prune-orphans');

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    #[Test]
    public function it_keeps_an_upload_referenced_by_an_rsvp_photo(): void
    {
        $media = Media::factory()->rsvpPhoto()->create(['created_at' => now()->subDays(9)]);

        Rsvp::factory()->create([
            'invitation_id' => $media->invitation_id,
            'photo_media_id' => $media->id,
        ]);

        $this->runCommand('media:prune-orphans');

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    /** Iki FK kolonu var; sorgu ikisini de gormeli (yalnizca photo degil). */
    #[Test]
    public function it_keeps_an_upload_referenced_by_an_rsvp_video(): void
    {
        $media = Media::factory()->rsvpVideo()->create(['created_at' => now()->subDays(9)]);

        Rsvp::factory()->create([
            'invitation_id' => $media->invitation_id,
            'video_media_id' => $media->id,
        ]);

        $this->runCommand('media:prune-orphans');

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    /**
     * 🔴 Galeri medyasi ASLA yetim olamaz.
     *
     * Galeri dosyasi bir LCV yanitina baglanmaz — davetiyenin galerisinin
     * KENDISIDIR. "Hicbir rsvp isaret etmiyor" olcutu ona uygulansaydi komut
     * butun galerileri silerdi. Olcut, turun ne ISE YARADIGINA bagli.
     */
    #[Test]
    public function it_never_touches_gallery_media(): void
    {
        $media = Media::factory()->create(['created_at' => now()->subYear()]);

        $this->runCommand('media:prune-orphans');

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    /** 🔴 Asil is DISKTE: satiri silmek yeri bosaltmaz. */
    #[Test]
    public function it_removes_the_file_from_its_own_disk(): void
    {
        $disk = Config::string('davetkart.media.disk');

        $media = Media::factory()->rsvpPhoto()->create(['created_at' => now()->subDays(2)]);

        Storage::disk($disk)->put($media->path, 'sahte-icerik');
        Storage::disk($disk)->assertExists($media->path);

        $this->runCommand('media:prune-orphans');

        Storage::disk($disk)->assertMissing($media->path);
    }

    #[Test]
    public function the_prune_dry_run_deletes_nothing(): void
    {
        $media = Media::factory()->rsvpPhoto()->create(['created_at' => now()->subDays(2)]);

        $this->runCommand('media:prune-orphans', ['--dry-run' => true]);

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    // --------------------------------------- sanctum:prune-expired (10.10)

    /**
     * 🔴 Faz 9'dan 10.10'a kadar bu komut zamanlanmisti, `schedule:list`'te
     * gorunuyordu, hata vermiyordu — ve HICBIR SEY silmiyordu (B4):
     * `sanctum.expiration` null oldugu icin asil sorgusu hic kosmuyordu.
     * Kayit testi (asagida) bunu yakalayamazdi; yalnizca SILINEN SATIRI
     * sayan bir test yakalar.
     *
     * Komut elle yazilan argumanlarla degil, ZAMANLAYICIDAKI satirla aynen
     * calistiriliyor (`scheduledArtisanCommand`). Yarin biri `--hours`'i
     * degistirirse test onu da gorur.
     *
     * Sinir: `expiration` (30 gun) + `--hours=24` = `created_at`'i 31 gunden
     * eski token silinir. Dortlu soru (dosya basi):
     *   1. hedef    : 31 gun + 1 dakika -> silinir
     *   2. hedef degil: 31 gun - 1 dakika (suresi dolmus ama bekleme gununde)
     *                  ve 1 gunluk (canli) token -> kalir
     *   3. sinir    : iki yanda birer dakika
     *   4. dry-run  : komutun boyle bir secenegi yok
     */
    #[Test]
    public function the_scheduled_token_prune_deletes_tokens_a_day_past_their_lifetime(): void
    {
        $user = User::factory()->create();

        $stale = $this->tokenIssuedAt($user, now()->subDays(31)->subMinute());
        $inGraceDay = $this->tokenIssuedAt($user, now()->subDays(31)->addMinute());
        $alive = $this->tokenIssuedAt($user, now()->subDay());

        $this->runCommand($this->scheduledArtisanCommand('sanctum:prune-expired'));

        $this->assertModelMissing($stale);
        $this->assertModelExists($inGraceDay);
        $this->assertModelExists($alive);
    }

    // -------------------------------------- users:normalize-emails (10.14)

    /**
     * 🔴 Asil amac: Faz 9'da `İ` ile kaydolmus kullanici yeniden girebilsin.
     * Kanit kolonda DEGIL yalnizca — giris ucunda: satir duzeldi ama giris
     * hala 401 donseydi komut isini yapmamis olurdu.
     */
    #[Test]
    public function it_folds_a_legacy_dotted_i_email_so_the_user_can_log_in_again(): void
    {
        $user = $this->userWithStoredEmail(self::LEGACY_DOTTED_I);

        $this->postJson(route('auth.login'), [
            'email' => self::CANONICAL,
            'password' => UserFactory::PASSWORD,
        ])->assertUnauthorized();

        $this->runCommand('users:normalize-emails');

        $this->assertSame(bin2hex(self::CANONICAL), bin2hex($user->refresh()->email));

        $this->postJson(route('auth.login'), [
            'email' => self::CANONICAL,
            'password' => UserFactory::PASSWORD,
        ])->assertOk();
    }

    /** Hedef olmayan satir: dokunulmaz, `updated_at` bile degismez. */
    #[Test]
    public function it_leaves_an_already_canonical_email_untouched(): void
    {
        $user = User::factory()->create(['email' => self::CANONICAL]);
        $before = $user->refresh()->updated_at;

        $this->travel(1)->minute();
        $this->runCommand('users:normalize-emails');

        $user->refresh();
        $this->assertSame(self::CANONICAL, $user->email);
        $this->assertEquals($before, $user->updated_at);
    }

    /**
     * 🔴 K-3'un 3. adimi Faz 9'da ikinci bir hesap aciyordu. O iki hesap
     * varsa hangisinin kalacagi bir KOD karari degil: komut yazmaz,
     * raporlar ve basarisiz biter. Ayni kosudaki cakismayan satir yine de
     * duzeltilir (bir cakisma digerlerini rehin almaz).
     */
    #[Test]
    public function it_skips_and_reports_an_address_another_account_already_holds(): void
    {
        $existing = User::factory()->create(['email' => self::CANONICAL]);
        $legacy = $this->userWithStoredEmail(self::LEGACY_DOTTED_I);
        $unrelated = $this->userWithStoredEmail("\u{0130}pek@ornek.test");

        $this->assertSame(Command::FAILURE, Artisan::call('users:normalize-emails'));

        $this->assertSame(self::CANONICAL, $existing->refresh()->email);
        $this->assertSame(self::LEGACY_DOTTED_I, $legacy->refresh()->email);
        $this->assertSame('ipek@ornek.test', $unrelated->refresh()->email);

        $this->assertStringContainsString(sprintf('#%d', $existing->id), Artisan::output());
    }

    /** Iki bozuk satir ayni adrese dusuyor: ikisi de yazilmaz. */
    #[Test]
    public function it_skips_two_legacy_rows_that_fold_into_the_same_address(): void
    {
        $dotted = $this->userWithStoredEmail(self::LEGACY_DOTTED_I);
        $capital = $this->userWithStoredEmail("\u{0130}smail.cankaya@gmail.com");

        $this->assertSame(Command::FAILURE, Artisan::call('users:normalize-emails'));

        $this->assertSame(self::LEGACY_DOTTED_I, $dotted->refresh()->email);
        $this->assertSame("\u{0130}smail.cankaya@gmail.com", $capital->refresh()->email);
    }

    /**
     * Dry-run yazmaz ve gorunmezi GORUNUR kilar: "i̇" ile "i" terminalde
     * ayni gorunur, rapor onu `\u0307` kacisiyla basar.
     */
    #[Test]
    public function the_normalize_dry_run_writes_nothing_and_shows_the_hidden_dot(): void
    {
        $user = $this->userWithStoredEmail(self::LEGACY_DOTTED_I);

        $this->runCommand('users:normalize-emails', ['--dry-run' => true]);

        $this->assertSame(self::LEGACY_DOTTED_I, $user->refresh()->email);
        $this->assertStringContainsString('i\u0307smail.cankaya@gmail.com', Artisan::output());
    }

    /**
     * Faz 9'un yazdigi satiri taklit eder: mutator'i ATLAYARAK yazar.
     * Model uzerinden yazilsaydi 10.13'ten beri normalizer onu duzeltirdi
     * ve testin kurmak istedigi bozuk durum hic olusmazdi.
     */
    private function userWithStoredEmail(string $raw): User
    {
        $user = User::factory()->create();

        DB::table('users')->where('id', $user->id)->update(['email' => $raw]);

        return $user->refresh();
    }

    private function tokenIssuedAt(User $user, DateTimeInterface $issuedAt): PersonalAccessToken
    {
        $token = $user->createToken('api')->accessToken;

        // `created_at` $fillable'da degil ve olmamali; zamani yalnizca test yazar.
        $token->forceFill(['created_at' => $issuedAt])->save();

        return $token;
    }

    // ------------------------------------------------------ ZAMANLAYICI (9.11)

    /**
     * 🔴 Bu test bir HATAYI degil bir UNUTMAYI yakalar.
     *
     * Iki komut yazildi ve ikisi de tek basina mukemmel calisiyor — ama
     * zamanlayiciya kaydedilmezlerse HIC kosmazlar ve bunu hicbir sey
     * soylemez: `composer check` yesil, uclar calisiyor, disk sessizce
     * doluyor. Ders 26'nin zaman eksenindeki hali.
     *
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        $commands = [];

        foreach (app(Schedule::class)->events() as $event) {
            $commands[] = (string) $event->command;
        }

        return $commands;
    }

    #[Test]
    public function the_maintenance_commands_are_registered_with_the_scheduler(): void
    {
        $scheduled = implode("\n", $this->scheduledCommands());

        $this->assertStringContainsString('orders:expire', $scheduled);
        $this->assertStringContainsString('media:prune-orphans', $scheduled);
        $this->assertStringContainsString('sanctum:prune-expired', $scheduled);
    }

    /**
     * Siklik da sozlesmedir: gunluk olmasi gereken bir is dakikalik kosarsa
     * veritabanini dover, saatlik olmasi gereken bir is haftalik kosarsa
     * isini yapmaz.
     */
    #[Test]
    public function each_maintenance_command_runs_at_its_intended_cadence(): void
    {
        $expressions = [];

        foreach (app(Schedule::class)->events() as $event) {
            $expressions[(string) $event->command] = $event->expression;
        }

        $this->assertSame('0 * * * *', $this->expressionFor($expressions, 'orders:expire'));
        $this->assertSame('15 3 * * *', $this->expressionFor($expressions, 'media:prune-orphans'));
        $this->assertSame('0 0 * * *', $this->expressionFor($expressions, 'sanctum:prune-expired'));
    }

    /**
     * 🔴 Ust uste kosma korumasi HER bakim isinde olmali.
     *
     * Onceki kosu bitmeden ikincisi baslarsa iki surec ayni satirlari
     * siler/gunceller. `orders:expire` icin zararsiz (idempotent), ama
     * `media:prune-orphans` ayni dosyayi iki kez silmeye calisir ve ikinci
     * surec satiri bulamadan once birincisi dosyayi silmis olabilir.
     * Korumayi isin idempotansina degil, YAPIYA baglariz.
     */
    #[Test]
    public function every_scheduled_command_guards_against_overlapping(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertNotNull(
                $event->mutexName(),
                sprintf('Zamanlanmis is withoutOverlapping() tasimiyor: %s', $event->command),
            );
        }
    }

    /**
     * Zamanlayicidaki satiri, `Artisan::call()`'a verilebilecek hale getirir.
     *
     *   Windows: "C:\...\php.exe" "artisan" sanctum:prune-expired --hours=24
     *   Linux  : '/usr/bin/php' 'artisan' sanctum:prune-expired --hours=24
     *   Sonuc  : sanctum:prune-expired --hours=24
     *
     * Tirnak isletim sistemine gore degisiyor (ProcessUtils::escapeArgument);
     * ifade ikisini de kabul eder.
     */
    private function scheduledArtisanCommand(string $name): string
    {
        foreach ($this->scheduledCommands() as $command) {
            if (str_contains($command, $name)
                && preg_match('/[\'"]artisan[\'"]\s+(.+)$/', $command, $matches) === 1) {
                return $matches[1];
            }
        }

        $this->fail(sprintf('Zamanlayicida bulunamadi: %s', $name));
    }

    /**
     * @param  array<string, string>  $expressions
     */
    private function expressionFor(array $expressions, string $needle): ?string
    {
        foreach ($expressions as $command => $expression) {
            if (str_contains($command, $needle)) {
                return $expression;
            }
        }

        return null;
    }
}
