<?php

declare(strict_types=1);

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Kişiye özel fiyat kataloğunun sorgusu: isteğe bağlı `invitation` (Faz 18.3, K163).
 *
 * Fiyat bir HEDEF için sorulur: `?invitation=<ulid>` o davetiye, parametre
 * yoksa davetiyesiz (paket) — checkout'un iki ucuyla aynı ayrım (K42).
 *
 * 🔴 Sahiplik burada SINANMAZ ve gerekmez: kimlik yalnızca kullanıcının KENDİ
 * siparişleriyle karşılaştırılır; başkasının kimliği hiçbir şey açmaz.
 * Ayrıntılı açıklama: docs/rehber/app/Http/Requests/Pricing/ShowPricingRequest.md
 */
final class ShowPricingRequest extends FormRequest
{
    /** Uç auth'lu; her giriş yapmış kullanıcı kendi fiyatını sorabilir. */
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
            // 'ulid' BİÇİMİ doğrular, varlığı değil (RsvpRequest ile aynı; D6: string kural).
            'invitation' => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }

    /** Fiyatı sorulan davetiye; null = davetiyesiz (paket). */
    public function invitationId(): ?string
    {
        /** @var array{invitation?: string|null} $validated */
        $validated = $this->validated();

        return $validated['invitation'] ?? null;
    }
}
