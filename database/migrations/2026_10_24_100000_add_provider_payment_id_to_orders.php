<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sağlayıcının ödemeye verdiği kimlik (Faz 12, E5 · K121).
 *
 * `provider_ref` BİZİM sipariş kimliğimiz (Shopier'de platform_order_id =
 * orders.id); `provider_payment_id` ise Shopier'in `payment_id`'si. Destek ve
 * iade konuşmasında Shopier paneli yalnızca kendi kimliğini tanır.
 *
 * UNIQUE DEĞİL: idempotans `provider_ref`'te (M8). Bu kolon bilgi taşır; aynı
 * ödemenin iki bildirimi aynı değeri yazar ve bu bir hata değildir.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_24_100000_add_provider_payment_id_to_orders.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Nullable: Fake ve hediye siparişlerinde yok, Shopier'de ödeme
            // gerçekleşene kadar yok.
            $table->string('provider_payment_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['provider_payment_id']);
            $table->dropColumn('provider_payment_id');
        });
    }
};
