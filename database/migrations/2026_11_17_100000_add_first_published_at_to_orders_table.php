<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bir hakkın bir davetiyeyi YAYINLADIĞININ kalıcı işareti (Faz 18.4, SEC-02 · K164).
 *
 * 🔴 Kapatılan delik: 3 günlük pencerede silinen davetiyenin siparişi serbest
 * kalır (K82) ve "hiç kullanılmamış" hakla aynı görünür; iade edilebiliyordu.
 * "Kullanıldı" bilgisi bağın (invitation_id) yokluğundan okunamaz: damga gerekir
 * ve damga serbest bırakmada SİLİNMEZ (K137'nin dersi, bir adım erkene).
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_11_17_100000_add_first_published_at_to_orders_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // `timestamp`, tz'siz: şemadaki bütün zaman kolonlarıyla aynı (paid_at, consumed_at).
            $table->timestamp('first_published_at')->nullable();
        });

        // Yalnızca parası alınmış hak yayın yapmış olabilir (consumed_at ile aynı kural).
        DB::statement(
            'ALTER TABLE orders
             ADD CONSTRAINT orders_first_published_at_check CHECK (first_published_at IS NULL OR paid_at IS NOT NULL)',
        );

        $this->backfillAttached();
        $this->backfillConsumed();
        $this->backfillReleased();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_first_published_at_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('first_published_at');
        });
    }

    /**
     * 1. Yayınlanmış bir davetiyeye BAĞLI (çöptekiler dahil), parası alınmış sipariş.
     *
     * Yükseltme siparişi yayından sonra ödenmiş olabilir: hizmet o an başladı (GREATEST).
     */
    private function backfillAttached(): void
    {
        DB::statement(
            'UPDATE orders
             SET first_published_at = GREATEST(invitations.published_at, orders.paid_at)
             FROM invitations
             WHERE orders.invitation_id = invitations.id
               AND invitations.published_at IS NOT NULL
               AND orders.paid_at IS NOT NULL
               AND orders.first_published_at IS NULL',
        );
    }

    /**
     * 2. Yanmış hak (K137): davetiyesi kalıcı silinmiş olabilir. Yayın anı
     * bilinmiyor; en iyi bilinen an yanma anı.
     */
    private function backfillConsumed(): void
    {
        DB::statement(
            'UPDATE orders
             SET first_published_at = consumed_at
             WHERE consumed_at IS NOT NULL
               AND first_published_at IS NULL',
        );
    }

    /**
     * 3. Daha önce SERBEST KALMIŞ hak. İz: aynı kullanıcının yayınlanmışken silinmiş
     * (çöpteki) davetiyesi + siparişin son güncellenmesi o silmeyle aynı an.
     * ⚠️ İz 30 gün yaşar (data:purge) ve sipariş sonradan güncellendiyse kaybolur.
     */
    private function backfillReleased(): void
    {
        DB::statement(
            "UPDATE orders
             SET first_published_at = trace.first_at
             FROM (
                 SELECT o.id, MIN(GREATEST(i.published_at, o.paid_at)) AS first_at
                 FROM orders o
                 JOIN invitations i ON i.user_id = o.user_id
                 WHERE o.invitation_id IS NULL
                   AND o.consumed_at IS NULL
                   AND o.first_published_at IS NULL
                   AND o.paid_at IS NOT NULL
                   AND i.deleted_at IS NOT NULL
                   AND i.published_at IS NOT NULL
                   AND o.paid_at <= i.deleted_at
                   AND o.updated_at BETWEEN i.deleted_at - INTERVAL '2 seconds' AND i.deleted_at + INTERVAL '2 seconds'
                 GROUP BY o.id
             ) AS trace
             WHERE orders.id = trace.id",
        );
    }
};
