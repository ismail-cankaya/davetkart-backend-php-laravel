<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Exceptions\AssistantQuotaExceededException;
use App\Models\AssistantUsage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Asistanın günlük kotasından bir mesaj düşer (Faz 25'te AskAssistantAction'dan ayrıldı, K310): sohbet
 * ve metin önerisi AYNI kotayı paylaşır. Ayrıntı: docs/rehber/app/Actions/Assistant/ChargeAssistantQuotaAction.md
 */
final class ChargeAssistantQuotaAction
{
    /**
     * Gunluk sayaci bir artirir; butce doluysa exception firlatir.
     * 🔴 CHECK-THEN-ACT YOK. "Once oku, sonra karsilastir, sonra yaz" uc
     * Ayrıntı: docs/rehber/app/Actions/Assistant/ChargeAssistantQuotaAction.md §K18-1
     */
    public function handle(User $user): void
    {
        $limit = Config::integer('davetkart.assistant.daily_message_limit_per_user');
        $today = $this->today();

        // 1. Satiri VAR ET. insertOrIgnore: satir zaten varsa hicbir sey
        // yapmaz ve UNIQUE(user_id, usage_date) ihlali HATA FIRLATMAZ. Iki
        // Ayrıntı: docs/rehber/app/Actions/Assistant/ChargeAssistantQuotaAction.md §K18-2
        AssistantUsage::query()->insertOrIgnore([
            'user_id' => $user->id,
            'usage_date' => $today,
            'message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Kosullu artirim. Eloquent'in increment()'i updated_at'i da
        // gunceller ve ETKILENEN SATIR SAYISINI doner.
        $charged = AssistantUsage::query()
            ->where('user_id', $user->id)
            ->where('usage_date', $today)
            ->where('message_count', '<', $limit)
            ->increment('message_count');

        if ($charged === 0) {
            throw new AssistantQuotaExceededException($this->secondsUntilReset(), $limit);
        }
    }

    /**
     * Kotanın günü: davetkart.default_timezone'da (İstanbul) bugünün tarihi.
     *
     * Faz 10 (10.57 · K106): önceden UTC'ydi ve kota İstanbul'da 03:00'te
     * yenileniyordu. Gerekçe: docs/rehber/app/Actions/Assistant/AskAssistantAction.md
     */
    private function today(): string
    {
        return $this->now()->toDateString();
    }

    /** Kotanın saat dilimindeki "şimdi". */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(Config::string('davetkart.default_timezone'));
    }

    /**
     * Butcenin yenilenmesine kalan saniye — 429'un Retry-After degeri.
     * Carbon'un diff metotlari surum ve `absolute` varsayilanina gore float
     * Ayrıntı: docs/rehber/app/Actions/Assistant/ChargeAssistantQuotaAction.md §K18-3
     */
    private function secondsUntilReset(): int
    {
        $now = $this->now();

        return max(1, $now->addDay()->startOfDay()->getTimestamp() - $now->getTimestamp());
    }
}
