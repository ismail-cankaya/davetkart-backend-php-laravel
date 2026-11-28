<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\MediaKind;
use App\Events\InvitationChanged;
use App\Support\TextSlots;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * Kullanicinin olusturdugu davetiye.
 * 🔴 `user_id`, `status` ve `published_at` BILEREK doldurulabilir DEGIL:
 * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-1
 */
#[Fillable([
    'category_id', 'preset_id', 'palette',
    'title', 'subtitle', 'names', 'venue', 'map_url', 'event_at', 'timezone',
    'show_envelope', 'show_timer', 'show_timeline',
    'show_gallery', 'show_gift', 'show_rsvp',
    'bank_name', 'account_holder', 'iban', 'gift_options',
    'rsvp_deadline', 'ask_menu_preference',
    'locale', 'text_overrides',
])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * Eloquent olayi -> alan (domain) olayi haritasi.
     * Cache temizleme buradan tetiklenir. Action'lardan ELLE firlatmak yerine
     * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-2
     */
    protected $dispatchesEvents = [
        'updated' => InvitationChanged::class,
        'deleted' => InvitationChanged::class,
        'restored' => InvitationChanged::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 🔴 InvitationPolicy kati karsilastirma yapar; HasUlids yuzunden
            // getIncrementing() false, yani anahtar cast'i otomatik gelmiyor.
            'user_id' => 'integer',

            'status' => InvitationStatus::class,

            // K23: degismez tarih. $d->addDay() orijinali bozmaz, kopya doner.
            'event_at' => 'immutable_datetime',
            'rsvp_deadline' => 'immutable_date',
            'published_at' => 'immutable_datetime',

            'gift_options' => 'array',

            // Galerinin sirasi: media ULID'leri (2026_09_17 migration'i).
            'gallery_media_ids' => 'array',

            'show_envelope' => 'boolean',
            'show_timer' => 'boolean',
            'show_timeline' => 'boolean',
            'show_gallery' => 'boolean',
            'show_gift' => 'boolean',
            'show_rsvp' => 'boolean',
            'ask_menu_preference' => 'boolean',
            'has_custom_texts' => 'boolean',

            // Faz 27.3 (K323): yalnızca Action'lar yazar (fillable değil).
            'pending_changes' => 'array',
        ];
    }

    /**
     * Kullanicinin degistirdigi sabit sablon metinleri (Faz 24, K303) ve paket bayragi (K305).
     * 🔴 `has_custom_texts` BURADA, `fill()` aninda yazilir: UpdateInvitationAction paket
     * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-3
     *
     * @return Attribute<array<string, array<string, string>>, array<string, array<string, string>>|null>
     */
    protected function textOverrides(): Attribute
    {
        return Attribute::make(
            get: static function (?string $value): array {
                if ($value === null || $value === '') {
                    return [];
                }

                /** @var array<string, array<string, string>> $decoded */
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

                return $decoded;
            },
            set: static function (?array $value): array {
                $kept = TextSlots::normalize($value);

                return [
                    'text_overrides' => $kept === null ? null : json_encode($kept, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'has_custom_texts' => $kept !== null,
                ];
            },
        );
    }

    /**
     * Misafire acik surumun cache anahtari.
     * Controller yazar (4.3), ClearInvitationCache siler (4.6): iki tuketici,
     * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-4
     */
    public static function publicCacheKey(string $id): string
    {
        // Faz 10 (10.63): büyük harfli istek ayrı ve hiç temizlenmeyen bir
        // girdi açmasın; dinleyici küçük harfli kimlikle siler.
        return Config::string('davetkart.cache.key_prefix').':public-invitation:'.strtolower($id);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Program akisi — HER ZAMAN kullanicinin sirasiyla doner.
     *
     * Siralama iliskinin icinde: cagiran yerde unutulursa sira rastgele olurdu.
     *
     * @return HasMany<TimelineEvent, $this>
     */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(TimelineEvent::class)->orderBy('sort_order');
    }

    /**
     * Bu davetiyeye gelen LCV yanitlari.
     * Siralama BILEREK yok. Program adimlarinin sirasi anlamin parcasiydi ve
     * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-5
     *
     * @return HasMany<Rsvp, $this>
     */
    public function rsvps(): HasMany
    {
        return $this->hasMany(Rsvp::class);
    }

    /**
     * Bu davetiye ICIN alinmis siparisler — yalnizca TEKIL alimlar (K42).
     * 🔴 Paket alimlar (invitation_id = NULL) bu iliskiden GORUNMEZ ve bu bir
     * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-6
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Bu davetiyeye yuklenmis dosyalarin KAYITLARI.
     * Siralama yok: galeri sirasi bu tabloda degil, `gallery_media_ids`
     * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-7
     *
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /**
     * Yalnizca galeri dosyalari — Resource'larin eager load ettigi iliski.
     * `media()` LCV foto/videolarini da tasir; galeriyi cizmek icin misafirlerin
     * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-8
     *
     * @return HasMany<Media, $this>
     */
    public function galleryMedia(): HasMany
    {
        return $this->hasMany(Media::class)->where('kind', MediaKind::Gallery);
    }

    /**
     * `gallery_media_ids` kolonunun TIPLI okumasi.
     * Kolon JSON; cast bize `array` verir ama icerigini garanti etmez. Metin
     * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-9
     *
     * @return list<string>
     */
    public function galleryMediaIds(): array
    {
        $raw = $this->gallery_media_ids;

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, is_string(...)));
    }

    /**
     * Galerideki dosyalar KULLANICININ SIRASIYLA.
     * 🔴 `galleryMedia` iliskisi YUKLU olmali (kati kip, 3.9). Dizide olup
     * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-10
     *
     * @return Collection<int, Media>
     */
    public function orderedGalleryMedia(): Collection
    {
        $byId = $this->galleryMedia->keyBy('id');
        $ordered = [];

        foreach ($this->galleryMediaIds() as $id) {
            $media = $byId->get($id);

            if ($media instanceof Media) {
                $ordered[] = $media;
            }
        }

        return collect($ordered);
    }

    /**
     * Odenmis TEKIL siparisin hakkinin serbest birakilabilecegi son an (K82).
     * `null` = davetiye hic yayinlanmadi; hak harcanmadi, pencere sorusu yok.
     * Ayrıntı: docs/rehber/app/Models/Invitation.md §K18-11
     */
    public function releaseWindowEndsAt(): ?CarbonImmutable
    {
        return $this->published_at?->addDays(Config::integer('davetkart.orders.release_window_days'));
    }
}
