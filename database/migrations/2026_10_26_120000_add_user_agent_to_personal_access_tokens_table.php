<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oturum listesi için cihaz bilgisi (Faz 14, 14.9 · K143).
 *
 * 🔴 IP adresi BİLEREK yok. "Aktif oturumlar" ekranı IP göstermek için bir
 * sebep sunar ama IP kişisel veridir ve CLAUDE.md §3 ham IP saklamayı yasaklar;
 * hash'lenmiş IP ise kullanıcıya hiçbir şey söylemez. Cihazı tanıtmak için
 * tarayıcı/işletim sistemi yeter (veri minimizasyonu, KVKK md. 4).
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_26_120000_add_user_agent_to_personal_access_tokens_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // Kısaltılmış User-Agent; eski token'larda NULL ("bilinmeyen cihaz").
            $table->string('user_agent', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('user_agent');
        });
    }
};
