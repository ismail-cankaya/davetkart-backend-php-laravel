<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PATCH /api/auth/me (Faz 14, 14.7).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/UpdateProfileAction.md §4
 */
final class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_guest_cannot_update_a_profile(): void
    {
        $this->patchJson(route('auth.update'), ['firstName' => 'A', 'lastName' => 'B'])->assertUnauthorized();
    }

    #[Test]
    public function the_name_is_trimmed_and_saved(): void
    {
        $user = User::factory()->create(['first_name' => 'Eski', 'last_name' => 'Ad']);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->patchJson(route('auth.update'), ['firstName' => '  İsmail ', 'lastName' => ' Cankaya'])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => (string) $user->id,
                'firstName' => 'İsmail',
                'lastName' => 'Cankaya',
                'email' => $user->email,
            ]]);

        $user->refresh();
        $this->assertSame('İsmail', $user->first_name);
        $this->assertSame('Cankaya', $user->last_name);
    }

    /** 🔴 Gövdedeki e-posta ve parola sessizce yok sayılır: kimlik bu uçtan değişmez. */
    #[Test]
    public function the_email_and_password_cannot_change_here(): void
    {
        $user = User::factory()->create();
        $email = $user->email;
        $hash = $user->password;

        $this->withToken($user->createToken('t')->plainTextToken)
            ->patchJson(route('auth.update'), [
                'firstName' => 'A',
                'lastName' => 'B',
                'email' => 'saldirgan@example.com',
                'password' => 'yeni-parola-123',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', $email);

        $user->refresh();
        $this->assertSame($email, $user->email);
        $this->assertSame($hash, $user->password);
    }

    #[Test]
    public function blank_names_are_refused(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->patchJson(route('auth.update'), ['firstName' => '   ', 'lastName' => str_repeat('x', 61)])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.firstName.0.rule', 'required')
            ->assertJsonPath('error.fields.lastName.0.rule', 'max');
    }
}
