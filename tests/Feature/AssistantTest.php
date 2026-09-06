<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Exceptions\AiProviderException;
use App\Models\AssistantUsage;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\NullProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 8'in ilk kaniti: PARA HARCAYAN uc.
 *
 * 🔴 Bu dosyanin en onemli testleri YANITA DEGIL ETKIYE bakar (T14):
 *   - Kota, saglayici PATLASA BILE dusuluyor mu -> kaniti sayac kolonu
 *   - Dunku kullanim bugunu etkilemiyor mu      -> kaniti YENI satir
 *   - Anahtar URL'e sizmiyor mu                 -> kaniti giden istegin kendisi
 *   - Saglayicinin ham hatasi disari cikmiyor mu-> kaniti yanit govdesi
 *
 * T13: her ikinci kimlikli istekten once forgetAuthState().
 * Ayrintili aciklama: docs/rehber/tests/Feature/AssistantTest.md
 */
final class AssistantTest extends TestCase
{
    use RefreshDatabase;

    public const REPLY = 'Davetiye metniniz icin su basligi onerebilirim.';

    // ------------------------------------------------------------ ERISIM

    #[Test]
    public function the_assistant_requires_authentication(): void
    {
        $this->postJson(route('assistant.chat'), ['message' => 'merhaba'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    #[Test]
    public function an_authenticated_user_gets_a_reply(): void
    {
        $this->bindReplyingProvider();

        $this->ask(User::factory()->create(), 'Dugun daveti metni yaz')
            ->assertOk()
            ->assertJsonPath('data.reply', self::REPLY);
    }

    /** C2: zarf istisnasi ad ad tanimlidir; bu uc listede DEGIL. */
    #[Test]
    public function the_reply_is_wrapped_in_the_data_envelope(): void
    {
        $this->bindReplyingProvider();

        $this->ask(User::factory()->create(), 'merhaba')
            ->assertJsonStructure(['data' => ['reply']]);
    }

    // ------------------------------------------------------- DOGRULAMA

    #[Test]
    public function the_message_is_required(): void
    {
        $this->bindReplyingProvider();

        $this->ask(User::factory()->create(), null)
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value)
            ->assertJsonPath('error.fields.message.0.rule', 'required');
    }

    /** E6: ust sinir config'ten gelir, kodda sabit degildir. */
    #[Test]
    public function the_message_length_is_capped_by_configuration(): void
    {
        $this->bindReplyingProvider();
        Config::set('davetkart.assistant.max_prompt_chars', 10);

        $this->ask(User::factory()->create(), str_repeat('a', 11))
            ->assertStatus(422)
            ->assertJsonPath('error.fields.message.0.rule', 'max')
            ->assertJsonPath('error.fields.message.0.params.max', 10);
    }

    /**
     * 🔴 Dogrulama SAGLAYICIDAN ONCE calisir — yani gecersiz bir istek para
     * harcamaz. Kaniti sayacin ARTMAMIS olmasi (T14).
     */
    #[Test]
    public function an_invalid_request_never_reaches_the_provider(): void
    {
        $this->bindFailingProvider();
        $user = User::factory()->create();

        $this->ask($user, null)->assertStatus(422);

        $this->assertDatabaseCount('assistant_usages', 0);
    }

    // ------------------------------------------------------------- KOTA

    #[Test]
    public function the_daily_quota_is_enforced(): void
    {
        $this->bindReplyingProvider();
        Config::set('davetkart.assistant.daily_message_limit_per_user', 1);

        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->send($token, 'ilk')->assertOk();

        $this->forgetAuthState();

        $this->send($token, 'ikinci')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::AssistantQuotaExceeded->value);
    }

    /**
     * 🔴 429 ama RATE_LIMITED DEGIL. Iki kod da 429 doner; ayrimi yalnizca
     * `code` tasir ve frontend ikisine ayni metni gosteremez.
     */
    #[Test]
    public function the_quota_rejection_carries_the_limit_and_a_retry_hint(): void
    {
        $this->bindReplyingProvider();
        Config::set('davetkart.assistant.daily_message_limit_per_user', 3);

        $user = User::factory()->create();
        AssistantUsage::factory()->spent(3)->create(['user_id' => $user->id]);

        $response = $this->ask($user, 'merhaba')->assertStatus(429);

        $response->assertJsonPath('error.params.limit', 3);

        // Saniye degeri kostugu saate gore degisir; sabit bir sayi
        // beklemek testi saate baglardi (8.0'daki ayni ders). Sozlesmenin
        // vaadi "bir tam sayi doner" — dogrulanan bu.
        $this->assertIsInt($response->json('error.params.retryAfter'));
    }

    #[Test]
    public function the_quota_is_counted_per_user(): void
    {
        $this->bindReplyingProvider();
        Config::set('davetkart.assistant.daily_message_limit_per_user', 1);

        $spent = User::factory()->create();
        AssistantUsage::factory()->spent(1)->create(['user_id' => $spent->id]);

        $this->ask($spent, 'merhaba')->assertStatus(429);

        $this->forgetAuthState();

        // Baska bir kullanicinin butcesi dokunulmamis olmali.
        $this->ask(User::factory()->create(), 'merhaba')->assertOk();
    }

    /** Butce GUNLUK: dunku dolu sayac bugunu kilitlemez. */
    #[Test]
    public function yesterdays_usage_does_not_count_today(): void
    {
        $this->bindReplyingProvider();
        Config::set('davetkart.assistant.daily_message_limit_per_user', 1);

        $user = User::factory()->create();

        AssistantUsage::factory()
            ->on(CarbonImmutable::now()->subDay()->toDateString())
            ->spent(1)
            ->create(['user_id' => $user->id]);

        $this->ask($user, 'merhaba')->assertOk();

        // Bugun icin AYRI bir satir acildi — eskisi guncellenmedi.
        $this->assertDatabaseCount('assistant_usages', 2);
        $this->assertDatabaseHas('assistant_usages', [
            'user_id' => $user->id,
            'usage_date' => CarbonImmutable::now()->toDateString(),
            'message_count' => 1,
        ]);
    }

    /**
     * 🔴 FAZ 8'IN EN ONEMLI TESTI. Kota, saglayici PATLASA BILE dusulur.
     *
     * Gerekce: bir zaman asiminda Gemini istegi islemis ve faturalamis
     * olabilir. "Cevap alamadim" ile "para harcanmadi" ayni sey degildir;
     * supheli durumda kullanici bir mesaj kaybeder, sistem para kaybetmez.
     * Kaniti yanit degil KOLONDUR (T14).
     */
    #[Test]
    public function the_quota_is_charged_even_when_the_provider_fails(): void
    {
        $this->bindFailingProvider();
        $user = User::factory()->create();

        $this->ask($user, 'merhaba')->assertStatus(503);

        $this->assertDatabaseHas('assistant_usages', [
            'user_id' => $user->id,
            'message_count' => 1,
        ]);
    }

    // -------------------------------------------------------- SAGLAYICI

    #[Test]
    public function a_failing_provider_returns_service_unavailable(): void
    {
        $this->bindFailingProvider();

        $response = $this->ask(User::factory()->create(), 'merhaba')
            ->assertStatus(503)
            ->assertJsonPath('error.code', ErrorCode::ProviderUnavailable->value);

        $this->assertIsInt($response->json('error.params.retryAfter'));
    }

    /** H8: saglayicinin ham hatasi yanitta ASLA gorunmez. */
    #[Test]
    public function the_raw_provider_error_never_reaches_the_response(): void
    {
        $this->bindFailingProvider();

        $body = $this->ask(User::factory()->create(), 'merhaba')
            ->assertStatus(503)
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('did not return a usable reply', $body);
        $this->assertStringNotContainsString('stub', $body);
    }

    /** K70: bilinmeyen surucu SESSIZ bir varsayilana dusmez. */
    #[Test]
    public function an_unknown_driver_is_rejected(): void
    {
        Config::set('ai.default', 'openai');

        $this->ask(User::factory()->create(), 'merhaba')
            ->assertStatus(503)
            ->assertJsonPath('error.code', ErrorCode::ProviderUnavailable->value);
    }

    /** A8: surucu kendi degismezini KENDISI korur. */
    #[Test]
    public function the_gemini_driver_refuses_to_run_without_an_api_key(): void
    {
        Config::set('ai.default', 'gemini');
        Config::set('ai.providers.gemini.api_key', null);
        Http::fake();

        $this->ask(User::factory()->create(), 'merhaba')->assertStatus(503);

        // Anahtar yoksa AGA HIC CIKILMAZ.
        Http::assertNothingSent();
    }

    #[Test]
    public function the_null_provider_answers_without_touching_the_network(): void
    {
        Config::set('ai.default', 'null');
        Http::fake();

        $this->ask(User::factory()->create(), 'merhaba')
            ->assertOk()
            ->assertJsonPath('data.reply', NullProvider::REPLY);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------ GEMINI ISTEGI

    /**
     * 🔴 Sir BASLIKTA gider, URL'de DEGIL. URL'ler erisim loglarina, proxy
     * kayitlarina ve hata izlerine yazilir.
     */
    #[Test]
    public function the_api_key_travels_in_a_header_and_never_in_the_url(): void
    {
        $this->fakeGemini('Merhaba!');

        $this->ask(User::factory()->create(), 'merhaba')->assertOk();

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains($request->url(), 'test-key');
        });
    }

