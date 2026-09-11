<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * Projenin ILK birim testi (Faz 9, dosya 9.8).
 *
 * 🔴 `Tests\TestCase` DEGIL, dogrudan PHPUnit'in TestCase'i genisletiliyor —
 * ve bu bir ayrintinin degil bir SINIRIN ifadesi: bu testler Laravel
 * uygulamasini hic ayaga kaldirmaz, veritabanina hic dokunmaz, RefreshDatabase
 * kullanmaz. Kurulum maliyeti sifir oldugu icin de milisaniyeler surerler.
 *
 * Neden sekiz faz boyunca hic birim test yazilmadi? Cunku yazilacak SAF bir
 * sey yoktu: her kural ya HTTP sinirinda ya veritabani kisitinda yasiyordu ve
 * ikisi de Feature testi ister. `OrderScope` ilk istisna — girdisi bir enum
 * degeri, ciktisi bir bool; arada ne ag var ne disk.
 *
 * Ders 26'nin tersi: bos `Unit` suiti sekiz fazdir kullanilmiyordu ve
 * silinmeye adaydi. Silmek yerine DOLDURULDU, cunku tam da bu fazda onu
 * hak eden bir sinif dogdu.
 * Ayrintili aciklama: docs/rehber/tests/Unit/OrderScopeTest.md
 */
final class OrderScopeTest extends TestCase
{
    /** 🔴 CHECK kisitinin okudugu liste. Degeri degisirse migration eskir (K39). */
    #[Test]
    public function it_exposes_exactly_two_raw_values(): void
    {
        $this->assertSame(['invitation', 'account'], OrderScope::values());
    }

    /**
     * Hesap geneline hak veren TEK kapsam 'account'.
     *
     * Bu yuklem OrderEntitlementResolver'in paket kolunu besliyor;
     * `Order::scopeGrantingAcrossAccount()` yalnizca onu SQL'e ceviriyor.
     */
    #[Test]
    public function only_the_account_scope_grants_rights_across_the_account(): void
    {
        $this->assertTrue(OrderScope::Account->grantsAcrossAccount());
        $this->assertFalse(OrderScope::Invitation->grantsAcrossAccount());
    }

    /**
     * Serbest birakilabilen TEK kapsam 'invitation'.
     *
     * Paket zaten hicbir davetiyeye bagli degildir; koparilacak bir bagi da
     * yoktur. DeleteInvitationAction bu soruyu ilk sirada sorar (L1).
     */
    #[Test]
    public function only_the_invitation_scope_is_releasable(): void
    {
        $this->assertTrue(OrderScope::Invitation->isReleasable());
        $this->assertFalse(OrderScope::Account->isReleasable());
    }

    /**
     * 🔴 Iki yuklem BIRBIRININ DEGILI DEGIL — bugun oyle gorunseler bile.
     *
     * `isReleasable()`'i `! grantsAcrossAccount()` diye yazmak cazipti ve iki
     * degerli bir enumda ayni sonucu verirdi. Yazilmadi: ucuncu bir kapsam
     * (kampanya kodu) hem hesap genelinde gecerli hem de bir davetiyeye
     * baglanabilir olabilir. Iki AYRI soru, iki AYRI cevap.
     *
     * Bu test o ayrimin bilincli oldugunu belgeler; ucuncu case eklendigi gun
     * kirmizi yanarak "burayi da dusun" der (T6: bir davranisin hem varligi
     * hem yoklugu test edilir).
     */
    #[Test]
    public function the_two_predicates_answer_different_questions(): void
    {
        $this->assertCount(
            2,
            OrderScope::cases(),
            'Ucuncu bir kapsam eklendi: grantsAcrossAccount() ve isReleasable() '
            .'yuklemlerini AYRI AYRI gozden gecir; biri digerinin degili degildir.',
        );
    }

    /** Enum siniri tutuyor mu? Gecersiz deger uretilemez (sihirli string yasagi). */
    #[Test]
    public function an_unknown_value_cannot_be_constructed(): void
    {
        $this->expectException(ValueError::class);

        OrderScope::from('paket');
    }

    /** tryFrom() sessizdir — from()'un aksine istisna degil null doner. */
    #[Test]
    public function try_from_returns_null_for_an_unknown_value(): void
    {
        $this->assertNull(OrderScope::tryFrom('paket'));
    }
}
