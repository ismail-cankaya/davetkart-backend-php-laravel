<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\MediaStorageUnavailableException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * 🔴 K183: üretimde medya YALNIZCA `s3` diskine yazılır; başka bir disk (ör.
 * unutulmuş `public` varsayılanı) yüklemeyi 503 ile durdurur, okumayı değil.
 * Ayrintili aciklama: docs/rehber/app/Support/MediaDiskGuard.md
 */
final readonly class MediaDiskGuard
{
    public const string PRODUCTION_DISK = 's3';

    public function __construct(private Application $app) {}

    public function disk(): string
    {
        return Config::string('davetkart.media.disk');
    }

    public function refusesUploads(): bool
    {
        return $this->app->isProduction() && $this->disk() !== self::PRODUCTION_DISK;
    }

    /** @throws MediaStorageUnavailableException Üretimde yanlış disk -> 503 */
    public function assertUploadsAllowed(): void
    {
        if (! $this->refusesUploads()) {
            return;
        }

        Log::critical('Media upload refused: production media disk is not s3', ['disk' => $this->disk()]);

        throw MediaStorageUnavailableException::wrongDisk($this->disk());
    }

    /** `php artisan about` satırı: diski ve üretimde yüklemenin kapalı olup olmadığını gösterir. */
    public function aboutLine(): string
    {
        return $this->refusesUploads()
            ? $this->disk().' (UPLOADS REFUSED: production requires '.self::PRODUCTION_DISK.')'
            : $this->disk();
    }
}
