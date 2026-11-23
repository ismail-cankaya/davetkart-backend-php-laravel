<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Exceptions\EmailVerificationFailedException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Doğrulama bağlantısının sorgusu (K191) — ConfirmEmailChangeRequest deseni: değerler sorguda,
 * göreli imza onları kapsar. Her bozukluk aynı 422'ye düşer (H6).
 */
final class ConfirmEmailVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'user' => ['required', 'integer'],
            'address' => ['required', 'string', 'size:64'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    protected function failedValidation(Validator $validator): never
    {
        throw EmailVerificationFailedException::because('malformed link');
    }

    protected function passedValidation(): void
    {
        if (! $this->hasValidRelativeSignature()) {
            throw EmailVerificationFailedException::because('invalid or expired signature');
        }
    }

    /** @return array{user_id: int, address_fingerprint: string} */
    public function link(): array
    {
        /** @var array{user: int|string, address: string} $data */
        $data = $this->validated();

        return ['user_id' => (int) $data['user'], 'address_fingerprint' => $data['address']];
    }
}
