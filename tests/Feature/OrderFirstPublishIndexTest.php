<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 18.3 (SEC-01, K158): kullanıcı başına en fazla BİR bekleyen ilk yayın
 * indirimli sipariş — değişmezin veritabanı katmanı.
 *
 * Testler satırı doğrudan yazar; kuralı uygulama kodu değil index tutmalı (E2).
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_11_16_100000_add_one_pending_first_publish_index_to_orders.md
 */
final class OrderFirstPublishIndexTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = 'orders_one_pending_first_publish_per_user';

    private const MIGRATION = 'migrations/2026_11_16_100000_add_one_pending_first_publish_index_to_orders.php';

    protected function setUp(): void
    {
        parent::setUp();

        // Fabrikanın firstPublish() durumu oranı üretim koduyla aynı yerden okur.
        Config::set('davetkart.pricing.first_publish_percent', 60);
    }

    #[Test]
    public function a_second_pending_first_publish_order_for_the_same_user_is_rejected_by_the_database(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->firstPublish()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(self::INDEX);

        Order::factory()->for($user)->firstPublish()->create();
    }

    #[Test]
    public function two_users_can_each_hold_one(): void
    {
        Order::factory()->firstPublish()->create();
        Order::factory()->firstPublish()->create();

        $this->assertSame(2, Order::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function rowsThatDoNotHoldTheDiscount(): array
    {
        return [
            'paid first publish' => ['paid'],
            'expired first publish' => ['expired'],
            'failed first publish' => ['failed'],
            'pending at list price' => ['list'],
        ];
    }

    #[Test]
    #[DataProvider('rowsThatDoNotHoldTheDiscount')]
    public function only_pending_first_publish_rows_are_counted(string $first): void
    {
        $user = User::factory()->create();

        match ($first) {
            'paid' => Order::factory()->for($user)->firstPublish()->paid()->create(),
            'expired' => Order::factory()->for($user)->firstPublish()->create(['status' => OrderStatus::Expired]),
            'failed' => Order::factory()->for($user)->firstPublish()->failed()->create(),
            'list' => Order::factory()->for($user)->create(),
            default => $this->fail("Bilinmeyen durum: {$first}"),
        };

        Order::factory()->for($user)->firstPublish()->create();

        $this->assertSame(2, Order::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_row_that_stops_being_pending_frees_the_slot(): void
    {
        $user = User::factory()->create();
        $first = Order::factory()->for($user)->firstPublish()->create();

        DB::table('orders')->where('id', $first->getKey())->update(['status' => OrderStatus::Expired->value]);

        Order::factory()->for($user)->firstPublish()->create();

        $this->assertSame(1, Order::query()->where('status', OrderStatus::Pending->value)->count());
    }

    #[Test]
    public function anonymized_orders_do_not_collide(): void
    {
        // Hesap silinince user_id NULL olur (K97); UNIQUE index NULL'ları ayrı sayar.
        $orders = Order::factory()->count(2)->firstPublish()->create();

        DB::table('orders')->whereIn('id', $orders->modelKeys())->update(['user_id' => null]);

        $this->assertSame(2, Order::query()->whereNull('user_id')->count());
    }

    #[Test]
    public function the_migration_stops_instead_of_changing_conflicting_rows(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();

        $user = User::factory()->create();
        Order::factory()->count(2)->for($user)->firstPublish()->create();

        try {
            $migration->up();
            $this->fail('Çakışan satırlar varken göç index kurmamalıydı.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 kullanicinin', $e->getMessage());
            $this->assertStringContainsString((string) $user->id, $e->getMessage());
        }

        // Veri değişmedi (K162): iki satır da hâlâ bekliyor.
        $this->assertSame(2, Order::query()->where('status', OrderStatus::Pending->value)->count());
    }

    #[Test]
    public function the_migration_can_be_rolled_back_and_run_again(): void
    {
        $migration = require database_path(self::MIGRATION);

        $migration->down();
        $this->assertFalse($this->indexExists());

        $migration->up();
        $this->assertTrue($this->indexExists());
    }

    private function indexExists(): bool
    {
        return DB::table('pg_indexes')
            ->where('tablename', 'orders')
            ->where('indexname', self::INDEX)
            ->exists();
    }
}
