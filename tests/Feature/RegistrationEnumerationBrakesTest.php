<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CountingHasher;
use Tests\TestCase;

/**
 * Faz 21.2 (SEC-15, K266): kayıt ucu kayıtlı adresi 422 ile söyler (kabul edilen risk); bu dosya frenleri kilitler.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/RegistrationEnumerationBrakesTest.md
 */
final class RegistrationEnumerationBrakesTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'kayitli@ornek.test';

    /** Kayıtlı adresle yapılan deneme de e-posta + IP kovasına sayılır: 5/dk, altıncısı 429. */
    #[Test]
    public function attempts_on_a_taken_address_fill_the_address_and_ip_bucket(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        foreach (range(1, 5) as $ignored) {
            $this->register(self::EMAIL, '203.0.113.7')
                ->assertUnprocessable()
                ->assertJsonPath('error.code', ErrorCode::RegistrationFailed->value);
        }

        $this->register(self::EMAIL, '203.0.113.7')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);

        // Kova adres + IP: aynı IP'den başka adres ve aynı adres başka IP'den geçer (IP kovası 20/dk dolmadı).
        $this->register('yeni@ornek.test', '203.0.113.7')->assertCreated();
        $this->register(self::EMAIL, '203.0.113.8')->assertUnprocessable();
    }

    /** Adres tarama (çok adres, tek IP): IP kovası 20/dk, yirmi birincisi 429. */
    #[Test]
    public function scanning_many_addresses_from_one_ip_is_capped(): void
    {
        foreach (range(1, 20) as $i) {
            $this->register("tarama{$i}@ornek.test", '198.51.100.9')->assertCreated();
            $this->forgetAuthState();
        }

        $this->register('tarama21@ornek.test', '198.51.100.9')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);
    }

    /**
     * A3 ruhu: kayıtlı adreste de parola hash'lenir (Argon2id, iki yolda da aynı maliyet).
     * Önce "adres var mı?" diye bakan bir kısa yol 422'yi ~200 ms erken döndürürdü.
     */
    #[Test]
    public function the_password_is_hashed_even_when_the_address_is_taken(): void
    {
        User::factory()->create(['email' => self::EMAIL]);
        $counter = new CountingHasher(Hash::getFacadeRoot());
        Hash::swap($counter);

        $this->register(self::EMAIL, '203.0.113.7')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::RegistrationFailed->value)
            ->assertJsonMissingPath('error.fields');

        $this->assertSame(1, $counter->makes);
    }

    /** @return TestResponse<Response> */
    private function register(string $email, string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(route('auth.register'), [
            'firstName' => 'Deniz',
            'lastName' => 'Kaya',
            'email' => $email,
            'password' => 'uzun-ve-yaygin-olmayan-42',
        ]);
    }
}
