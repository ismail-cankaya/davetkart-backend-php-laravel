<?php

declare(strict_types=1);

namespace App\Http\Requests\GiftCode;

use App\Enums\SubscriptionTier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Hediye kodu önizleme gövdesi: `code`, `tier` ve isteğe bağlı `invitationId` (Faz 11, 18.3).
 *
 * Kodun biçimi burada yalnızca kaba: uzunluk sınırı. Ayrı bir regex "biçim
 * hatası" ile "kod yok" arasında fark yaratır, D7'nin tek yanıtını delerdi.
 * Ayrıntılı açıklama: docs/rehber/app/Http/Requests/GiftCode/PreviewGiftCodeRequest.md
 */
final class PreviewGiftCodeRequest extends FormRequest
{
    /** Uç auth'lu; kodu her giriş yapmış kullanıcı önizleyebilir. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            // StoreCheckoutRequest ile aynı: 'in:' kuralı, Rule::enum değil (D6, Faz 7).
            'tier' => ['required', 'string', 'in:'.implode(',', SubscriptionTier::values())],
            // Faz 18.3 (K163): fiyatı sorulan hedef. Biçim doğrulanır, sahiplik değil:
            // kimlik yalnızca kullanıcının kendi siparişleriyle karşılaştırılır.
            'invitationId' => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }

    /** Fiyatı sorulan davetiye; null = davetiyesiz (paket). */
    public function invitationId(): ?string
    {
        /** @var array{invitationId?: string|null} $validated */
        $validated = $this->validated();

        return $validated['invitationId'] ?? null;
    }

    public function code(): string
    {
        /** @var array{code: string} $validated */
        $validated = $this->validated();

        return $validated['code'];
    }

    public function tier(): SubscriptionTier
    {
        /** @var array{tier: string} $validated */
        $validated = $this->validated();

        return SubscriptionTier::from($validated['tier']);
    }
}
