<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Exceptions\AiProviderException;
use App\Exceptions\AssistantQuotaExceededException;
use App\Models\User;
use App\Services\Ai\AiProvider;

/**
 * Kullanicinin mesajini AI saglayicisina vekaleten iletir.
 * 🔴 Katmanli savunma (L1) — en ucuzdan pahaliya, ve buradaki "pahali"
 * Ayrıntı: docs/rehber/app/Actions/Assistant/AskAssistantAction.md §K18-1
 */
final class AskAssistantAction
{
    public function __construct(
        // 🔴 Somut surucu DEGIL arayuz (D — Dependency Inversion). Bu Action
        // Gemini'yi hic duymamistir; testte NullProvider baglanir ve tek bir
        // satiri degismez (K8, ders 52).
        private readonly AiProvider $provider,
        private readonly ChargeAssistantQuotaAction $quota,
    ) {}

    /**
     * @param  string  $prompt  AskAssistantRequest::prompt() — uzunlugu
     *                          dogrulanmis, icerigi DOGRULANMAMIS metin
     *
     * @throws AssistantQuotaExceededException Gunluk butce doldu -> 429
     * @throws AiProviderException Saglayici yapilandirilmamis/erisilemiyor -> 503
     */
    public function handle(User $user, string $prompt): string
    {
        // 🔴 SIRA: once kota DUSULUR, sonra cagri yapilir.
        // Tersi daha "adil" gorunurdu (cagri basarisizsa hak yanmasin) ama
        // Ayrıntı: docs/rehber/app/Actions/Assistant/AskAssistantAction.md §K18-2
        $this->quota->handle($user);

        return $this->provider->reply($prompt);
    }
}
