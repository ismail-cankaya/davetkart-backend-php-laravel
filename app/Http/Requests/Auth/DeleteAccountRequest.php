<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/auth/me — hesabi silmeden once parola onayi (Faz 10, 10.39).
 *
 * 🔴 Token tek basina YETMEZ. Acik unutulmus bir oturum (paylasilan bilgisayar)
 * ya da calinmis bir token, geri alinamaz bir islemi tetiklememeli. Parolayi
 * bilmek, hesabin sahibinin O ANDA klavyede oldugunun kaniti.
 *
 * `current_password:sanctum`: Laravel'in kurali, istegi tasiyan token'in
 * kullanicisinin parolasini kontrol eder. Yanlis parola -> 422, alan hatasi
 * (`fields.password.0.rule = current_password`). Kimlik zaten dogrulanmis
 * oldugu icin "parola yanlis" demek bir sey sizdirmaz (H6 burada gecerli degil).
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Auth/DeleteAccountRequest.md
 */
final class DeleteAccountRequest extends FormRequest
{
    /** auth:sanctum kimligi zaten dogruladi; hesap yalnizca kendini silebilir. */
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
            'password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }
}
