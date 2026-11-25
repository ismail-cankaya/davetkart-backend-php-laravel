<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Enums\SubscriptionTier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Odeme baslatma govdesinin dogrulanmasi — `tier` ve istege bagli `giftCode` (Faz 11).
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Payment/StoreCheckoutRequest.md §A-E5-10
 */
final class StoreCheckoutRequest extends FormRequest
{
    /** Yetki karari Policy'nin isi; controller Gate::authorize ile cagirir. */
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
            // Gecerli plan listesi ENUM'DAN turetilir (K39 ailesi): elle yazilsaydi enum'a yeni bir plan eklendiginde kural sessizce eskirdi.
            // Ayrintili aciklama: docs/rehber/app/Http/Requests/Payment/StoreCheckoutRequest.md §A-E5-40
            'tier' => ['required', 'string', 'in:'.implode(',', SubscriptionTier::values())],

            // Faz 11: hediye kodu; yalnizca kaba uzunluk siniri, gecerliligini veritabani soyler (D7).
            // Ayrintili aciklama: docs/rehber/app/Http/Requests/Payment/StoreCheckoutRequest.md §A-E5-52
            'giftCode' => ['nullable', 'string', 'max:64'],

            // K262 (A-E5): TR cep telefonu, normalleştirilmiş `05XXXXXXXXX`. Zorunluluğu sürücü belirler (StartCheckoutAction).
            'phone' => ['nullable', 'string', 'regex:/\A05\d{9}\z/'],
        ];
    }

    /** Boşluk, tire, parantez atılır; `+90` / `90` öneki `0`'a çevrilir. */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone)) {
            $digits = preg_replace('/[\s\-().]/', '', $phone) ?? $phone;
            $this->merge(['phone' => preg_replace('/\A(?:\+?90)(?=5\d{9}\z)/', '0', $digits) ?? $digits]);
        }
    }

    /** Doğrulanmış telefon; yoksa ya da boşsa null. */
    public function phone(): ?string
    {
        /** @var array{phone?: string|null} $validated */
        $validated = $this->validated();
        $phone = $validated['phone'] ?? null;

        return $phone === null || $phone === '' ? null : $phone;
    }

    /** Dogrulanmis plan — Action enum bekler, sihirli string degil. */
    public function tier(): SubscriptionTier
    {
        /** @var array{tier: string} $validated */
        $validated = $this->validated();

        return SubscriptionTier::from($validated['tier']);
    }

    /** Hediye kodu; yoksa ya da yalnizca bosluksa null. */
    public function giftCode(): ?string
    {
        /** @var array{giftCode?: string|null} $validated */
        $validated = $this->validated();
        $code = trim((string) ($validated['giftCode'] ?? ''));

        return $code === '' ? null : $code;
    }
}
