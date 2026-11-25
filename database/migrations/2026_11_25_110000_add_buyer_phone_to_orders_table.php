<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K262 (A-E5, SEC-10 D2): alıcının cep telefonu yalnızca siparişte; Shopier'e yer tutucu yerine o gider.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_11_25_110000_add_buyer_phone_to_orders_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Nullable: hediye ve Fake siparişlerinde yok. Biçim `05XXXXXXXXX` (istek normalleştirir).
            $table->string('buyer_phone', 11)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('buyer_phone');
        });
    }
};
