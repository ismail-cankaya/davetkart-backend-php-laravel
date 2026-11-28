<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 27.3 (K323): yayındaki davetiyenin yayınlanmamış değişiklikleri (taslak katmanı).
 * Ayrıntı: docs/rehber/database/migrations/2026_11_28_120000_add_pending_changes_to_invitations_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            // Yalnızca canlıdan farklı alanlar ve program; misafir okumaz, sorgulanmaz (K6: küçük içerik jsonb).
            $table->jsonb('pending_changes')->nullable()->after('has_custom_texts');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn('pending_changes');
        });
    }
};
