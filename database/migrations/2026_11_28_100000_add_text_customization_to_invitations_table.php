<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 24 (K302, K303, K305): davetiyenin dili ve kullanıcının değiştirdiği sabit şablon metinleri.
 * Ayrıntı: docs/rehber/database/migrations/2026_11_28_100000_add_text_customization_to_invitations_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            // K302: v2.0'da yalnızca 'tr'; geçerli değerler config('davetkart.invitation_locales').
            $table->string('locale', 8)->default('tr')->after('palette');

            // K303: yalnızca değişen metinler, dile göre gruplu. Sorgulanmaz (K6: küçük içerik jsonb).
            $table->jsonb('text_overrides')->nullable()->after('locale');

            // K305: paket kuralı SQL ile doğrulanabilsin (K6). Model mutator'ı text_overrides ile birlikte yazar.
            $table->boolean('has_custom_texts')->default(false)->after('text_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn(['locale', 'text_overrides', 'has_custom_texts']);
        });
    }
};
