<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hediye kodu kullanımları (Faz 11): her satır bir siparişe bağlı bir kullanım.
 *
 * 🔴 Satır "kullanıldı" DEMEZ. Kullanımın aktif olup olmadığını bağlı siparişin
 * durumu söyler (D4): pending/paid/refunded aktif, failed/expired iade olmuş.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_23_100100_create_gift_code_redemptions_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_code_redemptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Kullanımı olan kod silinemez; kod kapatılır (disabled_at).
            $table->foreignUlid('gift_code_id')->constrained()->restrictOnDelete();

            // Hesap silinince satır kalır, sahibi anonimleşir: kodun toplam
            // kullanım sayısı düşmez (orders.user_id ile aynı karar, K97).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // UNIQUE: bir siparişte yalnızca bir kod.
            $table->foreignUlid('order_id')->unique()->constrained()->restrictOnDelete();

            // Kullanım anındaki indirim; kod ya da fiyat sonra değişse de geçmiş sabit.
            $table->unsignedInteger('discount_minor');

            $table->timestamp('created_at')->nullable();

            // Kişi başı kontrolün sorgusu: "bu kod + bu kullanıcı".
            $table->index(['gift_code_id', 'user_id']);
        });

        // PostgreSQL'de UNSIGNED yok. 0 serbest değil: indirim vermeyen bir
        // kullanım anlamsız (her fayda türü en az 1 kuruş düşürür).
        DB::statement('ALTER TABLE gift_code_redemptions ADD CONSTRAINT gift_code_redemptions_discount_check CHECK (discount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_code_redemptions');
    }
};
