<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\AccountLoginThrottledException;
use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use App\Notifications\SuspiciousLoginAttemptsNotification;
use App\Support\AccountLoginThrottle;
use Illuminate\Support\Facades\Hash;

/**
 * Kimlik dogrular ve token uretir; kullanici yoksa da sahte hash'le dogrular (zamanlama, 08 §3.1).
 * Hesap kovasi (SEC-16, K265): dolu kovada parola hic denenmez, dogru parola da 429 alir.
 * Ayrintili aciklama: docs/rehber/app/Actions/Auth/LoginUserAction.md
 */
final class LoginUserAction
{
    /** Hicbir kullanicinin parolasi olamayacak sabit girdi. */
    private const DUMMY_PASSWORD = 'password-that-is-never-valid';

    /** Surec basina bir kez uretilir; her istekte yeniden hesaplanmaz. */
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly IssueApiTokenAction $issueToken,
        private readonly AccountLoginThrottle $throttle,
    ) {}

    /**
     * @param  array{email: string, password: string}  $credentials
     * @param  string|null  $userAgent  Oturum listesinde cihazı tanıtır (Faz 14, K143)
     *
     * @return array{user: User, token: string}
     *
     * @throws InvalidCredentialsException Kullanici yoksa VEYA parola yanlissa.
     * @throws AccountLoginThrottledException Hesabin basarisiz giris kovasi doluysa (429).
     */
    public function handle(array $credentials, ?string $userAgent): array
    {
        $this->throttle->ensureOpen($credentials['email']);

        $user = User::where('email', $credentials['email'])->first();

        // Kullanici yoksa sahte hash kullanilir — is yuku her iki yolda da AYNI.
        // '??' null erisimini zaten bastirir; '?->' fazladan olurdu (PHPStan).
        $hash = $user->password ?? self::dummyHash();

        // 🔴 Kontrolden ONCE calisir; sirasi degistirilemez.
        $passwordMatches = Hash::check($credentials['password'], $hash);

        if ($user === null || ! $passwordMatches) {
            $this->recordFailure($credentials['email'], $user);

            throw new InvalidCredentialsException;
        }

        $this->rehashIfNeeded($user, $credentials['password']);

        return [
            'user' => $user,
            'token' => $this->issueToken->handle($user, $userAgent),
        ];
    }

    /** Kayitsiz adres de sayilir (429 kaydi ele vermez); uyari postasi yalnizca var olan hesaba. */
    private function recordFailure(string $email, ?User $user): void
    {
        if ($this->throttle->recordFailure($email) && $user !== null && $this->throttle->claimWarning($email)) {
            $user->notify(new SuspiciousLoginAttemptsNotification);
        }
    }

    /**
     * Hash parametreleri eskidiyse sessizce yukseltir (config: rehash_on_login).
     * Ham parola yalnizca su anda elimizde; firsat bu istekte kullanilir.
     */
    private function rehashIfNeeded(User $user, string $plainPassword): void
    {
        if (! Hash::needsRehash($user->password)) {
            return;
        }

        $user->password = $plainPassword;   // 'hashed' cast'i guncel ayarla hash'ler
        $user->save();
    }

    /** Gecerli hash ayarlariyla uretilir ki suresi gercek hash'lerle esitlensin. */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(self::DUMMY_PASSWORD);
    }
}
