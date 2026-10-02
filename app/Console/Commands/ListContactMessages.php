<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContactMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * İletişim formundan gelen mesajları listeler (Faz 10, 10.65).
 *
 * Yönetim paneli yok; mesajlar bu komutla, sunucuda okunur. En yeni üstte.
 *
 * 🔴 Mesaj metni misafirden geliyor: terminal kontrol karakterleri (renk,
 * imleç, ekran temizleme) yazdırılmadan önce görünür hâle getirilir.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/ListContactMessages.md
 */
final class ListContactMessages extends Command
{
    protected $signature = 'contact:list
                            {--limit=20 : En fazla kaç mesaj}
                            {--since= : Bu tarihten (YYYY-MM-DD) sonrakiler}
                            {--full : Mesajın tamamı (varsayılan: ilk 80 karakter)}';

    protected $description = 'Iletisim formundan gelen mesajlari listeler (en yeni ustte)';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $since = $this->option('since');
        $timezone = Config::string('davetkart.default_timezone');

        $query = ContactMessage::query()->latest()->limit($limit);

        if (is_string($since) && $since !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
                $this->error('--since YYYY-MM-DD biciminde olmali.');

                return self::INVALID;
            }

            // Gün, mesajların gösterildiği saat diliminde başlar.
            $query->where('created_at', '>=', CarbonImmutable::parse($since, $timezone)->startOfDay()->utc());
        }

        $messages = $query->get();

        if ($messages->isEmpty()) {
            $this->info('Mesaj yok.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tarih', 'Konu', 'Ad', 'E-posta', 'Mesaj'],
            $messages->map(fn (ContactMessage $message): array => [
                $message->created_at?->setTimezone($timezone)->format('Y-m-d H:i'),
                $message->subject->value,
                $this->safe($message->name),
                $this->safe($message->email),
                $this->safe($this->option('full') ? $message->message : Str::limit($message->message, 80)),
            ])->all(),
        );

        $this->line(sprintf('%d mesaj (saat dilimi: %s).', $messages->count(), $timezone));

        return self::SUCCESS;
    }

    /**
     * Kontrol karakterlerini görünür kılar: "\e[2J" ekranı silmesin,
     * "\u001b[2J" olarak yazılsın. C1 aralığı (U+0080–U+009F) da dahil:
     * bazı terminaller onları da komut sayar. Satır sonu ve sekme kalır.
     */
    private function safe(string $text): string
    {
        return (string) preg_replace_callback(
            '/[\x{00}-\x{08}\x{0B}-\x{1F}\x{7F}-\x{9F}]/u',
            static fn (array $match): string => sprintf('\u%04x', mb_ord($match[0])),
            $text,
        );
    }
}
