<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** K260 (A-E1): 19.14 öncesi hesaplar doğrulanmış sayılır; doğrulanmış ve sonradan açılan hesaba dokunulmaz. */
final class MarkExistingAccountsVerifiedMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = '2026_11_25_100000_mark_existing_accounts_as_verified';

    #[Test]
    public function accounts_opened_before_the_migration_are_marked_verified_at_their_creation_time(): void
    {
        $old = User::factory()->unverified()->create(['created_at' => '2026-10-01 12:00:00']);
        $verified = User::factory()->create(['email_verified_at' => '2026-11-01 09:00:00']);
        $later = User::factory()->unverified()->create(['created_at' => now()->addMinute()]);

        // RefreshDatabase göçü boş tabloda koştu; kaydını silip gerçek `migrate` yoluyla yeniden koşturuyoruz.
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $command = $this->artisan('migrate', ['--path' => 'database/migrations/'.self::MIGRATION.'.php']);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();

        $this->assertSame('2026-10-01 12:00:00', $this->verifiedAt($old));
        $this->assertSame('2026-11-01 09:00:00', $this->verifiedAt($verified));
        $this->assertNull($this->verifiedAt($later), 'Göçten sonra açılan hesap doğrulama postasını beklemeli.');
    }

    private function verifiedAt(User $user): ?string
    {
        return User::query()->findOrFail($user->id)->email_verified_at?->format('Y-m-d H:i:s');
    }
}
