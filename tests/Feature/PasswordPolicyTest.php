<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 19.9 (SEC-05, K258): parola belirlenen uc uc, tek kural (PasswordPolicy).
 * Ayrintili aciklama: docs/rehber/tests/Feature/PasswordPolicyTest.md
 */
final class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const PASSPHRASE = 'mor-bisiklet-kahve-2027';

    /** @return array<string, array{string}> */
    public static function commonPasswords(): array
    {
        return [
            'rakam dizisi' => ['12345678'],
            'password' => ['password'],
            'klavye dizisi' => ['qwerty123456'],
            'buyuk harfle' => ['QWERTY123456'],
            'tek karakter tekrari' => ['aaaaaaaaaaaa'],
        ];
    }

    #[Test]
    #[DataProvider('commonPasswords')]
    public function registration_rejects_common_passwords(string $password): void
    {
        $this->postJson(route('auth.register'), $this->registration($password))
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.password.0.rule', 'uncommon_password');

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    #[DataProvider('commonPasswords')]
    public function password_reset_rejects_common_passwords(string $password): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson(route('auth.password.reset'), ['email' => $user->email, 'token' => $token, 'password' => $password])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.password.0.rule', 'uncommon_password');
    }

    #[Test]
    #[DataProvider('commonPasswords')]
    public function password_change_rejects_common_passwords(string $password): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->putJson(route('auth.password.update'), ['currentPassword' => UserFactory::PASSWORD, 'password' => $password])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.password.0.rule', 'uncommon_password');
    }

    /** Regresyon: uzun, yaygin olmayan bir parola cumlesi uc ucta da gecer. */
    #[Test]
    public function a_long_uncommon_passphrase_is_accepted(): void
    {
        $this->postJson(route('auth.register'), $this->registration(self::PASSPHRASE))->assertCreated();

        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $this->postJson(route('auth.password.reset'), ['email' => $user->email, 'token' => $token, 'password' => self::PASSPHRASE])
            ->assertSuccessful();

        $other = User::factory()->create();
        $this->withToken($other->createToken('t')->plainTextToken)
            ->putJson(route('auth.password.update'), ['currentPassword' => UserFactory::PASSWORD, 'password' => self::PASSPHRASE])
            ->assertNoContent();
    }

    /** Uzunluk kurali degismedi (Ismail'in karari: 8); 7 karakter `min`, 8 karakter gecer. */
    #[Test]
    public function the_minimum_length_is_eight(): void
    {
        $this->postJson(route('auth.register'), $this->registration('kx7#mq2'))
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.password.0.rule', 'min')
            ->assertJsonPath('error.fields.password.0.params.min', 8);

        $this->postJson(route('auth.register'), $this->registration('kx7#mq2z'))->assertCreated();
    }

    /** @return array{firstName: string, lastName: string, email: string, password: string} */
    private function registration(string $password): array
    {
        return ['firstName' => 'Deniz', 'lastName' => 'Kaya', 'email' => 'deniz@example.com', 'password' => $password];
    }
}