    /**
     * Sistem talimati AYRI alanda gider, kullanicinin mesajiyla
     * BIRLESTIRILMEZ: sinir metinde degil YAPIDA durur.
     */
    #[Test]
    public function the_system_instruction_is_sent_as_its_own_field(): void
    {
        $this->fakeGemini('Merhaba!');

        $this->ask(User::factory()->create(), 'dugun metni')->assertOk();

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return isset($body['systemInstruction'])
                && $body['contents'][0]['parts'][0]['text'] === 'dugun metni';
        });
    }

    /**
     * 🔴 200 gelmesi kullanilabilir cevap gelmesi demek DEGILDIR: guvenlik
     * filtresine takilan istek de 200 doner, `candidates` bostur.
     */
    #[Test]
    public function an_empty_gemini_candidate_is_treated_as_a_failure(): void
    {
        Config::set('ai.default', 'gemini');
        Config::set('ai.providers.gemini.api_key', 'test-key');
        Http::fake(['*' => Http::response(['candidates' => []])]);

        $this->ask(User::factory()->create(), 'merhaba')
            ->assertStatus(503)
            ->assertJsonPath('error.code', ErrorCode::ProviderUnavailable->value);
    }

    /** Saglayici 4xx dondugunde de disari yalnizca 503 cikar (H8). */
    #[Test]
    public function a_rejected_gemini_request_becomes_service_unavailable(): void
    {
        Config::set('ai.default', 'gemini');
        Config::set('ai.providers.gemini.api_key', 'test-key');
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

        $body = $this->ask(User::factory()->create(), 'merhaba')
            ->assertStatus(503)
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('API key not valid', $body);
    }

    // ------------------------------------------------------- HIZ SINIRI

    /**
     * 🔴 Hiz siniri ile kota AYRI seylerdir (L3) ve AYRI kod donerler.
     * Bu test ikisinin karismadigini kanitliyor: kota bol, limit dar.
     */
    #[Test]
    public function assistant_requests_are_rate_limited(): void
    {
        $this->bindReplyingProvider();
        Config::set('davetkart.assistant.rate_limit.per_user_per_minute', 2);
        Config::set('davetkart.assistant.daily_message_limit_per_user', 100);

        $token = $this->tokenFor(User::factory()->create());

        foreach (range(1, 2) as $ignored) {
            $this->send($token, 'merhaba')->assertOk();
            $this->forgetAuthState();
        }

        $this->send($token, 'merhaba')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);
    }

    // ---------------------------------------------------------- YARDIMCI

    /** Sabit bir yanit donduren sahte surucu. */
    private function bindReplyingProvider(): void
    {
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function name(): string
            {
                return 'stub';
            }

            public function reply(string $prompt): string
            {
                return AssistantTest::REPLY;
            }
        });
    }

    /** Her cagriyi reddeden sahte surucu — 503 yolunu sinar. */
    private function bindFailingProvider(): void
    {
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function name(): string
            {
                return 'stub';
            }

            public function reply(string $prompt): string
            {
                throw AiProviderException::unreachable('stub');
            }
        });
    }

    /** Gercek GeminiProvider'i sahte bir ag ile kosturur. */
    private function fakeGemini(string $text): void
    {
        Config::set('ai.default', 'gemini');
        Config::set('ai.providers.gemini.api_key', 'test-key');

        Http::fake(['*' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => $text]]]],
            ],
        ])]);
    }

    /**
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function ask(User $user, ?string $message): TestResponse
    {
        return $this->send($this->tokenFor($user), $message);
    }

    /**
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function send(string $token, ?string $message): TestResponse
    {
        // T10: token yolu withToken() ile sinanir; actingAs() guard'i atlar
        // ve yesil yanan bos bir test uretir.
        return $this->withToken($token)->postJson(
            route('assistant.chat'),
            $message === null ? [] : ['message' => $message],
        );
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
