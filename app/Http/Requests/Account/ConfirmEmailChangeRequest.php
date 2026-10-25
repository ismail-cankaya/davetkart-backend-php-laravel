<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Exceptions\EmailChangeFailedException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/public/email-change/confirm?user=…&email=…&old=…&expires=…&signature=…
 * (Faz 14, 14.10 · K144).
 *
 * 🔴 Kimlik YOK (public): bağlantıyı açan, oturum açmamış olabilir (telefonda
 * mail). Yetkinin kanıtı İMZADIR. Her hata — eksik parametre dahil — aynı
 * EMAIL_CHANGE_INVALID'e döner: bağlantıyı kurcalayan biri hangi parçanın
 * yanlış olduğunu öğrenmesin.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/RequestEmailChangeAction.md §4
 */
final class ConfirmEmailChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Değerler SORGUDAN okunur: imza onları kapsıyor, gövde imzalanmamış.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user' => ['required', 'integer'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'old' => ['required', 'string', 'size:64'],
        ];
    }

    /**
     * Kurallar SORGUYA uygulanır (gövde değil).
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query();
    }

    protected function failedValidation(Validator $validator): never
    {
        throw EmailChangeFailedException::because('malformed link');
    }

    /** Doğrulamadan sonra imza: süre + bütünlük. */
    protected function passedValidation(): void
    {
        if (! $this->hasValidRelativeSignature()) {
            throw EmailChangeFailedException::because('invalid or expired signature');
        }
    }

    /**
     * @return array{user_id: int, email: string, old_fingerprint: string}
     */
    public function change(): array
    {
        /** @var array{user: int|string, email: string, old: string} $data */
        $data = $this->validated();

        return ['user_id' => (int) $data['user'], 'email' => $data['email'], 'old_fingerprint' => $data['old']];
    }
}
