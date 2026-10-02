<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LCV düzenleme kodunun özeti (Faz 10, 10.59 · K101).
 *
 * Boş olabilir: Faz 10'dan önceki yanıtların kodu yok, onlar güncellenemez.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_02_110000_add_edit_code_hash_to_rsvps_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rsvps', function (Blueprint $table): void {
            $table->string('edit_code_hash', 64)->nullable()->after('ip_hash');
        });
    }

    public function down(): void
    {
        Schema::table('rsvps', function (Blueprint $table): void {
            $table->dropColumn('edit_code_hash');
        });
    }
};
