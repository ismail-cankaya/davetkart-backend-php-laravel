<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Account\SendEmailVerificationAction;
use App\Exceptions\RegistrationFailedException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Yeni kullanici olusturur ve ilk API token'ini uretir.
 * Gelen veri RegisterRequest'ten gecmistir; burada TEKRAR DOGRULANMAZ.
 * Ayrıntı: docs/rehber/app/Actions/Auth/RegisterUserAction.md §K18-1
 */
final class RegisterUserAction
{
    public function __construct(
        private readonly IssueApiTokenAction $issueToken,
        private readonly SendEmailVerificationAction $sendVerification,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string}  $attributes
     * @param  string|null  $userAgent  Oturum listesinde cihazı tanıtır (Faz 14, K143)
     *
     * @return array{user: User, token: string}
     *
     * @throws RegistrationFailedException E-posta zaten kayitliysa.
     */
    public function handle(array $attributes, ?string $userAgent): array
    {
        try {
            $registered = DB::transaction(function () use ($attributes, $userAgent): array {
                $user = User::create($attributes);

                return [
                    'user' => $user,
                    'token' => $this->issueToken->handle($user, $userAgent),
                ];
            });

            // K191: doğrulama postası commit'ten SONRA kuyruğa girer. K276: ödeme ve yayın
            // doğrulamayı beklemez; yalnızca ilk yayın indirimi bekler (FirstPublishEligibility).
            $this->sendVerification->handle($registered['user']);

            return $registered;
        } catch (UniqueConstraintViolationException) {
            // H6: hangi alanin catistigi ISTEMCIYE soylenmez.
            throw RegistrationFailedException::emailTaken();
        }
    }
}
