<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Models\Invitation;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Support\TextSlots;

/**
 * Bir şablon metni için asistan önerileri (Faz 25, K310 — altyapı; istem ayrıntısı sonraki faz).
 * İstem sunucuda kurulur, kullanıcı serbest istem yazamaz; sözleşmeye uymayan öneri atılır.
 * Ayrıntı: docs/rehber/app/Actions/Assistant/SuggestInvitationTextsAction.md
 */
final class SuggestInvitationTextsAction
{
    public const MAX_SUGGESTIONS = 3;

    public function __construct(
        private readonly AiProvider $provider,
        private readonly ChargeAssistantQuotaAction $quota,
    ) {}

    /** @return list<string> */
    public function handle(User $user, Invitation $invitation, string $key, string $current): array
    {
        // Sohbetle aynı sıra: önce kota düşer, sonra para harcayan çağrı (AskAssistantAction §6).
        $this->quota->handle($user);

        $reply = $this->provider->reply($this->prompt($invitation, $key, $current));

        return $this->parse($key, $reply, $current);
    }

    private function prompt(Invitation $invitation, string $key, string $current): string
    {
        $slot = TextSlots::slot($key);
        $max = $slot['maxLength'] ?? 40;
        $placeholders = array_map(static fn (string $name): string => '{'.$name.'}', $slot['placeholders'] ?? []);

        return implode("\n", array_filter([
            'Bir dijital davetiye şablonundaki kısa bir metni yeniden yazıyorsun.',
            'Etkinlik türü: '.$invitation->category_id.'. Metnin yeri: '.$key.'.',
            'Şimdiki metin: "'.$current.'"',
            'Türkçe, sıcak ve kibar '.self::MAX_SUGGESTIONS.' farklı seçenek yaz. Her biri en fazla '.$max.' karakter.',
            $placeholders === [] ? null : 'Her seçenekte '.implode(', ', $placeholders).' ifadesi aynen bir kez geçsin.',
            'Yalnızca seçenekleri yaz: her satıra bir tane, numara, tırnak ve açıklama olmadan.',
        ]));
    }

    /** @return list<string> */
    private function parse(string $key, string $reply, string $current): array
    {
        $suggestions = [];

        foreach (preg_split('/\R/u', $reply) ?: [] as $line) {
            // Model kurala uymazsa: baştaki numara/madde işareti ve çevreleyen tırnaklar atılır.
            $text = trim((string) preg_replace('/^\s*(?:\d+[.)]|[-*•])\s*/u', '', $line));
            $text = trim($text, " \t\"'“”‘’");

            if ($text === '' || $text === $current || in_array($text, $suggestions, true) || ! TextSlots::isValidText($key, $text)) {
                continue;
            }

            $suggestions[] = $text;
            if (count($suggestions) === self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $suggestions;
    }
}
