<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/auth/password — mevcut parola + yeni parola (Faz 14, 14.8).
 *
 * 🔴 Token tek başına YETMEZ (DeleteAccountRequest ile aynı gerekçe): açık
 * kalmış bir oturumu bulan biri parolayı değiştirip asıl sahibi dışarıda
 * bırakamamalı. `current_password:sanctum` → yanlışsa 422, alan hatası.
 *
 * Yeni parolanın kuralı kayıtla AYNI (min 8, max 255). `different`: aynı
 * parolayı "değiştirmek" diğer oturumları kapatır ama hiçbir şeyi korumaz;
 * kullanıcı büyük ihtimalle yanlış alana yazmıştır.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/ChangePasswordAction.md
 */
final class ChangePasswordRequest extends FormRequest
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
            'currentPassword' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'different:currentPassword'],
        ];
    }

    public function newPassword(): string
    {
        /** @var string $password */
        $password = $this->validated('password');

        return $password;
    }
}
