<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Kimlik dogrular ve yeni bir API token'i uretir.
 *
 * 🔴 ZAMANLAMA SALDIRISI SAVUNMASI: kullanici bulunamasa bile sahte bir hash'e
 * karsi dogrulama yapilir. Aksi halde yanit ~200 ms hizli doner ve saldirgan
 * bunu OLCEREK e-postanin kayitli oldugunu anlar (08 §3.1).
 * Ayrintili aciklama: docs/rehber/app/Actions/Auth/LoginUserAction.md
 */
final class LoginUserAction
{
    /** RegisterUserAction ile ayni etiket; token kaynagi ayirt edilmez. */
    private const TOKEN_NAME = 'api';

    /** Hicbir kullanicinin parolasi olamayacak sabit girdi. */
    private const DUMMY_PASSWORD = 'password-that-is-never-valid';

    /** Surec basina bir kez uretilir; her istekte yeniden hesaplanmaz. */
    private static ?string $dummyHash = null;

    /**
     * @param  array{email: string, password: string}  $credentials
     *
     * @return array{user: User, token: string}
     *
     * @throws InvalidCredentialsException Kullanici yoksa VEYA parola yanlissa.
     */
    public function handle(array $credentials): array
    {
        $user = User::where('email', $credentials['email'])->first();

        // Kullanici yoksa sahte hash kullanilir — is yuku her iki yolda da AYNI.
        $hash = $user?->password ?? self::dummyHash();

        // 🔴 Kontrolden ONCE calisir; sirasi degistirilemez.
        $passwordMatches = Hash::check($credentials['password'], $hash);

        if ($user === null || ! $passwordMatches) {
            throw new InvalidCredentialsException;
        }

        $this->rehashIfNeeded($user, $credentials['password']);

        return [
            'user' => $user,
            'token' => $user->createToken(self::TOKEN_NAME)->plainTextToken,
        ];
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
