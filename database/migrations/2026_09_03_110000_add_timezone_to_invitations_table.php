<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔴 K63 — Faz 4'ten beri UC KEZ ertelenen kolon.
     *
     * Problem: `event_at` bir DUVAR SAATI saklar ('2026-08-21 19:00') ama
     * hangi saat diliminde oldugunu SOYLEMEZ. Misafirin geri sayim sayaci
     * bu degeri kendi cihazinin saat diliminde yorumlar — Berlin'deki misafir
     * dugunu bir saat once, Los Angeles'taki on saat once sanir.
     *
     * Neden `timestamptz` (saat dilimli zaman damgasi) degil?
     * Cunku sorun bir DEPOLAMA sorunu degil, bir NIYET sorunu. Kullanici
     * "dugun saat 19:00'da" der; bu 19:00 DUGUNUN OLDUGU YERIN saatidir.
     * timestamptz'e cevirmek icin o yerin saat dilimini zaten bilmemiz gerekir
     * — yani kolon her halukarda gerekli. Ustelik duvar saatini saklamak,
     * saat dilimi kurallari degistiginde (yaz saati uygulamasi kaldirilinca)
     * dogru davranistir: dugun yine 19:00'da.
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            // IANA saat dilimi kimligi ('Europe/Istanbul'). En uzun kayitli
            // kimlik 32 karakterin altinda; 64 rahat bir ust sinir.
            //
            // nullable: Faz 3'ten beri var olan kayitlarin saat dilimi
            // BILINMIYOR ve uydurmak bir veri yalanidir. Okuma tarafinda
            // config varsayilanina duser (N4: null bir bilgidir).
            $table->string('timezone', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
