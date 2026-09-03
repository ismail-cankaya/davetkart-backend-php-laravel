<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\SubscriptionTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            // K40/K52/K56 ailesi: kimlik yanitta doner (CheckoutResult.orderId)
            // ve yarin bir "siparislerim" ucunda URL'de gececek. Artan bigint
            // platformdaki toplam satis sayisini ele verirdi.
            $table->ulid('id')->primary();

            // Siparisi KIM aldi. Hesap silinirse siparis de gider (KVKK).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 🔴 K42: NULL = PAKET alimi (hesap geneli), dolu = TEKIL alim
            // (yalnizca o davetiye). Yayin hakki bu IKI kaynaktan sorulur ama
            // TEK arayuzden (PublishEntitlementResolver, 7.9).
            //
            // nullOnDelete, cascade DEGIL: davetiye silinince odeme kaydi
            // KAYBOLMAZ. Muhasebe kaydi kullanicinin bir tikiyla yok olamaz;
            // K60'in (rsvps medya FK'leri) ayni gerekcesi, daha agir hali.
            $table->foreignUlid('invitation_id')->nullable()->constrained()->nullOnDelete();

            // Satin alinan plan. CHECK asagida — degerler enum'dan gelir (K39).
            $table->string('tier', 16);

            $table->string('status', 16)->default(OrderStatus::default()->value);

            // 🔴 PARA KURUSTA (minor unit) SAKLANIR, TL'de degil.
            // 249.90 gibi bir fiyat float'ta 249.89999999999998 olur; toplam
            // alindiginda kurus kaybolur. Tam sayi aritmetigi bu sinifi hatayi
            // YAPISAL olarak imkansiz kilar. Sunum (249,90 TL) frontend'in isi.
            $table->unsignedInteger('amount_minor');

            // ISO 4217. Fiyat degisse de gecmis satisin para birimi sabittir —
            // config'ten okunsaydi bir gun EUR'ya gecince tum gecmis carpilirdi
            // (K54/F4'un para birimindeki hali: kolon GECMISI anlatir).
            $table->string('currency', 3);

            // Odemeyi hangi surucu isledi ('fake' | 'iyzico'). Yine F4: bugun
            // hangi saglayiciyla calistigimiz config'te, o siparisin hangisiyle
            // odendigi burada.
            $table->string('provider', 32);

            /*
             * 🔴 IDEMPOTANSIN VERITABANI YARISI.
             *
             * Saglayicinin o odemeye verdigi kimlik. UNIQUE oldugu icin ayni
             * odeme icin IKINCI BIR SATIR olusturmak veritabani seviyesinde
             * imkansizdir — uygulama kodundaki bir `if (already_processed)`
             * kontrolu es zamanli iki webhook'ta yaris kosuluna girer, kisit
             * girmez (E2: benzersizlik `if` ile degil kisitla kurulur).
             *
             * nullable: siparis, saglayici kimligi atanmadan ONCE de var
             * olabilir. PostgreSQL'de UNIQUE indeks birden cok NULL'a izin
             * verir — yani "henuz referansi olmayan" siparisler birbirini
             * engellemez. (MySQL'de de boyledir; SQL standardi bunu soyler.)
             *
             * ⚠️ Bu kisit "iki SATIR olamaz" der; "bir satir iki kez
             * ILERLEYEMEZ" demez. Onu OrderStatus::canTransitionTo() + satir
             * kilidi soyler (B6 — bkz. docs/rehber/app/Enums/OrderStatus.md §4).
             */
            $table->string('provider_ref', 191)->nullable()->unique();

            // Odemenin onaylandigi an. status='paid' ile birlikte damgalanir;
            // ikisi ayri ayri degil TEK gecis icinde yazilir (7.15).
            $table->timestamp('paid_at')->nullable();

            // Odenmemis siparisin son kullanma tarihi
            // (config/payment.php -> order_expires_after_minutes).
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // Tek sorgu deseni: "bu kullanicinin odenmis siparisleri"
            // (PublishEntitlementResolver'in paket kolu).
            $table->index(['user_id', 'status']);

            // Tekil kol: "bu davetiye icin odenmis siparis var mi?"
            $table->index(['invitation_id', 'status']);
        });

        // Gecerli degerler enum'dan gelir; elle yazilsaydi enum degisince kisit
        // sessizce eskirdi (K39). Kaynak derleme zamani sabiti — kullanici
        // girdisi degil, dolayisiyla string birlestirme burada guvenlidir.
        $tiers = "'".implode("', '", SubscriptionTier::values())."'";
        $statuses = "'".implode("', '", OrderStatus::values())."'";

        DB::statement(
            "ALTER TABLE orders
             ADD CONSTRAINT orders_tier_check CHECK (tier IN ({$tiers}))",
        );

        DB::statement(
            "ALTER TABLE orders
             ADD CONSTRAINT orders_status_check CHECK (status IN ({$statuses}))",
        );

        // 🔴 PostgreSQL'de UNSIGNED YOKTUR (Faz 5: rsvps.guest_count, Faz 6:
        // media.size_bytes). unsignedInteger duz 'integer'a duser ve -100 kabul
        // eder. Bedava plan yok (docs/09) — sifir tutarli siparis de anlamsiz.
        DB::statement(
            'ALTER TABLE orders
             ADD CONSTRAINT orders_amount_minor_check CHECK (amount_minor > 0)',
        );

        /*
         * 🔴 Parasi alinmis siparis ZAMAN DAMGASI TASIMAK ZORUNDA — ve
         * alinmamis siparis TASIYAMAZ.
         *
         * "status='paid' ama paid_at NULL" mumkun olsaydi muhasebe raporu o
         * satiri sessizce atlardi; tersi de mumkun olsaydi odenmemis bir
         * siparis odenmis gibi raporlanirdi. Kisit iki kolonu birbirine baglar:
         * E2'nin "kural `if` ile degil kisitla kurulur" ilkesinin cok-kolonlu
         * hali.
         *
         * 'refunded' de listede: iade edilmis siparis bir zamanlar odendi ve
         * damgasi silinmez. Liste OrderStatus::paidValues()'tan TURETILIR.
         */
        $paidStates = "'".implode("', '", OrderStatus::paidValues())."'";

        DB::statement(
            "ALTER TABLE orders
             ADD CONSTRAINT orders_paid_at_check
             CHECK ((status IN ({$paidStates})) = (paid_at IS NOT NULL))",
        );
    }

    public function down(): void
    {
        // Kisitlar tabloya bagli oldugu icin tabloyla birlikte dusser.
        Schema::dropIfExists('orders');
    }
};
