<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MediaKind;
use App\Models\Invitation;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;

/**
 * Test ve seeder icin sahte medya KAYDI uretir.
 *
 * ⚠️ Yalnizca satiri uretir — DISKE DOSYA YAZMAZ. Gercek dosyaya ihtiyac duyan
 * testler Storage::fake() + UploadedFile::fake() kullanir (6.13). Ayrimi
 * bilerek koruyoruz: kota, iliski ve sizinti testlerinin gercek bir dosyaya
 * ihtiyaci yok ve her testte disk I/O odemek gereksiz.
 *
 * 🔴 Rastgelelik yalnizca DAVRANISI ETKILEMEYEN alanlarda. `kind` ve
 * `size_bytes` sabit: ikisi de kota ve sinir testlerini belirliyor.
 * Ayrintili aciklama: docs/rehber/database/factories/MediaFactory.md
 *
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * Bir medya kaydinin varsayilan alanlari.
     *
     * Donus tipi BILEREK yazilmadi — ust siniftan devralinir (Faz 2, ders 19).
     */
    public function definition(): array
    {
        return [
            // Ust kayit verilmezse fabrika kendi davetiyesini uretir.
            'invitation_id' => Invitation::factory(),

            'kind' => MediaKind::Gallery,

            // Action hangi diske yaziyorsa fabrika da onu yazsin: testte
            // Storage::fake() ayni adi taklit ediyor.
            'disk' => Config::string('davetkart.media.disk'),

            // 🔴 Yol RASTGELE olmak ZORUNDA: tabloda UNIQUE(disk, path) var.
            // Sabit bir yol yazsaydik ikinci fabrika cagrisi kisiti ihlal eder
            // ve test "sebepsiz" kirilirdi.
            'path' => 'media/gallery/'.fake()->uuid().'.jpg',

            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,

            // Kuyruk isi henuz kosmadi.
            'optimized_at' => null,
        ];
    }

    /**
     * LCV fotografi — misafirin yukledigi tur.
     *
     * 🔴 `kind` ile `mime_type` ve `path` BIRLIKTE degisiyor. Yalnizca kind'i
     * degistirseydik "turu video ama MIME'i jpeg" gibi gercekte olusamayacak
     * bir satir uretirdik ve testler var olmayan bir durumu dogrulardi
     * (InvitationFactory::published()'in status + published_at'i birlikte
     * degistirmesiyle ayni ilke).
     */
    public function rsvpPhoto(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => MediaKind::RsvpPhoto,
            'mime_type' => 'image/jpeg',
            'path' => 'media/rsvp_photo/'.fake()->uuid().'.jpg',
        ]);
    }

    /** LCV videosu — optimize EDILMEYEN tur (MediaKind::isOptimizable). */
    public function rsvpVideo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => MediaKind::RsvpVideo,
            'mime_type' => 'video/mp4',
            'path' => 'media/rsvp_video/'.fake()->uuid().'.mp4',
            'size_bytes' => 1_048_576,
        ]);
    }

    /** Kuyruk isi bu dosyayi zaten islemis. */
    public function optimized(): static
    {
        return $this->state(fn (array $attributes): array => [
            'optimized_at' => now(),
        ]);
    }

    /**
     * Belirli bir boyut — kota/limit testlerinin okunur olmasi icin.
     *
     * `->sized(5_000_000)` yazmak, `->create(['size_bytes' => 5_000_000])`
     * yazmaktan daha net soyler ki bu sayi TESTIN KONUSU.
     */
    public function sized(int $bytes): static
    {
        return $this->state(fn (array $attributes): array => [
            'size_bytes' => $bytes,
        ]);
    }
}
