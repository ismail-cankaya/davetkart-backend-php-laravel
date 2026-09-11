<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `orders.scope` artik ZORUNLU — genislet/daralt deseninin 3. adimi.
 *
 * 🔴 Neden ayri bir migration?
 * A2.2 kolonu nullable ekledi cunku o an eski uygulama kodu hala calisiyordu
 * ve `scope` yazmiyordu. A2.3 butun yazicilari guncelledi. Ancak ikisi FARKLI
 * DEPLOY'larda kostugunda bu sira anlamlidir:
 *
 *   Deploy 1:  A2.2 (nullable kolon)  +  eski kod                -> calisir
 *   Deploy 2:  A2.3 (yazicilar)        +  yeni kod                -> calisir
 *   Deploy 3:  A2.4 (bu dosya)         -> artik NULL uretilmiyor  -> guvenli
 *
 * Uc adim ayni deploy'a sikistirilirsa desen bir TOREN olur: nullable kolon
 * hicbir sey korumaz, cunku korudugu pencere hic acilmaz.
 *
 * 🔴 Guvenlik agi ELLE YAZILMADI. Tabloda tek bir NULL kalmissa PostgreSQL
 * bu deyimi zaten reddeder:
 *     ERROR: column "scope" of relation "orders" contains null values
 * Ustune bir `if (Order::whereNull('scope')->exists()) throw ...` yazmak,
 * veritabaninin zaten verdigi garantiyi uygulama katmaninda TEKRARLAMAK
 * olurdu (E2: benzersizlik/zorunluluk `if` ile degil KISITLA kurulur). Ve
 * o `if` ile gercek kontrol arasinda bir yaris penceresi acilirdi.
 *
 * Migration'in burada PATLAMASI istenen davranistir: deploy durur, veri
 * bozulmaz. Sessizce gecmesi, NULL kapsamli bir siparisin yayin hakki
 * sorgusundan kacmasi demek olurdu.
 * Ayrintili aciklama: docs/rehber/database/migrations/2026_09_11_100000_make_orders_scope_not_null.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // ALTER COLUMN ... SET NOT NULL raw yazildi: Laravel'in ->change()
        // metodu kolonun TAMAMINI yeniden tanimlar (tip, uzunluk, varsayilan)
        // ve eksik yazilan her modifier sessizce DUSER. Tek bir ozelligi
        // degistirmek icin kolonun tamamini yeniden beyan etmek, dokunmadigin
        // seyleri de riske atmaktir.
        DB::statement('ALTER TABLE orders ALTER COLUMN scope SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders ALTER COLUMN scope DROP NOT NULL');
    }
};
