<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/auth/register girdisini dogrular.
 *
 * Alan adlari camelCase'tir — istek neyi gonderiyorsa o dogrulanir (08 §2.4).
 * 🔴 `unique` kurali BILEREK YOK: e-postanin kayitli oldugunu soylemek
 * kullanici sayimi (enumeration) acigidir. Kontrol RegisterUserAction'da.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Auth/RegisterRequest.md
 */
final class RegisterRequest extends FormRequest
{
    /** Kayit herkese acik; yetki kontrolu yok. */
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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            // D6: kural ADI sozlesmeye giriyor. Kural NESNESI kullanilirsa
            // Laravel onu sinif adiyla raporlar ve framework ici API'ye sizar.
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    /**
     * Dogrulamadan ONCE calisir: bosluklari kirpar, e-postayi kucultur.
     *
     * 🔴 Buradaki veri HENUZ DOGRULANMAMISTIR. `email[]=x` gonderen bir istekte
     * input bir dizidir; mb_strtolower(dizi) TypeError firlatir. Bu yuzden
     * yalnizca string olanlara dokunulur, gerisi `string` kuralina birakilir.
     */
    // E posta CamelCase kuralları uygulanıyor (küçük harfe cevriliyor)
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['firstName', 'lastName', 'email'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = trim($value);
            }
        }

        if (isset($normalized['email'])) {
            $normalized['email'] = mb_strtolower($normalized['email']);
        }

        $this->merge($normalized);
    }

    /**
     * Dogrulanmis camelCase girdiyi User kolonlarina esler.
     *
     * HTTP alan adlarini bilmek bu katmanin isi; Action saf veri alir.
     *
     * @return array{first_name: string, last_name: string, email: string, password: string}
     */
    public function userAttributes(): array
    {
        /** @var array{firstName: string, lastName: string, email: string, password: string} $data */
        $data = $this->validated();

        return [
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'],
            'email' => $data['email'],
            // Ham parola: hash'lemeyi User modelinin `hashed` cast'i yapar.
            'password' => $data['password'],
        ];
    }
}
