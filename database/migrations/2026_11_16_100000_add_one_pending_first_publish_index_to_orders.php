<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PromotionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kullanıcı başına en fazla BİR bekleyen ilk yayın indirimli sipariş (Faz 18.3, SEC-01 · K158).
 *
 * 🔴 Tek kullanımlık hakkın veritabanı katmanı: uygulamadaki kilit atlansa da
 * ikinci satır yazılamaz (E2). Kısmi index yalnızca indirimi TUTAN satırları
 * sayar; ödenmiş, süresi dolmuş ve indirimsiz satırlar serbest.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_11_16_100000_add_one_pending_first_publish_index_to_orders.md
 */
return new class extends Migration
{
    private const INDEX = 'orders_one_pending_first_publish_per_user';

    private const GUIDE = 'docs/rehber/database/migrations/2026_11_16_100000_add_one_pending_first_publish_index_to_orders.md';

    public function up(): void
    {
        // Değerler enum'dan (K39); derleme zamanı sabiti, kullanıcı girdisi değil.
        $pending = OrderStatus::Pending->value;
        $firstPublish = PromotionKind::FirstPublish->value;

        // 🔴 K162: göç veri DEĞİŞTİRMEZ. Hangi siparişin kapanacağı bir insan
        // kararı; sessizce seçmek birinin açık ödemesini bozabilirdi.
        $conflicts = DB::table('orders')
            ->where('status', $pending)
            ->where('promotion', $firstPublish)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('user_id')
            ->pluck('user_id');

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                '%d kullanicinin birden fazla bekleyen ilk yayin indirimli siparisi var (user_id: %s). Goc veri degistirmez; cozum: %s §4',
                $conflicts->count(),
                $conflicts->take(10)->implode(', '),
                self::GUIDE,
            ));
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX." ON orders (user_id) WHERE status = '{$pending}' AND promotion = '{$firstPublish}'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
