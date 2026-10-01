<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\EmailNormalizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/auth/reset-password girdisini dogrular (Faz 10, 10.33).
 *
 * Govde maildeki baglantidan gelir: `token` ve `email` sorgu dizesinden,
 * `password` kullanicinin yazdigindan. Parola kurali KAYITLA AYNI (min:8):
 * sifirlama, kayitta reddedilecek bir parolaya kapi acmamali.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Auth/ResetPasswordRequest.md
 */
final class ResetPasswordRequest extends FormRequest
{
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
            // Laravel'in token'i 64 karakterlik onaltilik bir dizedir. Sinir
            // bicimi DOGRULAMAZ (o aracin isi); yalnizca sacma uzunlugu keser.
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    /** Kayit, giris ve sifirlama istegiyle AYNI normalizasyon (10.13). */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => EmailNormalizer::normalize($email)]);
        }
    }

    /**
     * @return array{token: string, email: string, password: string}
     */
    public function credentials(): array
    {
        /** @var array{token: string, email: string, password: string} $data */
        $data = $this->validated();

        return $data;
    }
}
