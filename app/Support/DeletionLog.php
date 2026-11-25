<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DeletionSubject;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * K233 (B-E1): silme basina bir dosya (yalnizca tur + kimlik + zaman); veritabani disinda.
 * Ayrintili aciklama: docs/rehber/app/Support/DeletionLog.md
 */
final class DeletionLog
{
    private const DIRECTORY = 'deletion-log';

    public function record(DeletionSubject $subject, string $id): void
    {
        $at = CarbonImmutable::now('UTC');
        $path = sprintf('%s/%s/%s-%s-%s.json', self::DIRECTORY, $at->format('Y-m-d'), $at->format('His.u'), $subject->value, $id);

        $this->disk()->put($path, json_encode([
            'subject' => $subject->value,
            'id' => $id,
            'at' => $at->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<array{subject: DeletionSubject, id: string, at: CarbonImmutable}> eskiden yeniye */
    public function since(CarbonInterface $from): array
    {
        $from = CarbonImmutable::instance($from)->utc();
        $entries = [];

        foreach ($this->disk()->directories(self::DIRECTORY) as $day) {
            if (basename($day) < $from->format('Y-m-d')) {
                continue;
            }
            foreach ($this->disk()->files($day) as $file) {
                $entry = $this->parse((string) $this->disk()->get($file));
                if ($entry !== null && $entry['at']->greaterThanOrEqualTo($from)) {
                    $entries[] = $entry;
                }
            }
        }

        usort($entries, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $entries;
    }

    /** Gun klasorleri `before`'un gununden eskiyse silinir; silinen klasor sayisi doner. */
    public function prune(CarbonInterface $before): int
    {
        $limit = CarbonImmutable::instance($before)->utc()->format('Y-m-d');
        $pruned = 0;

        foreach ($this->disk()->directories(self::DIRECTORY) as $day) {
            if (basename($day) < $limit) {
                $this->disk()->deleteDirectory($day);
                $pruned++;
            }
        }

        return $pruned;
    }

    /** @return array{subject: DeletionSubject, id: string, at: CarbonImmutable}|null */
    private function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_string($data['subject'] ?? null) || ! is_string($data['id'] ?? null) || ! is_string($data['at'] ?? null)) {
            return null;
        }
        $subject = DeletionSubject::tryFrom($data['subject']);

        return $subject === null ? null : ['subject' => $subject, 'id' => $data['id'], 'at' => CarbonImmutable::parse($data['at'])];
    }

    private function disk(): Filesystem
    {
        return Storage::disk(Config::string('deletion_log.disk'));
    }
}
