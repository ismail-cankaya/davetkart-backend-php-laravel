<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/auth/login girdisini dogrular.
 *
 * 🔴 Parola KARMASIKLIK kurali BILEREK YOK (bkz. kilavuz §3.1) ve
 * `exists:users,email` de YOK — ikisi de enumeration acigi uretir.
 * Girisin tek isi kimlik dogrulamak; kimlik TOPLAMAK degil.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Auth/LoginRequest.md
 */
final class LoginRequest extends FormRequest
{
    /** Giris herkese acik; yetki kontrolu yok. */
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
            // Yalnizca "var mi ve makul uzunlukta mi". Dogrulugu Hash::check soyler.
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * E-postayi kanonik hale getirir: kayitta da kucuk harfle saklandi.
     *
     * Bu olmadan "Ayse@Ornek.TEST" ile giris DENEMESI kaydi bulamaz ve
     * kullanici, dogru parolayla bile giris yapamaz.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        /** @var array{email: string, password: string} $data */
        $data = $this->validated();

        return $data;
    }
}
