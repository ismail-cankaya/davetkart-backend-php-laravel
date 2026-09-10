<?php

declare(strict_types=1);

use App\Enums\OrderScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `orders.scope` — bir siparisin NE SATIN ALDIGI.
 *
 * 🔴 Bu migration bir hatayi kapatiyor: Faz 7'de `invitation_id IS NULL`
 * "paket alimi" anlamina geliyordu, ama `nullOnDelete` yuzunden ayni NULL
 * "tekil siparisin davetiyesi silindi" anlamina da gelebiliyordu. Iki gercek
 * tek bicimde temsil edilince, silinen bir davetiyenin tekil siparisi
 * SESSIZCE pakete donusuyor ve hesap geneline yayin hakki veriyordu (N4).
 *
 * 🔴 GENISLET/DARALT (expand/contract) — kolon burada NULLABLE ekleniyor.
 * Uretimde bir kolonu tek adimda NOT NULL yapmak imkansizdir: migration
 * kostugu anda ESKI uygulama kodu hala calisiyordur ve o kod `scope`
 * yazmaz — her INSERT patlar. Sira uc adima bolunur:
 *   1. GENISLET  (bu dosya)  : nullable kolon + geri doldurma + kisitlar
 *   2. TASI      (A2.3)      : tum yazicilar scope yazmaya baslar
 *   3. DARALT    (A2.4)      : ayri migration SET NOT NULL yapar
 * Ayrintili aciklama: docs/rehber/database/migrations/2026_09_10_100000_add_scope_to_orders_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. GENISLET ───────────────────────────────────────────────────
        Schema::table('orders', function (Blueprint $table) {
            // after() BILEREK yok: MySQL'e ozgu bir modifier'dir ve PostgreSQL
            // grameri onu sessizce yok sayar. Sessizce hicbir sey yapan bir
            // cagri, yapmadigi seyi yaptigini sandirir. PostgreSQL'de kolon
            // sirasi zaten anlamsizdir — SELECT * kullanmiyoruz.
            //
            // DB varsayilani da YOK (E7/K70): bir siparisin kapsamini satin
            // alma yolu bilir ve acikca yazar. Varsayilan olsaydi, yazmayi
            // unutan bir yol sessizce ona duserdi — 'account' ise bedava
            // sinirsiz yayin, 'invitation' ise odenen paketin calismamasi.
            $table->string('scope', 16)->nullable();
        });

        // ── 2. GERI DOLDUR ────────────────────────────────────────────────
        // Mevcut satirlarda kapsam, bugunku (tek anlamli) kurala gore
        // TURETILEBILIR: bu satirlarin hicbiri henuz silinmis bir davetiyeden
        // gelmiyor, cunku kalici silme kod tabaninda HIC YOK (forceDelete()
        // cagiran tek bir satir bile aranip bulunamadi). Yani bugun NULL olan
        // her invitation_id gercekten paket alimidir.
        //
        // 🔴 Bu gerekce YARIN gecersiz olur — A2.5 kalici silmeyi getirdiginde
        // ayni sorgu yanlis cevap verirdi. Geri doldurma pencereye baglidir:
        // bir goc, kostugu ANIN dunyasini varsayar.
        DB::statement(
            'UPDATE orders SET scope = CASE WHEN invitation_id IS NULL THEN ? ELSE ? END',
            [OrderScope::Account->value, OrderScope::Invitation->value],
        );

        // ── 3. KISITLAR ───────────────────────────────────────────────────
        // Gecerli degerler enum'dan gelir; elle yazilsaydi enum degisince
        // kisit sessizce eskirdi (K39). Kaynak derleme zamani sabiti.
        $scopes = "'".implode("', '", OrderScope::values())."'";

        DB::statement(
            "ALTER TABLE orders
             ADD CONSTRAINT orders_scope_check CHECK (scope IN ({$scopes}))",
        );

        /*
         * 🔴 E11 — cok kolonlu degismez KISITA yazilir, `if`e degil.
         *
         * Bir PAKET siparisi hicbir davetiyeye bagli olamaz: 'account' kapsami
         * zaten hesap genelini kapsar, ustune bir davetiye kimligi tasimasi
         * anlamsizdir ve resolver'in iki kolunu birden eslestirirdi.
         *
         * Tersi SERBESTTIR ve bu dilimin butun amaci odur:
         *   scope='invitation' + invitation_id=NULL  =  serbest birakilmis
         *   tekil siparis. Hicbir davetiyeye hak vermez; sahibi onu yeni bir
         *   davetiyeye baglayana kadar bekler.
         */
        DB::statement(
            "ALTER TABLE orders
             ADD CONSTRAINT orders_account_scope_has_no_invitation_check
             CHECK (scope <> '".OrderScope::Account->value."' OR invitation_id IS NULL)",
        );

        /*
         * 🔴 IKI KISIT DA SU AN NULL'A IZIN VERIYOR — ve bu bir eksiklik degil,
         * SQL'in uc degerli mantiginin dogal sonucu:
         *
         *   NULL IN ('invitation','account')  ->  NULL  (TRUE degil, FALSE de degil)
         *   CHECK bir satiri YALNIZCA sonuc FALSE oldugunda reddeder.
         *
         * Yani "bilinmiyor" gecer. Kolonu gercekten zorunlu kilan sey CHECK
         * degil NOT NULL'dir ve o, yazicilar guncellendikten SONRA A2.4'te
         * eklenecek. Bu tuzak siktir: CHECK yazip "artik zorunlu" sanmak.
         */
    }

    public function down(): void
    {
        // Kisitlar once dusurulur: PostgreSQL kolonu, ona bagli CHECK'lerle
        // birlikte dusurmez.
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_account_scope_has_no_invitation_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_scope_check');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
