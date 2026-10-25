<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Models\User;
use App\Support\EmailNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/auth/email-change — yeni adres + mevcut parola (Faz 14, 14.10).
 *
 * 🔴 `unique:users` kuralı BİLEREK YOK (RegisterRequest ile aynı gerekçe):
 * "bu adres kullanımda" demek bir hesap tarayıcısı açardı. Çakışma onay
 * anında, veritabanının UNIQUE kısıtında yakalanır (14.11).
 * `not_in` kendi adresi içindir: sızdıracak bir şey yok, kullanıcı zaten biliyor.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/RequestEmailChangeAction.md
 */
final class RequestEmailChangeRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->user();

        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::notIn([$user->email])],
            'currentPassword' => ['required', 'string', 'current_password:sanctum'],
        ];
    }

    /** Kayıt ve girişle AYNI normalizasyon (Faz 10, 10.13): `İ` iki hesap açmasın. */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => EmailNormalizer::normalize(trim($email))]);
        }
    }

    public function newEmail(): string
    {
        /** @var string $email */
        $email = $this->validated('email');

        return $email;
    }
}
