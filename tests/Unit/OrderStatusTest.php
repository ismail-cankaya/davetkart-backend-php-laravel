<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Siparis durum makinesinin TAM TABLOSU (Faz 10, dosya 10.3).
 *
 * 🔴 Feature testleri makinenin yalnizca birkac gecisini dener (webhook
 * tekrari, iade, gec odeme). Bu dosya 5 x 5 = 25 ciftin HEPSINI sabitler:
 * yarin biri "expired -> refunded da acik olsun" derse ya da bir kolu
 * yanlislikla genisletirse, hangi ciftin degistigi burada kirmizi yanar.
 *
 * OrderScopeTest'in ayni siniri: Laravel ayaga kalkmaz, veritabanina
 * dokunulmaz. Girdisi bir enum, ciktisi bir bool.
 * Ayrintili aciklama: docs/rehber/tests/Unit/OrderStatusTest.md
 */
final class OrderStatusTest extends TestCase
{
    /**
     * Mesru gecislerin TAMAMI. Listede olmayan her cift yasaktir.
     *
     * @var list<array{0: OrderStatus, 1: OrderStatus}>
     */
    private const ALLOWED = [
        [OrderStatus::Pending, OrderStatus::Paid],
        [OrderStatus::Pending, OrderStatus::Failed],
        [OrderStatus::Pending, OrderStatus::Expired],
        [OrderStatus::Expired, OrderStatus::Paid],
        [OrderStatus::Paid, OrderStatus::Refunded],
    ];

    /** 🔴 CHECK kisitinin okudugu liste (K39). Migration 10.4 bunu yeniden kurar. */
    #[Test]
    public function it_exposes_exactly_five_raw_values(): void
    {
        $this->assertSame(
            ['pending', 'paid', 'failed', 'expired', 'refunded'],
            OrderStatus::values(),
        );
    }

    /**
     * 25 ciftin hepsi. T6: izin VERILENLER kadar verilMEYENLER de sabitlenir —
     * yalnizca "expired -> paid acik mi" diye sormak, "expired -> refunded
     * da acildi" hatasini gormezdi.
     */
    #[Test]
    public function the_transition_table_is_exactly_the_documented_one(): void
    {
        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                $this->assertSame(
                    in_array([$from, $to], self::ALLOWED, true),
                    $from->canTransitionTo($to),
                    sprintf('%s -> %s', $from->value, $to->value),
                );
            }
        }
    }

    /** 🔴 Gec gelen odeme: bizim vazgecmemiz saglayicinin sozunu ezmez (K89). */
    #[Test]
    public function an_expired_order_can_still_become_paid_but_a_failed_one_cannot(): void
    {
        $this->assertTrue(OrderStatus::Expired->canTransitionTo(OrderStatus::Paid));
        $this->assertFalse(OrderStatus::Failed->canTransitionTo(OrderStatus::Paid));
    }

    /** Suresi dolmus siparis ne hak verir ne de odenmis sayilir (paid_at CHECK'i degismez). */
    #[Test]
    public function an_expired_order_grants_nothing_and_holds_no_money(): void
    {
        $this->assertFalse(OrderStatus::Expired->grantsPublishRight());
        $this->assertFalse(OrderStatus::Expired->hasBeenPaid());
        $this->assertSame(['paid', 'refunded'], OrderStatus::paidValues());
    }

    /**
     * isFinal() durum makinesinden TURETILIYOR: yalnizca gidecek yeri olmayanlar.
     *
     * `paid` de sonlu DEGIL (iade edilebilir). Eski govde (`!== Pending`)
     * hem `paid`'i hem `expired`'i sonlu sayardi.
     */
    #[Test]
    public function only_states_without_an_exit_are_final(): void
    {
        $final = array_values(array_filter(
            OrderStatus::cases(),
            static fn (OrderStatus $status): bool => $status->isFinal(),
        ));

        $this->assertSame([OrderStatus::Failed, OrderStatus::Refunded], $final);
    }
}
