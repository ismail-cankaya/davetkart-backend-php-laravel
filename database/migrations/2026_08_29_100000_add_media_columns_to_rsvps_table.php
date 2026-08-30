<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LCV yanitina misafirin yukledigi foto/videoyu baglar.
 *
 * 🔴 Bu migration Faz 5'te YAZILMADI ve bu bilincli bir karardi (ders 26):
 * o gun media tablosu yoktu, kolonlari yazan kod da yoktu. Bir faz boyunca
 * yazani olmayan kolon, DOGRU OLDUGU VARSAYILAN kolondur — Faz 4'te
 * InvitationPublished olayinin InvitationChanged'e donusme sebebiyle (K48)
 * ayni aile.
 *
 * Bugun uc sey birden hazir: media tablosu (6.2), yukleme uclari (6.15/6.16)
 * ve ayni fazda gelecek yazan kod (6.20). Bu yuzden kolonlar VE yabanci
 * anahtar TEK migration'da ekleniyor: "kolon var ama kisiti yok" ara durumu
 * hic olusmuyor.
 * Ayrintili aciklama: docs/rehber/database/migrations/2026_08_29_100000_add_media_columns_to_rsvps_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rsvps', function (Blueprint $table) {
            // 🔴 URL DEGIL KIMLIK. Frontend `photoUrl: string` bekliyor ama URL
            // bir TURETILEN degerdir (E1): RsvpResource onu media.disk +
            // media.path'ten cozer. Ham URL saklasaydik APP_URL veritabanina
            // gomulur ve alan adi degistigi gun tum baglantilar kirilirdi.
            //
            // foreignUlid: media.id ULID (6.2). Tip uyusmazsa kisit kurulamaz.
            $table->foreignUlid('photo_media_id')
                ->nullable()
                ->after('message')
                ->constrained('media')
                // 🔴 nullOnDelete, cascadeOnDelete DEGIL: dosya silinince LCV
                // YANITI DA silinmemeli. Misafirin yazdigi metin, ektigi
                // fotograftan bagimsiz bir veridir. cascade yazsaydik bir
                // temizlik isi sessizce LCV kayitlarini goturebilirdi.
                ->nullOnDelete();

            $table->foreignUlid('video_media_id')
                ->nullable()
                ->after('photo_media_id')
                ->constrained('media')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rsvps', function (Blueprint $table) {
            // Kisit once dusurulur: PostgreSQL kolonu FK'siyle birlikte
            // dusurmez, once bagi koparmak gerekir.
            $table->dropConstrainedForeignId('photo_media_id');
            $table->dropConstrainedForeignId('video_media_id');
        });
    }
};
