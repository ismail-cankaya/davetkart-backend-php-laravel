<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Media;
use App\Models\Order;
use App\Models\Rsvp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
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

    // ------------------------------------------------- orders:expire (9.9)

    #[Test]
    public function it_fails_a_pending_order_whose_window_has_closed(): void
    {
        $order = Order::factory()->create(['expires_at' => now()->subMinute()]);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame(OrderStatus::Failed, $order->refresh()->status);
    }

    #[Test]
    public function it_leaves_a_pending_order_whose_window_is_still_open(): void
    {
        $order = Order::factory()->create(['expires_at' => now()->addMinutes(10)]);

        $this->artisan('orders:expire')->assertSuccessful();

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

        $this->artisan('orders:expire')->assertSuccessful();

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
    }

    /** N4: "sure sinirsiz" (NULL) ile "sure dolmus" ayni sey degildir. */
    #[Test]
    public function it_never_touches_an_order_without_an_expiry(): void
    {
        $order = Order::factory()->create(['expires_at' => null]);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    #[Test]
    public function the_dry_run_option_writes_nothing(): void
    {
        $order = Order::factory()->create(['expires_at' => now()->subDay()]);

        $this->artisan('orders:expire', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }
    // ------------------------------------------ media:prune-orphans (9.10)

    #[Test]
    public function it_deletes_an_unreferenced_guest_upload_past_the_grace_period(): void
    {
        $media = Media::factory()->rsvpPhoto()->create([
            'created_at' => now()->subDays(2),
        ]);

        $this->artisan('media:prune-orphans')->assertSuccessful();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    /** Misafir formu doldururken dosyasi silinmemeli. */
    #[Test]
    public function it_keeps_an_unreferenced_upload_inside_the_grace_period(): void
    {
        $media = Media::factory()->rsvpPhoto()->create([
            'created_at' => now()->subMinutes(5),
        ]);

        $this->artisan('media:prune-orphans')->assertSuccessful();

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

        $this->artisan('media:prune-orphans')->assertSuccessful();

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

        $this->artisan('media:prune-orphans')->assertSuccessful();

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

        $this->artisan('media:prune-orphans')->assertSuccessful();

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

        $this->artisan('media:prune-orphans')->assertSuccessful();

        Storage::disk($disk)->assertMissing($media->path);
    }

    #[Test]
    public function the_prune_dry_run_deletes_nothing(): void
    {
        $media = Media::factory()->rsvpPhoto()->create(['created_at' => now()->subDays(2)]);

        $this->artisan('media:prune-orphans', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('media', ['id' => $media->id]);
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
