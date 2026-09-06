<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_usages', function (Blueprint $table) {
            // 🔴 ULID DEGIL bigint. K40'in ayirt edici sorusu: "bu kimlik bir
            // URL'de geciyor mu?" Gecmiyor — bu satiri disaridan kimse
            // adresleyemez, yalnizca (user_id, usage_date) ciftiyle bulunur.
            // timeline_events de ayni sebeple bigint kalmisti.
            $table->id();

            // Kullanici silinirse sayaci da gider: ona ait olmayan bir veri
            // degil, TAMAMEN ona ait bir sayac (KVKK: unutulma hakki).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Gunun kendisi — zaman damgasi DEGIL. Kota "bugun kac mesaj"
            // sorusudur; saat tasimak, gun sinirini bulaniklastirirdi (E8).
            $table->date('usage_date');

            $table->unsignedInteger('message_count')->default(0);

            $table->timestamps();

            // 🔴 KOTANIN ASIL KORUMASI BU KISIT. Bir kullanicinin bir gunu
            // icin IKINCI bir satir olusamaz; olusabilseydi es zamanli iki
            // istek iki ayri sayac acar ve kotayi ikiye katlardi (E2:
            // benzersizlik `if` ile degil VERITABANI KISITIYLA korunur).
            // Ayni kisit, sorgunun indeksi olarak da gorev yapiyor.
            $table->unique(['user_id', 'usage_date']);
        });

        // 🔴 PostgreSQL'de UNSIGNED YOKTUR: unsignedInteger 'integer'a duser
        // ve -5 kabul eder. Negatif bir sayac, kotayi sessizce genisletirdi.
        // rsvps.guest_count'ta ogrenilen ayni ders.
        DB::statement(
            'ALTER TABLE assistant_usages
             ADD CONSTRAINT assistant_usages_message_count_check CHECK (message_count >= 0)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_usages');
    }
};
