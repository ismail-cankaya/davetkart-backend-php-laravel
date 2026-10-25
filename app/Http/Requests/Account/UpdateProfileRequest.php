<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/auth/me — ad ve soyad (Faz 14, 14.7).
 *
 * 🔴 E-posta bu uçtan DEĞİŞMEZ. Gövdede `email` gelse bile kurallarda yok,
 * validated()'a girmez. E-posta hesabın kimliği; değişmesi doğrulama ister (14.10).
 * Kurallar kayıtla AYNI (RegisterRequest): iki yerde iki farklı uzunluk olmasın.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/UpdateProfileAction.md
 */
final class UpdateProfileRequest extends FormRequest
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
            'firstName' => ['required', 'string', 'max:60'],
            'lastName' => ['required', 'string', 'max:60'],
        ];
    }

    /** Doğrulamadan ÖNCE kırp: "  " boş sayılsın ve `required`'a takılsın. */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['firstName', 'lastName'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = trim($value);
            }
        }

        $this->merge($normalized);
    }

    /**
     * @return array{first_name: string, last_name: string}
     */
    public function profile(): array
    {
        /** @var array{firstName: string, lastName: string} $data */
        $data = $this->validated();

        return ['first_name' => $data['firstName'], 'last_name' => $data['lastName']];
    }
}
