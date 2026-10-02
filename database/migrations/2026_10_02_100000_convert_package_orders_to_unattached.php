<?php

declare(strict_types=1);

use App\Enums\OrderScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eski paket siparislerini bagsiz tekil siparise cevirir (Faz 10, 10.58 · K99).
 *
 * Paket artik tek davetiyelik: ilk yayinda bir davetiyeye baglanir.
 * 'account' satirlarinin zaten davetiyesi yok (CHECK kisiti), yani cevrilen
 * satir bir sonraki yayinda ClaimReleasedOrderAction tarafindan baglanir.
 * Ayrintili aciklama: docs/rehber/database/migrations/2026_10_02_100000_convert_package_orders_to_unattached.md
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('scope', OrderScope::Account->value)
            ->update(['scope' => OrderScope::Invitation->value]);
    }

    /**
     * Geri alinamaz: hangi satirin eskiden paket oldugu artik bilinmiyor.
     * Sema degismedigi icin geri alma bilerek bos; satirlar oldugu gibi kalir.
     */
    public function down(): void
    {
        //
    }
};
