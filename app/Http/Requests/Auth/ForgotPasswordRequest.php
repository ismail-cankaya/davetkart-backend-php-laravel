<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\EmailNormalizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/auth/forgot-password girdisini dogrular (Faz 10, 10.32).
 *
 * 🔴 `exists:users,email` BILEREK YOK: "bu e-posta kayitli degil" demek
 * formu bir hesap tarayicisina cevirir. Uc her durumda ayni 202'yi doner.
 * Bicim hatasi (gecersiz e-posta) ise 422 alabilir: o, hesabin varligi
 * hakkinda bir sey soylemez.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Auth/ForgotPasswordRequest.md
 */
final class ForgotPasswordRequest extends FormRequest
{
    /** Parolasini unutan kullanici zaten giris yapamaz; yetki kontrolu yok. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * Kayit ve girisle AYNI normalizasyon (10.13): `İsmail@…` ile kaydolan
     * kullanici `ismail@…` ile sifirlama isteyebilmeli.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => EmailNormalizer::normalize($email)]);
        }
    }

    public function normalizedEmail(): string
    {
        /** @var array{email: string} $data */
        $data = $this->validated();

        return $data['email'];
    }
}
