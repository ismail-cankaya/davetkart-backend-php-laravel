<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Yanmış yayın hakkının KALICI işareti (Faz 14, 14.1 · K137).
 *
 * 🔴 Kapatılan delik: 3 günlük pencere kapandıktan sonra silinen bir davetiye
 * çöp kutusunda 30 gün kalır, sonra `data:purge` onu kalıcı siler. FK
 * `nullOnDelete` olduğu için siparişin `invitation_id`'si NULL olur ve sipariş
 * SERBEST BIRAKILMIŞ bir hakla aynı biçime girer: ClaimReleasedOrderAction onu
 * yeni bir davetiyeye bağlardı. Yanmış hak, 30 gün sonra bedava yayın olurdu.
 *
 * Bilgi bağın kendisinde (invitation_id) duramaz, çünkü bağ kopabilir. Ayrı bir
 * damga gerekir: `consumed_at`.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_26_100000_add_consumed_at_to_orders_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('consumed_at')->nullable();
        });

        // Yalnızca parası alınmış sipariş tüketilebilir (paid_at damgalı).
        DB::statement(
            'ALTER TABLE orders
             ADD CONSTRAINT orders_consumed_at_check CHECK (consumed_at IS NULL OR paid_at IS NOT NULL)',
        );

        // Geriye dönük: çöp kutusundaki bir davetiyeye HÂLÂ bağlı ödenmiş
        // sipariş, ancak pencere kapalıyken silinmiş olabilir (pencere açıkken
        // silme bağı koparırdı). Damga olarak silinme anı kullanılır.
        // ⚠️ Zaten kalıcı silinmiş davetiyelerin siparişleri ayırt EDİLEMEZ.
        DB::statement(
            'UPDATE orders
             SET consumed_at = invitations.deleted_at
             FROM invitations
             WHERE orders.invitation_id = invitations.id
               AND invitations.deleted_at IS NOT NULL
               AND orders.paid_at IS NOT NULL
               AND orders.consumed_at IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_consumed_at_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('consumed_at');
        });
    }
};
