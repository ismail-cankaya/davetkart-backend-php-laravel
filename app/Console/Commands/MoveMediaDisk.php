<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\InvitationChanged;
use App\Models\Invitation;
use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * A-E6 (K183): bir diskteki medya dosyalarını başka diske kopyalar ve satırın `disk`'ini çevirir (ör. public → s3).
 * Tek seferlik bakım komutu, zamanlayıcıda yok (K84); önce `--dry-run`, kaynak yalnızca `--delete-source` ile silinir.
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/MoveMediaDisk.md
 */
final class MoveMediaDisk extends Command
{
    protected $signature = 'media:move-disk
                            {--from=public : Kaynak disk}
                            {--to= : Hedef disk (varsayılan davetkart.media.disk)}
                            {--dry-run : Kopyalama, yalnızca kaç dosyanın taşınacağını söyle}
                            {--delete-source : Doğrulanmış kopyadan sonra kaynak dosyayı sil}';

    protected $description = 'Medya dosyalarını bir diskten diğerine taşır ve kayıtlarını günceller';

    public function handle(): int
    {
        $option = $this->option('from');
        $from = is_string($option) ? $option : '';
        $to = $this->targetDisk();

        if ($from === '' || $from === $to || ! is_array(Config::get("filesystems.disks.{$from}")) || ! is_array(Config::get("filesystems.disks.{$to}"))) {
            $this->components->error("Geçersiz disk çifti: {$from} → {$to}");

            return self::INVALID;
        }

        $rows = Media::query()->where('disk', $from);

        if ($this->option('dry-run') === true) {
            $this->components->info(sprintf('%d dosya %s → %s taşınacak (taşınmadı).', $rows->count(), $from, $to));

            return self::SUCCESS;
        }

        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $moved = 0;
        $missing = [];
        $invitations = [];

        $rows->chunkById(100, function ($chunk) use ($source, $target, $from, $to, &$moved, &$missing, &$invitations): void {
            foreach ($chunk as $media) {
                if (! $this->copy($source, $target, $media->path)) {
                    $missing[] = $media->id;

                    continue;
                }

                // Koşullu: bu arada başka bir süreç satırı değiştirdiyse üzerine yazılmaz.
                if (Media::query()->whereKey($media->id)->where('disk', $from)->update(['disk' => $to]) === 1) {
                    $moved++;
                    $invitations[$media->invitation_id] = true;

                    if ($this->option('delete-source') === true) {
                        $source->delete($media->path);
                    }
                }
            }
        });

        // Misafir önbelleği eski URL'leri taşıyor; olay önbelleği ve önizlemeyi tazeler (K48).
        Invitation::query()->whereKey(array_keys($invitations))->each(fn (Invitation $invitation) => event(new InvitationChanged($invitation)));

        $this->components->info(sprintf('%d dosya %s → %s taşındı.', $moved, $from, $to));

        if ($missing !== []) {
            $this->components->error(sprintf('%d kaydın kaynak dosyası yok; satırları değişmedi: %s', count($missing), implode(', ', $missing)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function targetDisk(): string
    {
        $option = $this->option('to');

        return is_string($option) && $option !== '' ? $option : Config::string('davetkart.media.disk');
    }

    /** Kopyalar ve boyutla doğrular; hedefte aynı boyutta dosya varsa yeniden yazmaz. Kaynak yoksa false. */
    private function copy(Filesystem $source, Filesystem $target, string $path): bool
    {
        if (! $source->exists($path)) {
            return false;
        }

        if (! $target->exists($path) || $target->size($path) !== $source->size($path)) {
            $stream = $source->readStream($path);

            if ($stream === null) {
                return false;
            }

            $target->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $target->size($path) === $source->size($path);
    }
}
