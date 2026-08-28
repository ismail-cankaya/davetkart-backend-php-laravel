<?php

declare(strict_types=1);

use App\Enums\RsvpStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rsvps', function (Blueprint $table) {
            // 🔴 K52: bu kimlik URL'de gecer (DELETE /api/rsvps/{id}), bu yuzden
            // ULID. K40'in gerekcesi aynen gecerli: artan bigint, disaridan
            // sayilabilir bir sayac verir. timeline_events bigint kaldi cunku
            // onun kimligi hicbir URL'de gecmiyor.
            $table->ulid('id')->primary();

            // Davetiye silinirse LCV yanitlari da gider (KVKK: unutulma hakki).
            // Ust tablonun anahtari ULID oldugu icin foreignId DEGIL foreignUlid.
            $table->foreignUlid('invitation_id')->constrained()->cascadeOnDelete();

            // Misafirin verdigi bilgiler. guest_name ZORUNLU: adsiz bir LCV
            // yanitinin sahibe hicbir faydasi yok.
            $table->string('guest_name', 120);
            $table->unsignedSmallInteger('guest_count')->default(1);

            // K49: gosterim metni degil makine-okunur kod. CHECK asagida.
            $table->string('status', 16);

            // Menu tercihi frontend katalogunun anahtari — kisitlanmaz (E6).
            $table->string('menu_preference', 60)->nullable();
            $table->text('message')->nullable();

            // 🔴 KVKK veri minimizasyonu: HAM IP SAKLANMAZ. Ayni misafirin
            // tekrar gonderip gondermedigini anlamaya yeter, kimin gonderdigini
            // soylemeye yetmez. sha256 -> 64 onaltilik karakter.
            $table->string('ip_hash', 64);

            $table->timestamps();

            // Iki sorgu desenini birden karsilar:
            //   kota  : WHERE invitation_id = ? AND status IN (...)
            //   liste : WHERE invitation_id = ? ORDER BY created_at DESC
            // Ikincisinde de indeks kullanilir cunku invitation_id ONEKTIR.
            $table->index(['invitation_id', 'status']);
        });

        // Gecerli durumlar enum'dan gelir; elle yazilsaydi enum degistiginde
        // kisit sessizce eskirdi. Kaynak derleme zamani sabiti oldugu icin
        // string birlestirme burada guvenlidir — kullanici girdisi degil.
        $allowed = "'".implode("', '", RsvpStatus::values())."'";

        DB::statement(
            "ALTER TABLE rsvps
             ADD CONSTRAINT rsvps_status_check CHECK (status IN ({$allowed}))",
        );

        // 🔴 PostgreSQL'de UNSIGNED YOKTUR: unsignedSmallInteger 'smallint'e
        // duser ve -5 kabul eder. Sifir veya negatif misafir sayisi kotayi
        // ASAGI cekerdi. Ust sinir (config: max_guests_per_entry) BURADA DEGIL
        // dogrulamada: o bir is tercihi, bu bir veri butunlugu kurali (E6).
        DB::statement(
            'ALTER TABLE rsvps
             ADD CONSTRAINT rsvps_guest_count_check CHECK (guest_count >= 1)',
        );
    }

    public function down(): void
    {
        // Kisitlar tabloya bagli oldugu icin tabloyla birlikte dusser.
        Schema::dropIfExists('rsvps');
    }
};
