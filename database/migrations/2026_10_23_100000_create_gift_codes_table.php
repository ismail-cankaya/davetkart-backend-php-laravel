<?php

declare(strict_types=1);

use App\Enums\GiftCodeBenefit;
use App\Enums\SubscriptionTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hediye kodları (Faz 11).
 *
 * 🔴 Kodun kendisi SAKLANMAZ, SHA-256 özeti saklanır (D3). Kullanım sayısı da
 * saklanmaz; sipariş durumundan türetilir (D4).
 * Fayda türü ile alanlar arasındaki bağ CHECK kısıtlarıyla kurulur (E2/E11).
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_23_100000_create_gift_codes_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // hash('sha256', normalize(code)): 64 onaltılık karakter.
            $table->char('code_hash', 64)->unique();

            // Listeleme için son 4 karakter; kodu geri vermez.
            $table->string('code_hint', 8);

            $table->string('label', 120)->nullable();

            // Toplu üretimin kimliği; elle girilen kodda NULL.
            $table->ulid('batch_id')->nullable()->index();

            $table->string('benefit', 16);
            $table->string('tier', 16)->nullable();
            $table->smallInteger('percent_off')->nullable();
            $table->unsignedInteger('amount_off_minor')->nullable();
            $table->unsignedInteger('max_redemptions');

            $table->timestamp('expires_at')->nullable();

            // Silmek yerine kapatılır: kullanım satırları koda bağlı (denetim izi).
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();
        });

        // Kaynak derleme zamanı sabiti (enum case'leri), kullanıcı girdisi
        // değil: string birleştirme burada güvenli (create_orders_table ile aynı).
        $benefits = "'".implode("', '", GiftCodeBenefit::values())."'";
        $tiers = "'".implode("', '", SubscriptionTier::values())."'";
        $free = GiftCodeBenefit::Free->value;
        $percent = GiftCodeBenefit::Percent->value;
        $fixed = GiftCodeBenefit::Fixed->value;

        DB::statement("ALTER TABLE gift_codes ADD CONSTRAINT gift_codes_benefit_check CHECK (benefit IN ({$benefits}))");

        // NULL geçer (üç değerli mantık): "bütün planlar" demek.
        DB::statement("ALTER TABLE gift_codes ADD CONSTRAINT gift_codes_tier_check CHECK (tier IN ({$tiers}))");

        // Ücretsiz kod HANGİ planı açtığını bilmek zorunda.
        DB::statement("ALTER TABLE gift_codes ADD CONSTRAINT gift_codes_free_has_tier_check CHECK (benefit <> '{$free}' OR tier IS NOT NULL)");

        // ⇔ ilişkisi: yüzde alanı YALNIZCA yüzde kodunda dolu (iki yönde).
        DB::statement("ALTER TABLE gift_codes ADD CONSTRAINT gift_codes_percent_check CHECK ((benefit = '{$percent}') = (percent_off IS NOT NULL) AND (percent_off IS NULL OR percent_off BETWEEN 1 AND 100))");

        // PostgreSQL'de UNSIGNED yok: > 0 kısıtla kurulur.
        DB::statement("ALTER TABLE gift_codes ADD CONSTRAINT gift_codes_amount_off_check CHECK ((benefit = '{$fixed}') = (amount_off_minor IS NOT NULL) AND (amount_off_minor IS NULL OR amount_off_minor > 0))");

        DB::statement('ALTER TABLE gift_codes ADD CONSTRAINT gift_codes_max_redemptions_check CHECK (max_redemptions >= 1)');
    }

    public function down(): void
    {
        // Kısıtlar tabloyla birlikte düşer.
        Schema::dropIfExists('gift_codes');
    }
};
