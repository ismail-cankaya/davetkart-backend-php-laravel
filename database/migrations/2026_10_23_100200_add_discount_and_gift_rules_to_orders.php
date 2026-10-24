<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Siparişe indirim kolonu ve hediye siparişi kuralı (Faz 11).
 *
 * 🔴 "Tutar 0 ancak hediye siparişinde olabilir" kuralı `if` ile değil kısıtla
 * kurulur (E2): `provider = 'gift'` ⇔ `amount_minor = 0`.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_23_100200_add_discount_and_gift_rules_to_orders.md
 */
return new class extends Migration
{
    /**
     * Order::GIFT_PROVIDER ile aynı değer. Sabite bağlanmadı: bir migration
     * koştuğu günün dünyasını anlatır, model sabiti sonradan değişebilir.
     */
    private const GIFT_PROVIDER = 'gift';

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Fiyattan düşülen tutar (kuruş). Ödenen = amount_minor.
            $table->unsignedInteger('discount_minor')->default(0);
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_minor_check CHECK (discount_minor >= 0)');

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_amount_minor_check');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_minor_check CHECK (amount_minor >= 0)');

        // İki yönlü: hediye siparişi bedavadır, bedava sipariş hediyedir.
        // İki kolon da NOT NULL; eşitlik hiçbir satırda NULL üretmez.
        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_gift_amount_check
             CHECK ((provider = '".self::GIFT_PROVIDER."') = (amount_minor = 0))",
        );
    }

    public function down(): void
    {
        // 🔴 Önce veri: 0 tutarlı (hediye) sipariş eski `amount_minor > 0`
        // kısıtını ihlal eder. Silmek ödeme kaydını yok etmek olur; karar insana.
        $gifts = DB::table('orders')->where('amount_minor', 0)->count();

        if ($gifts > 0) {
            throw new RuntimeException(
                "{$gifts} adet 0 tutarli (hediye) siparis var; geri alma bu kayitlari silmeyi gerektirir. Elle karar verilmeli.",
            );
        }

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_gift_amount_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_amount_minor_check');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_minor_check CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_discount_minor_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('discount_minor');
        });
    }
};
