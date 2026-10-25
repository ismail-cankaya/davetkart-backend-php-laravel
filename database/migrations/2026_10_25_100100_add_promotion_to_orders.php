<?php

declare(strict_types=1);

use App\Enums\PromotionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Siparişin aldığı otomatik indirim (Faz 13, K133).
 *
 * `discount_minor` artık TOPLAM indirimdir: otomatik indirim + hediye kodu.
 * Liste fiyatı ayrı bir kolon değil: `amount_minor + discount_minor` (E1:
 * türetilebilen saklanmaz; saklansaydı bir gün ikisi ayrışabilirdi).
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_25_100100_add_promotion_to_orders.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // NULL = otomatik indirim yok. Değerler enum'dan (K39).
            $table->string('promotion', 16)->nullable();

            // discount_minor'ın otomatik indirimden gelen kısmı (kuruş).
            // Hediye kodunun kısmı = discount_minor - promotion_discount_minor.
            $table->unsignedInteger('promotion_discount_minor')->default(0);

            // Yalnızca promotion = 'campaign' iken dolu. restrict: kampanya
            // satırı silinmez, durdurulur (muhasebe izi).
            $table->foreignUlid('price_campaign_id')->nullable()->constrained('price_campaigns')->restrictOnDelete();
        });

        $kinds = "'".implode("', '", PromotionKind::values())."'";

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_promotion_check CHECK (promotion IS NULL OR promotion IN ({$kinds}))");

        // 🔴 Üç kısıt, üç "imkânsız satır" (E2: kural if ile değil kısıtla):
        //   1. otomatik indirim toplam indirimden büyük olamaz
        //   2. kaynak yoksa tutar yok, tutar varsa kaynak var
        //   3. kampanya kimliği ancak ve ancak kaynak 'campaign' iken dolu
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_promotion_discount_check CHECK (promotion_discount_minor >= 0 AND promotion_discount_minor <= discount_minor)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_promotion_source_check CHECK ((promotion IS NULL) = (promotion_discount_minor = 0))');

        // COALESCE şart: NULL = 'campaign' NULL üretir ve CHECK NULL'ı GEÇİRİR.
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_promotion_campaign_check CHECK ((COALESCE(promotion, '') = 'campaign') = (price_campaign_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_promotion_campaign_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_promotion_source_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_promotion_discount_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_promotion_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('price_campaign_id');
            $table->dropColumn(['promotion', 'promotion_discount_minor']);
        });
    }
};
