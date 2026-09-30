<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Models\User;
use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Bozuk girdi kapisi ve 429 basligi, BUTUN rota gruplarinda (Faz 10, 10.20).
 *
 * ef7c692 kapiyi (RejectMalformedInput) 'api' grubuna tek bir middleware
 * olarak koydu ve RsvpTest onu YALNIZCA LCV ucunda sinadi. Kapinin varlik
 * sebebi "yeni bir uc eklendiginde kimse bir kurali hatirlamak zorunda
 * kalmasin" idi; bunun kaniti ancak her grupta sinamakla olur.
 *
 * Denetim bulgulari: K-6 (yarim JSON -> 422 yerine 400), K-2 (NUL bayti),
 * K-5 (429'da Retry-After BASLIGI yoktu, yalnizca govdede vardi).
 * Ayrintili aciklama: docs/rehber/tests/Feature/MalformedInputTest.md
 */
final class MalformedInputTest extends TestCase
{
    use RefreshDatabase;

    /** Var olmayan bir davetiye: kapi uca ulasilmadan calistigi icin 404 degil 400 beklenir. */
    private const YOK_OLAN_ULID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // ------------------------------------------------------ BOZUK GIRDI KAPISI

    /**
     * Her rota grubundan bir uc. `true` = kimlik ister: kapi Authenticate'ten
     * SONRA calisir (oncelik listesi), token olmasa 400'den once 401 gelirdi.
     *
     * @return iterable<string, array{string, array<string, string>, bool}>
     */
    public static function endpoints(): iterable
    {
        yield 'auth: giris' => ['auth.login', [], false];
        yield 'davetiye: olusturma' => ['invitations.store', [], true];
        yield 'public: LCV (var olmayan davetiye)' => ['public.invitations.rsvps.store', ['invitation' => self::YOK_OLAN_ULID], false];
        yield 'public: iletisim' => ['public.contact.store', [], false];
        yield 'asistan' => ['assistant.chat', [], true];
        yield 'odeme bildirimi (webhook)' => ['public.payments.webhook', [], false];
    }

    /**
     * K-6: Laravel cozulemeyen JSON'u sessizce BOS govde sayar ve 422
     * "alan zorunlu" der — oysa istemci gonderdi, govde yolda kesildi.
     *
     * @param  array<string, string>  $parameters
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function a_truncated_json_body_is_malformed_on_every_route(string $route, array $parameters, bool $needsToken): void
    {
        $response = $this->call(
            'POST',
            route($route, $parameters),
            server: $this->transformHeadersToServerVars([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                ...$this->authHeader($needsToken),
            ]),
            content: '{"message": "Merhaba, düğün saat kaçta başl',
        );

        $this->assertMalformed($response);
    }

    /**
     * K-2: PostgreSQL metni ilk \0'da keser — dogrulama tam dizeyi, yazma
     * kesik dizeyi gorur. jsonb ise \u0000'i hic kabul etmez (500).
     *
     * @param  array<string, string>  $parameters
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function a_nul_byte_is_malformed_on_every_route(string $route, array $parameters, bool $needsToken): void
    {
        $response = $this->withHeaders($this->authHeader($needsToken))
            ->postJson(route($route, $parameters), [
                'email' => "ayse\u{0000}@ornek.test",
                'message' => "Tebrikler\u{0000}!",
            ]);

        $this->assertMalformed($response);
    }

    /** Anahtarda NUL da bozuktur: jsonb kolonlari onu da reddeder. */
    #[Test]
    public function a_nul_byte_in_a_key_is_malformed_too(): void
    {
        $this->withToken($this->tokenFor(User::factory()->create()))
            ->postJson(route('invitations.store'), ['invitation' => ["ti\u{0000}tle" => 'Düğün']])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ErrorCode::MalformedRequest->value);
    }

    /**
     * 🔴 Kapi rota model baglamasindan ONCE calisir (bootstrap/app.php:
     * prependToPriorityList): bozuk istek davetiye tablosuna HIC sorgu
     * actirmaz, var olmayan davetiye 404 degil 400 alir.
     *
     * Uc bilerek `invitations.update`: ORTUK baglama yapan (Invitation
     * $invitation) bir uc. Public LCV ucu kimligi string alip Action'da
     * cozuyor; orada bu iddia hicbir sey kanitlamazdi (10.20'de denendi:
     * oncelik satiri silinince LCV vakasi yesil kaldi).
     *
     * Sorgu gunlugu BOS degil: kapi Authenticate'ten sonra calisir, token
     * sorgulari beklenen. Iddia yalnizca `invitations` tablosu hakkinda.
     */
    #[Test]
    public function a_malformed_request_is_rejected_before_route_model_binding(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        DB::enableQueryLog();

        $this->withToken($token)
            ->putJson(route('invitations.update', self::YOK_OLAN_ULID), ['invitation' => ['title' => "Dü\u{0000}ğün"]])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ErrorCode::MalformedRequest->value);

        $touchedInvitations = array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => str_contains($entry['query'], 'invitations'),
        );

        $this->assertSame([], $touchedInvitations);
    }

    /** T6'nin varlik yarisi: saglam istek kapiyi gecer (burada 422: bos form). */
    #[Test]
    public function a_well_formed_request_passes_the_gate(): void
    {
        $this->postJson(route('public.contact.store'), ['name' => 'Şeyma Şen'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);
    }

    // -------------------------------------------------- 429: RETRY-AFTER BASLIGI

    /**
     * K-5 / K93: docs/08 §4.1 "429 yaniti Retry-After BASLIGINI tasir" diyor;
     * ef7c692'ye kadar yalnizca govdede `params.retryAfter` vardi. RsvpTest
     * LCV ucunu sinadi; burada kalan uc sinir.
     *
     * Zaman donduruluyor: kova dakikalik, yani baslik tam 60 olmali.
     * Istekler bilerek GECERSIZ (422): ThrottleRequests sayaci istek
     * controller'a ulasmadan artirir; saglayici ya da gercek mesaj gerekmez.
     */
    #[Test]
    public function the_auth_limiter_sends_a_retry_after_header(): void
    {
        $this->freezeSecond();

        foreach (range(1, 5) as $ignored) {
            $this->postJson(route('auth.login'), [])->assertUnprocessable();
        }

        $this->assertRetryAfter($this->postJson(route('auth.login'), []), ErrorCode::RateLimited, 60);
    }

    #[Test]
    public function the_contact_limiter_sends_a_retry_after_header(): void
    {
        $this->freezeSecond();
        Config::set('davetkart.contact.rate_limit.per_ip_per_minute', 1);

        $this->postJson(route('public.contact.store'), [])->assertUnprocessable();

        $this->assertRetryAfter($this->postJson(route('public.contact.store'), []), ErrorCode::RateLimited, 60);
    }

    #[Test]
    public function the_assistant_limiter_sends_a_retry_after_header(): void
    {
        $this->freezeSecond();
        Config::set('davetkart.assistant.rate_limit.per_user_per_minute', 1);
        $token = $this->tokenFor(User::factory()->create());

        $this->withToken($token)->postJson(route('assistant.chat'), [])->assertUnprocessable();
        $this->forgetAuthState();

        $this->assertRetryAfter(
            $this->withToken($token)->postJson(route('assistant.chat'), []),
            ErrorCode::RateLimited,
            60,
        );
    }

    /**
     * Ikinci bir 429 turu: middleware degil IS kurali (gunluk kota). Baslik
     * bu kez exception'in errorParams()'indan turuyor; ayni kapi
     * (ApiExceptionRenderer::headers) ikisini de tasiyor mu?
     *
     * Saniye degeri gun sinirina bagli (plan 10.57 onu degistirecek); bu
     * yuzden tam deger degil, BASLIK = GOVDE esitligi iddia ediliyor.
     */
    #[Test]
    public function the_assistant_daily_quota_sends_a_retry_after_header(): void
    {
        Config::set('davetkart.assistant.daily_message_limit_per_user', 0);
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function name(): string
            {
                return 'stub';
            }

            public function reply(string $prompt): string
            {
                return 'cevap';
            }
        });

        $response = $this->withToken($this->tokenFor(User::factory()->create()))
            ->postJson(route('assistant.chat'), ['message' => 'Davetiyeme ne yazayım?'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::AssistantQuotaExceeded->value);

        $seconds = $response->json('error.params.retryAfter');

        $this->assertIsInt($seconds);
        $this->assertGreaterThan(0, $seconds);
        $response->assertHeader('Retry-After', (string) $seconds);
    }

    // ------------------------------------------------------------ YARDIMCILAR

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertMalformed(TestResponse $response): void
    {
        $response->assertStatus(400)
            ->assertJsonPath('error.code', ErrorCode::MalformedRequest->value)
            ->assertJsonMissingPath('error.fields');
    }

    /**
     * Baslik VE govde, ayni deger. Baslik govdeden turetiliyor (tek kaynak);
     * ikisi ayrisirsa istemci hangisine inanacagini bilemez.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertRetryAfter(TestResponse $response, ErrorCode $code, int $seconds): void
    {
        $response->assertStatus(429)
            ->assertJsonPath('error.code', $code->value)
            ->assertJsonPath('error.params.retryAfter', $seconds)
            ->assertHeader('Retry-After', (string) $seconds);
    }

    /**
     * @return array<string, string>
     */
    private function authHeader(bool $needsToken): array
    {
        if (! $needsToken) {
            return [];
        }

        return ['Authorization' => 'Bearer '.$this->tokenFor(User::factory()->create())];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }
}
