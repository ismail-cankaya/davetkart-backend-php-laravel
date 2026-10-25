<?php

declare(strict_types=1);

use App\Enums\SubscriptionTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manuel fiyat kampanyaları (Faz 13, K132).
 *
 * Satır SİLİNMEZ, durdurulur (`stopped_at`): siparişler hangi kampanyadan
 * indirim aldığını `orders.price_campaign_id` ile gösterir ve muhasebe kaydı
 * bir komutla yok olamaz.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_25_100000_create_price_campaigns_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Yüzde. CHECK aşağıda: 1–90 (tutar indirimle 0'a inemez).
            $table->unsignedSmallInteger('percent');

            // NULL = bütün planlar. Değerler enum'dan (K39).
            $table->string('tier', 16)->nullable();

            // Kampanya penceresi. ends_at NULL = durdurulana kadar.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            // Elle durdurma anı. Satır silinmez (FK restrict).
            $table->timestamp('stopped_at')->nullable();

            // Operatör notu: "Yılbaşı kampanyası". Müşteriye gösterilmez.
            $table->string('label', 120)->nullable();

            $table->timestamps();

            // Tek sorgu deseni: "şu an geçerli kampanyalar".
            $table->index(['starts_at', 'ends_at']);
        });

        $tiers = "'".implode("', '", SubscriptionTier::values())."'";

        DB::statement("ALTER TABLE price_campaigns ADD CONSTRAINT price_campaigns_tier_check CHECK (tier IS NULL OR tier IN ({$tiers}))");

        // 🔴 Üst sınır 90, 100 DEĞİL: kampanya tutarı 0'a indiremez.
        // 0 tutarlı sipariş yalnızca hediye siparişi olabilir
        // (orders_gift_amount_check); bedava yayının yolu hediye kodu.
        DB::statement('ALTER TABLE price_campaigns ADD CONSTRAINT price_campaigns_percent_check CHECK (percent BETWEEN 1 AND 90)');

        DB::statement('ALTER TABLE price_campaigns ADD CONSTRAINT price_campaigns_window_check CHECK (ends_at IS NULL OR ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_campaigns');
    }
};
