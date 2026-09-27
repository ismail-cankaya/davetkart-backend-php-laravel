<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 2'nin kaniti: kayit, giris, cikis ve token dogrulama.
 *
 * T5: metin degil DAVRANIS dogrulanir — kod, durum, alan adi.
 * T6: bir davranisin hem VARLIGI hem YOKLUGU test edilir.
 * Ayrintili aciklama: docs/rehber/tests/Feature/AuthTest.md
 */
final class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ayse@ornek.test';

    private const PASSWORD = 'gizli1234';

    /** Denetimin K-3 ornegi. U+0130: Turkce klavyenin buyuk `İ`'si. */
    private const EMAIL_WITH_CAPITAL_I = "\u{0130}smail.Cankaya@gmail.com";

    private const EMAIL_PLAIN = 'ismail.cankaya@gmail.com';

    // ---------------------------------------------------------------- KAYIT

    #[Test]
    public function register_creates_user_and_returns_unwrapped_session(): void
    {
        $response = $this->postJson(route('auth.register'), $this->registerPayload());

        // Denetim (24 Eylul): testler yalnizca ASCII ad kullaniyordu. Turkce
        // harfleri bozan bir katman (kodlama, bir yerde unutulmus kucultme)
        // hicbir testi kirmazdi.
        $response->assertCreated()
            ->assertJsonPath('user.firstName', 'Ayşe')
            ->assertJsonPath('user.lastName', 'Yıldırım')
            ->assertJsonPath('user.email', self::EMAIL)
            ->assertJsonStructure(['user' => ['id', 'firstName', 'lastName', 'email'], 'token']);

        // 🔴 K11: auth yanitlari ZARFSIZ — {data: ...} olmamali.
        $response->assertJsonMissingPath('data');

        // Frontend `id: string` bekliyor.
        $this->assertIsString($response->json('user.id'));
    }

    #[Test]
    public function register_persists_hashed_password_and_lowercased_email(): void
    {
        $this->postJson(route('auth.register'), $this->registerPayload([
            'email' => 'AYSE@Ornek.TEST',
        ]))->assertCreated();

        $user = User::query()->where('email', self::EMAIL)->firstOrFail();

        // K32: Argon2id, ham parola degil.
        $this->assertStringStartsWith('$argon2id$', $user->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    #[Test]
    public function register_response_never_exposes_the_password(): void
    {
        $content = (string) $this->postJson(route('auth.register'), $this->registerPayload())
            ->assertCreated()
            ->getContent();

        $this->assertStringNotContainsString(self::PASSWORD, $content);
        $this->assertStringNotContainsString('password', $content);
    }

    /** T6'nin "varlik" yarisi: normal dogrulama hatasi `fields` DONDURUR. */
    #[Test]
    public function register_reports_field_errors_for_invalid_input(): void
    {
        $this->postJson(route('auth.register'), $this->registerPayload(['password' => '123']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value)
            ->assertJsonPath('error.fields.password.0.rule', 'min');
    }

    /** 🔴 T6'nin "yokluk" yarisi: kayit hatasi `fields` DONDURMEZ (H6). */
    #[Test]
    public function register_does_not_reveal_that_the_email_is_taken(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->postJson(route('auth.register'), $this->registerPayload())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::RegistrationFailed->value)
            ->assertJsonMissingPath('error.fields');
    }

    /** Buyuk harfli yazim ayni hesaba dusmeli — mutator + prepareForValidation. */
    #[Test]
    public function register_treats_email_case_insensitively(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->postJson(route('auth.register'), $this->registerPayload([
            'email' => 'AYSE@Ornek.TEST',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::RegistrationFailed->value);

        $this->assertSame(1, User::query()->count());
    }

    // ---------------------------------------------------------------- GIRIS

    #[Test]
    public function login_returns_unwrapped_session(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL]);

        $response = $this->postJson(route('auth.login'), [
            'email' => self::EMAIL,
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonStructure(['user' => ['id', 'firstName', 'lastName', 'email'], 'token'])
            ->assertJsonMissingPath('data');

        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function login_accepts_the_email_in_any_case(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->postJson(route('auth.login'), [
            'email' => '  AYSE@Ornek.TEST  ',
            'password' => UserFactory::PASSWORD,
        ])->assertOk();
    }

    /**
     * 🔴 ENUMERATION TESTI: kayitli olmayan e-posta ile yanlis parola
     * BIREBIR AYNI yaniti uretir. Fark olsaydi kayit taramasi mumkun olurdu.
     */
    #[Test]
    public function login_is_indistinguishable_for_unknown_email_and_wrong_password(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $unknownEmail = $this->postJson(route('auth.login'), [
            'email' => 'hicyok@ornek.test',
            'password' => 'yanlis-parola',
        ]);

        $wrongPassword = $this->postJson(route('auth.login'), [
            'email' => self::EMAIL,
            'password' => 'yanlis-parola',
        ]);

        $unknownEmail->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::InvalidCredentials->value)
            ->assertJsonMissingPath('error.fields');

        $wrongPassword->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::InvalidCredentials->value);

        // Govdeler ayirt edilemez olmali (APP_DEBUG=false — T4).
        $this->assertSame($unknownEmail->getContent(), $wrongPassword->getContent());
    }

    // ------------------------------------------------ TURKCE İ (K-3 · 10.13)

    /**
     * 🔴 Denetimin K-3 senaryosu, birebir (1. ve 2. adim).
     *
     * Faz 9'da: kayit 201 ama veritabanina "i̇smail…" (i + U+0307) yaziliyordu;
     * ayni kullanici "ismail…" ile giriste dogru parolayla 401 aliyordu.
     *
     * T6'nin yokluk yarisi da burada: normalizasyon YALNIZCA e-postaya
     * uygulanir. Ad "İsmail" olarak kalmali — "ismail" degil.
     */
    #[Test]
    public function a_user_registered_with_a_turkish_capital_i_can_log_in_with_a_plain_i(): void
    {
        $this->postJson(route('auth.register'), $this->registerPayload([
            'firstName' => 'İsmail',
            'lastName' => 'Işıkoğlu',
            'email' => self::EMAIL_WITH_CAPITAL_I,
        ]))
            ->assertCreated()
            ->assertJsonPath('user.email', self::EMAIL_PLAIN)
            ->assertJsonPath('user.firstName', 'İsmail')
            ->assertJsonPath('user.lastName', 'Işıkoğlu');

        // Ekranda ayni gorunen iki dizi: baytla karsilastir.
        $this->assertSame(bin2hex(self::EMAIL_PLAIN), bin2hex(User::query()->sole()->email));

        $this->postJson(route('auth.login'), [
            'email' => self::EMAIL_PLAIN,
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    /** Ters yon: hesap duz `i` ile kayitli, kullanici `İ` ile giriyor. Korudugu: LoginRequest. */
    #[Test]
    public function login_accepts_a_turkish_capital_i_for_an_account_stored_with_a_plain_i(): void
    {
        User::factory()->create(['email' => self::EMAIL_PLAIN]);

        $this->postJson(route('auth.login'), [
            'email' => self::EMAIL_WITH_CAPITAL_I,
            'password' => UserFactory::PASSWORD,
        ])->assertOk();
    }

    /** 🔴 K-3'un 3. adimi: ayni adresin ikinci hesabi. Faz 9'da 201 donuyordu. */
    #[Test]
    public function a_second_account_cannot_be_opened_with_the_other_form_of_the_same_address(): void
    {
        User::factory()->create(['email' => self::EMAIL_PLAIN]);

        $this->postJson(route('auth.register'), $this->registerPayload([
            'email' => self::EMAIL_WITH_CAPITAL_I,
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::RegistrationFailed->value);

        $this->assertSame(1, User::query()->count());
    }

    /**
     * Son savunma hatti: istek katmanindan GECMEYEN yazim (seeder, tinker,
     * factory, ileride parola sifirlama). Korudugu: User::setEmailAttribute().
     *
     * Kayit ucu bu mutator'i sinayamaz: RegisterRequest e-postayi zaten
     * normalize edip gonderiyor (kilavuz §3.7, esdeger mutant).
     */
    #[Test]
    public function the_user_model_folds_the_turkish_capital_i_on_every_write(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL_WITH_CAPITAL_I]);

        $this->assertSame(self::EMAIL_PLAIN, $user->refresh()->email);
    }

    /**
     * 🔴 Hiz siniri kovasi da AYNI normalizasyondan gecer. Korudugu:
     * AppServiceProvider::authLimits().
     *
     * Bes deneme duz `i` ile, altincisi `İ` ile. Kova anahtari farkli
     * normalize edilseydi altinci deneme YENI bir kovaya duserdi (401):
     * ayni hesaba dakikada 5 degil 10 deneme. IP kovasi 20/dk oldugu icin
     * buradaki 429 yalnizca e-posta kovasindan gelebilir.
     */
    #[Test]
    public function the_auth_limiter_counts_both_forms_of_i_in_one_bucket(): void
    {
        User::factory()->create(['email' => self::EMAIL_PLAIN]);

        foreach (range(1, 5) as $ignored) {
            $this->postJson(route('auth.login'), [
                'email' => self::EMAIL_PLAIN,
                'password' => 'yanlis-parola',
            ])->assertUnauthorized();
        }

        $this->postJson(route('auth.login'), [
            'email' => self::EMAIL_WITH_CAPITAL_I,
            'password' => 'yanlis-parola',
        ])
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);
    }

    // ------------------------------------------------------------ ME / CIKIS

    #[Test]
    public function me_requires_a_valid_token(): void
    {
        $this->getJson(route('auth.me'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    /**
     * 🔴 `me` zarfli doner: {data: ...} — istisna yalnizca login/register icin.
     *
     * T10: `actingAs()` DEGIL. `actingAs()` guard'i atlar; `me` tam da
     * "token hala gecerli mi?" sorusunun ucu (frontend acilista bunu soracak,
     * 10.29) ve o soruyu soran yol burada test edilmeli.
     */
    #[Test]
    public function me_returns_the_wrapped_user(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL]);

        $this->withToken($this->tokenFor($user))
            ->getJson(route('auth.me'))
            ->assertOk()
            ->assertJsonPath('data.email', self::EMAIL)
            ->assertJsonMissingPath('data.password');
    }

    #[Test]
    public function logout_requires_authentication(): void
    {
        $this->postJson(route('auth.logout'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    /** 🔴 Cikis YALNIZCA istegi tasiyan token'i iptal eder. */
    #[Test]
    public function logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();

        $phone = $user->createToken('api')->plainTextToken;
        $laptop = $user->createToken('api')->plainTextToken;

        $this->withToken($phone)->postJson(route('auth.logout'))->assertNoContent();

        // 🔴 T13: guard cozdugu kullaniciyi onbellekler, setRequest onu temizlemez.
        // Sifirlanmazsa sonraki istek TOKEN'A HIC BAKMADAN ayni kullaniciyi doner.
        $this->forgetAuthState();
        $this->withToken($phone)->getJson(route('auth.me'))->assertUnauthorized();

        $this->forgetAuthState();
        $this->withToken($laptop)->getJson(route('auth.me'))->assertOk();

        $this->assertSame(1, $user->tokens()->count());
    }

    // ----------------------------------------------------------- TOKEN OMRU

    /**
     * 🔴 K90: token 30 gun gecerli, MUTLAK — `created_at`'ten sayilir.
     *
     * Sinirin IKI yani da sinaniyor (T6): yalnizca "31. gunde 401" yazsaydik
     * omur 29 gune dusse de, 1 gune dusse de test yesil kalirdi.
     *
     * Ayni test "mutlak"i da kanitliyor: token son saniyede KULLANILIYOR
     * (Guard `last_used_at`'i gunceller). Kayan pencere olsaydi bu kullanim
     * omru 30 gun daha uzatirdi ve bir saniye sonraki istek 200 donerdi.
     *
     * Token login ucundan aliniyor, `createToken()`'dan degil: istemcinin
     * gercekte aldigi token bu. Yarin LoginUserAction token'a kendi
     * `expires_at`'ini yazarsa bu test onu da sinar.
     */
    #[Test]
    public function a_token_expires_thirty_days_after_it_was_issued(): void
    {
        $issuedAt = CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC');
        $this->travelTo($issuedAt);

        User::factory()->create(['email' => self::EMAIL]);

        $token = $this->postJson(route('auth.login'), [
            'email' => self::EMAIL,
            'password' => UserFactory::PASSWORD,
        ])->assertOk()->json('token');

        $this->assertIsString($token);

        // Son saniye: hala gecerli.
        $this->travelTo($issuedAt->addDays(30)->subSecond());
        $this->withToken($token)->getJson(route('auth.me'))->assertOk();

        // 30. gunun tam sonu: gecersiz. T13: guard onbellegi sifirlanmazsa
        // ikinci istek token'a hic bakmaz ve test haksiz yere 200 alir.
        $this->forgetAuthState();
        $this->travelTo($issuedAt->addDays(30));
        $this->withToken($token)->getJson(route('auth.me'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    // ----------------------------------------------------------- HIZ SINIRI

    /** 🔴 K36: alti deneme, altincisi reddedilir. */
    #[Test]
    public function credential_endpoints_are_rate_limited(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $payload = ['email' => self::EMAIL, 'password' => 'yanlis-parola'];

        foreach (range(1, 5) as $ignored) {
            $this->postJson(route('auth.login'), $payload)->assertUnauthorized();
        }

        $this->postJson(route('auth.login'), $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value)
            ->assertJsonStructure(['error' => ['params' => ['retryAfter']]]);
    }

    /**
     * Kapsam siniri: token gerektiren uclar hiz sinirina TAKILMAZ.
     *
     * T10 + T13: her istek gercek token yolundan gecer. Guard sifirlanmasaydi
     * ilk istekten sonraki yedisi token'a hic bakmazdi.
     */
    #[Test]
    public function authenticated_endpoints_are_not_throttled_by_the_auth_limiter(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        foreach (range(1, 8) as $ignored) {
            $this->forgetAuthState();
            $this->withToken($token)->getJson(route('auth.me'))->assertOk();
        }
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Ayşe',
            'lastName' => 'Yıldırım',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ], $overrides);
    }
}
