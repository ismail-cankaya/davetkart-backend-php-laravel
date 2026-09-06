<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContactSubject;
use App\Enums\ErrorCode;
use App\Http\Requests\Contact\ContactRequest;
use App\Support\IpHasher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 8'in ikinci kaniti: sistemin DORDUNCU auth'suz yazma yolu.
 *
 * 🔴 Bu dosyanin en onemli testleri YANITA DEGIL ETKIYE bakar (T14):
 *   - Honeypot 204 doner  -> kaniti satirin YAZILMAMIS olmasi
 *   - KVKK                -> kaniti ham IP'nin veritabaninda BULUNMAMASI
 *   - CHECK kisiti        -> kaniti modeli ATLAYAN bir insert'in patlamasi
 * Ayrintili aciklama: docs/rehber/tests/Feature/ContactTest.md
 */
final class ContactTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------- MUTLU YOL

    /** Uc auth'suz: token gonderilmiyor ve gonderilmemeli. */
    #[Test]
    public function a_guest_can_send_a_contact_message(): void
    {
        $this->submit()->assertNoContent();

        $this->assertDatabaseHas('contact_messages', [
            'name' => 'Deniz Yilmaz',
            'email' => 'deniz@example.test',
            'subject' => ContactSubject::Pricing->value,
        ]);
    }

    /**
     * 204: govdede dondurulecek hicbir sey yok. `id` kimsenin isine yaramaz,
     * `ip_hash` sizinti olurdu (C1: beyaz listenin bos oldugu yerde Resource
     * da gereksizdir).
     */
    #[Test]
    public function the_response_carries_no_body(): void
    {
        $response = $this->submit()->assertNoContent();

        $this->assertSame('', $response->getContent());
    }

    // ---------------------------------------------------------- KVKK

    /**
     * 🔴 HAM IP HICBIR YERDE DURMAZ. Kaniti kolonun kendisi (T14): yanitta
     * zaten hicbir sey yok, dolayisiyla yanita bakmak bir sey kanitlamaz.
     */
    #[Test]
    public function the_raw_ip_is_never_stored(): void
    {
        $this->submit();

        $stored = DB::table('contact_messages')->value('ip_hash');

        $this->assertSame(IpHasher::hash('127.0.0.1'), $stored);
        $this->assertNotSame('127.0.0.1', $stored);
    }

    /** 8.7: iki refleks birlesti — depoda tek bir IP hash'leme yolu var. */
    #[Test]
    public function the_ip_hash_is_a_keyed_hmac(): void
    {
        $this->submit();

        $stored = DB::table('contact_messages')->value('ip_hash');

        $this->assertSame(
            hash_hmac('sha256', '127.0.0.1', Config::string('app.key')),
            $stored,
        );

        // Duz hash ile AYNI OLMAMALI: aksi halde 8.7 hicbir sey degistirmemis
        // olurdu ve bu test sessizce yesil yanardi (mutasyon dusuncesi, T16).
        $this->assertNotSame(
            hash('sha256', '127.0.0.1'.Config::string('app.key')),
            $stored,
        );
    }

    // ------------------------------------------------------- HONEYPOT

    /** L2: bota "yakalandin" DENMEZ — yanit gercek gonderimle ayni. */
    #[Test]
    public function a_honeypot_submission_looks_successful(): void
    {
        $this->submit([ContactRequest::HONEYPOT_FIELD => 'http://spam.example'])
            ->assertNoContent();
    }

    /** 🔴 Asil kanit burada: yanit ayni ama SATIR YAZILMADI (T14). */
    #[Test]
    public function a_honeypot_submission_is_not_persisted(): void
    {
        $this->submit([ContactRequest::HONEYPOT_FIELD => 'http://spam.example']);

        $this->assertDatabaseCount('contact_messages', 0);
    }

    /** Honeypot alani BOS gonderen durust istemci elenmez. */
    #[Test]
    public function an_empty_honeypot_field_is_not_a_trap(): void
    {
        // ConvertEmptyStringsToNull global middleware'i '' -> null yapar.
        $this->submit([ContactRequest::HONEYPOT_FIELD => ''])->assertNoContent();

        $this->assertDatabaseCount('contact_messages', 1);
    }

    // ------------------------------------------------------ DOGRULAMA

    #[Test]
    public function every_field_is_required(): void
    {
        $response = $this->postJson(route('public.contact.store'), [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);

        foreach (['name', 'email', 'subject', 'message'] as $field) {
            $response->assertJsonPath("error.fields.{$field}.0.rule", 'required');
        }
    }

    #[Test]
    public function an_invalid_email_is_rejected(): void
    {
        $this->submit(['email' => 'deniz(at)example'])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.email.0.rule', 'email');

        $this->assertDatabaseCount('contact_messages', 0);
    }

    /**
     * 🔴 D6: kural ADI sozlesmenin parcasidir. Rule::enum() kullanilsaydi
     * burada 'in' yerine 'illuminate_validation_rules_enum' gorurduk ve
     * framework adi hata zarfina sizardi.
     */
    #[Test]
    public function the_subject_must_be_a_known_value(): void
    {
        $this->submit(['subject' => 'sikayet'])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.subject.0.rule', 'in');
    }

    /**
     * 🔴 FRONTEND SOZLESMESI. services/contact.ts su tipi tasiyor:
     *   'general' | 'support' | 'pricing' | 'partnership' | 'kvkk'
     * Degerlerden biri degisirse form sessizce 422 almaya baslardi.
     */
    #[Test]
    public function the_subject_values_match_the_frontend_contract(): void
    {
        $this->assertSame(
            ['general', 'support', 'pricing', 'partnership', 'kvkk'],
            ContactSubject::values(),
        );
    }

    /** E6: ust sinir config'ten gelir. */
    #[Test]
    public function the_message_length_is_capped_by_configuration(): void
    {
        Config::set('davetkart.contact.max_message_chars', 20);

        $this->submit(['message' => str_repeat('a', 21)])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.message.0.rule', 'max')
            ->assertJsonPath('error.fields.message.0.params.max', 20);
    }

    // --------------------------------------------------------- SEMA

    /**
     * 🔴 E11/A8 ailesi: kural yalnizca dogrulama katmaninda durmuyor.
     * Dogrulama HTTP'ye aittir ve ATLANABILIR (konsol, kuyruk, seeder);
     * CHECK kisiti atlanamaz. Bu insert modeli bilerek atliyor.
     */
    #[Test]
    public function the_database_refuses_an_unknown_subject(): void
    {
        $this->expectException(QueryException::class);

        DB::table('contact_messages')->insert([
            'name' => 'Deniz',
            'email' => 'deniz@example.test',
            'subject' => 'sikayet',
            'message' => 'merhaba',
            'ip_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------- HIZ SINIRI

    /**
     * L1'in en ucuz katmani. Kova IP anahtarli: bu formun bir UST KAYNAGI
     * yok, dolayisiyla LCV'deki "davetiye basina" kovasinin karsiligi yok.
     */
    #[Test]
    public function contact_submissions_are_rate_limited(): void
    {
        Config::set('davetkart.contact.rate_limit.per_ip_per_minute', 2);

        foreach (range(1, 2) as $ignored) {
            $this->submit()->assertNoContent();
        }

        $this->submit()
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);
    }

    // ---------------------------------------------------------- YARDIMCI

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function submit(array $overrides = []): TestResponse
    {
        return $this->postJson(route('public.contact.store'), array_merge([
            'name' => 'Deniz Yilmaz',
            'email' => 'deniz@example.test',
            'subject' => ContactSubject::Pricing->value,
            'message' => 'Plan farklarini ogrenmek istiyorum.',
        ], $overrides));
    }
}
