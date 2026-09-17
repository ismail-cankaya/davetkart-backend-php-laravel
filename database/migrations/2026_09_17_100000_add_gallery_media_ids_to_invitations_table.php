<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Galerinin SIRASI — Faz 6'nin acik kalan maddesi (docs/09, `gallery_images[]`).
 *
 * 🔴 `media` satirlari galerinin kendisi DEGIL, sunucunun KAYDIDIR: kota
 * sayimi ve temizlik icin. Kullanicinin gordugu dizilisin tek adresi bu
 * kolondur (Media kilavuzu §6). `created_at`'e gore siralamak, olmayan bir
 * sirayi uydurmak olurdu: es zamanli yuklemeler istenen sirayla bitmez ve
 * ileride suruklenerek degistirilen sira hic ifade edilemezdi.
 *
 * Kolon `media` KIMLIKLERINI tutar, URL'lerini degil: URL disk + yol +
 * yapilandirmadan TURETILIR ve saklanmaz (E1, create_media_table §5).
 *
 * Varsayilan `[]` ve NOT NULL: "galerisi yok" bir DEGERDIR, bilinmeyen bir
 * durum degil. PostgreSQL varsayilani mevcut satirlara da yazar; ayri bir
 * doldurma adimi gerekmez.
 * Ayrintili aciklama: docs/rehber/database/migrations/2026_09_17_100000_add_gallery_media_ids_to_invitations_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->jsonb('gallery_media_ids')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropColumn('gallery_media_ids');
        });
    }
};
