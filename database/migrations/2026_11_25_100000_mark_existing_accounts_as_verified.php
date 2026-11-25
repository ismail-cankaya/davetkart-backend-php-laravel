<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * K260 (A-E1): e-posta doğrulamasından (19.14, K191) önce açılmış hesaplar doğrulanmış sayılır.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_11_25_100000_mark_existing_accounts_as_verified.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // Damga hesabın açılış anı: "doğrulandı" değil, "doğrulamadan önce vardı" kaydı.
        DB::table('users')
            ->whereNull('email_verified_at')
            ->where('created_at', '<=', now())
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    // Geri alınamaz: göçün işaretlediği satır gerçekten doğrulanmış olandan ayırt edilemez.
    public function down(): void {}
};
