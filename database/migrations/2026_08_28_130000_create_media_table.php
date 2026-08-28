<?php

declare(strict_types=1);

use App\Enums\MediaKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            // K56: K40/K52 ile ayni kural — kimlik URL'de gecebilir (silme ucu
            // Faz 7'de gelecek), artan bigint platformdaki toplam dosya sayisini
            // ele verirdi.
            $table->ulid('id')->primary();

            // 🔴 SAHIPLIK ILISKIDEN GELIR. `user_id` kolonu BILEREK yok:
            // LCV medyasini kimligi bilinmeyen MISAFIR yukluyor, yani her
            // dosyanin bir kullanicisi yok. Ama her dosyanin bir DAVETIYESI var.
            // Yetki de oradan sorulur (P5, Faz 5'te RsvpPolicy ile ayni desen).
            $table->foreignUlid('invitation_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 16);   // CHECK asagida

            // 🔴 Hangi diskte durdugu SAKLANIR, config'ten okunmaz.
            // config('davetkart.media.disk') "SIMDI nereye yaziyoruz" sorusunun
            // cevabidir; bu kolon "O DOSYA nereye yazilmisti" sorusunun. Disk
            // degistigi gun (yerel -> S3) eski satirlar hala cozulebilir kalir.
            $table->string('disk', 32);

            // URL DEGIL yol saklanir. URL, Storage::url($disk, $path) ile
            // TURETILIR (E1). Ham URL yazilsaydi APP_URL veritabanina gomulurdu
            // ve alan adi degisince tum baglantilar kirilirdi.
            $table->string('path', 255);

            // Istemcinin BEYAN ETTIGI degil, dosyanin ICERIGINDEN okunan tip.
            $table->string('mime_type', 64);

            $table->unsignedInteger('size_bytes');

            // Kuyruktaki optimizasyon isini yapti mi? null = henuz islenmedi
            // (ya da tur optimize edilmiyor). Job'in tekrar calismasi zararsiz
            // olsun diye damga; ShouldBeUnique yerine veri ile idempotans.
            $table->timestamp('optimized_at')->nullable();

            $table->timestamps();

            // Ayni diskte ayni yol iki kez olamaz. Rastgele ad uretimi carpisma
            // ihtimalini zaten yok denecek kadar dusuruyor; bu kisit onu
            // ALISKANLIGA degil YAPIYA baglar (E2).
            $table->unique(['disk', 'path']);

            // Tek sorgu deseni: bu davetiyenin su turden dosyalari (kota + liste).
            $table->index(['invitation_id', 'kind']);
        });

        // Gecerli turler enum'dan gelir; elle yazilsaydi enum degisince kisit
        // sessizce eskirdi. Kaynak derleme zamani sabiti — kullanici girdisi degil.
        $allowed = "'".implode("', '", MediaKind::values())."'";

        DB::statement(
            "ALTER TABLE media
             ADD CONSTRAINT media_kind_check CHECK (kind IN ({$allowed}))",
        );

        // 🔴 PostgreSQL'de UNSIGNED YOKTUR (Faz 5'te rsvps.guest_count ile
        // ogrenildi): unsignedInteger duz 'integer'a duser ve -5 kabul eder.
        // Sifir baytlik dosya da anlamsiz — yukleme yarim kalmis demektir.
        DB::statement(
            'ALTER TABLE media
             ADD CONSTRAINT media_size_bytes_check CHECK (size_bytes > 0)',
        );
    }

    public function down(): void
    {
        // Kisitlar tabloya bagli oldugu icin tabloyla birlikte dusser.
        Schema::dropIfExists('media');
    }
};
