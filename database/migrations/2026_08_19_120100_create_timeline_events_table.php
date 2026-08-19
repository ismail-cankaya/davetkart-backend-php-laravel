<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_events', function (Blueprint $table) {
            // Bu satirlar URL'de gecmez; enumeration riski yok, ULID gereksiz.
            // Sozlesmedeki "id string" kurali Resource katmaninda karsilanir.
            $table->id();

            // Ust tablonun anahtari ULID oldugu icin foreignId DEGIL foreignUlid.
            $table->foreignUlid('invitation_id')->constrained()->cascadeOnDelete();

            // Autosave yarim veri gonderir (yeni adim bos baslikla dogar).
            $table->string('time', 8)->nullable();      // '16:30' — sorgulanmaz
            $table->string('title', 120)->nullable();
            $table->text('description')->nullable();

            // Kullanicinin siralamasi; saate gore siralama YAPILMAZ (bkz. kilavuz §4).
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Tek sorgu deseni: bu davetiyenin adimlarini sirasiyla getir.
            $table->index(['invitation_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_events');
    }
};
