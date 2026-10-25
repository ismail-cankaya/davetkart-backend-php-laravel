<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\PaymentMethod;
use App\Exceptions\PaymentMethodException;
use App\Services\Payment\PaymentMethods;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 13 (K134–K135): `PAYMENT_METHODS` satırının okunması.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/PaymentMethods.md
 */
final class PaymentMethodsTest extends TestCase
{
    /** Sağlama toplamı geçerli örnek TR IBAN (Iban testlerinde de kullanılan). */
    private const VALID_IBAN = 'TR330006100519786457841326';

    #[Test]
    public function only_the_listed_methods_are_enabled_in_a_fixed_order(): void
    {
        Config::set('payment.methods', ' gift_code , CARD ');

        $this->assertSame(
            [PaymentMethod::Card, PaymentMethod::GiftCode],
            $this->methods()->enabled(),
        );
    }

    #[Test]
    public function the_gift_code_only_setting_turns_the_card_off(): void
    {
        Config::set('payment.methods', 'gift_code');

        $this->assertFalse($this->methods()->isEnabled(PaymentMethod::Card));
        $this->assertTrue($this->methods()->isEnabled(PaymentMethod::GiftCode));
    }

    #[Test]
    public function an_empty_list_closes_every_method(): void
    {
        Config::set('payment.methods', '');

        $this->assertSame([], $this->methods()->enabled());
    }

    #[Test]
    public function an_unknown_word_is_a_configuration_error_not_a_silent_skip(): void
    {
        Config::set('payment.methods', 'card,gift_cod');

        try {
            $this->methods()->enabled();
            $this->fail('An unknown method must not be ignored.');
        } catch (PaymentMethodException $e) {
            $this->assertSame(ErrorCode::ProviderUnavailable, $e->errorCode());
            $this->assertSame([], $e->errorParams());
        }
    }

    #[Test]
    public function ensure_enabled_throws_the_disabled_code_with_the_method(): void
    {
        Config::set('payment.methods', 'gift_code');

        try {
            $this->methods()->ensureEnabled(PaymentMethod::Card);
            $this->fail('Card must be rejected.');
        } catch (PaymentMethodException $e) {
            $this->assertSame(ErrorCode::PaymentMethodDisabled, $e->errorCode());
            $this->assertSame(403, $e->errorCode()->status());
            $this->assertSame(['method' => 'card'], $e->errorParams());
        }
    }

    #[Test]
    public function bank_transfer_needs_a_complete_account_with_a_valid_iban(): void
    {
        Config::set('payment.methods', 'bank_transfer');
        $this->account('Ziraat', 'DavetKart', 'tr33 0006 1005 1978 6457 8413 26');

        $account = $this->methods()->bankAccount();

        $this->assertTrue($this->methods()->isEnabled(PaymentMethod::BankTransfer));
        $this->assertNotNull($account);
        $this->assertSame(self::VALID_IBAN, $account->iban);
        $this->assertSame('DavetKart', $account->accountHolder);
    }

    /** @return array<string, array{string, string, string}> */
    public static function brokenAccounts(): array
    {
        return [
            'no bank name' => ['', 'DavetKart', self::VALID_IBAN],
            'no holder' => ['Ziraat', '  ', self::VALID_IBAN],
            'no iban' => ['Ziraat', 'DavetKart', ''],
            'one digit off' => ['Ziraat', 'DavetKart', 'TR330006100519786457841327'],
        ];
    }

    #[Test]
    #[DataProvider('brokenAccounts')]
    public function listed_bank_transfer_stays_closed_without_a_usable_account(string $bank, string $holder, string $iban): void
    {
        Log::spy();
        Config::set('payment.methods', 'card,bank_transfer');
        $this->account($bank, $holder, $iban);

        $this->assertSame([PaymentMethod::Card], $this->methods()->enabled());
        $this->assertNull($this->methods()->bankAccount());
    }

    private function account(string $bank, string $holder, string $iban): void
    {
        Config::set('payment.bank_transfer.bank_name', $bank);
        Config::set('payment.bank_transfer.account_holder', $holder);
        Config::set('payment.bank_transfer.iban', $iban);
    }

    private function methods(): PaymentMethods
    {
        return $this->app->make(PaymentMethods::class);
    }
}
