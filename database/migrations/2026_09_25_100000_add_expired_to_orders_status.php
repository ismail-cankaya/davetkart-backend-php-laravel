<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `orders.status` CHECK kisitina `expired` eklenir (Faz 10, K89).
 *
 * 🔴 Kisit ENUM'DAN yeniden kurulur, liste elle yazilmaz (K39). Bu yuzden
 * migration'in govdesinde 'expired' kelimesi hic gecmiyor: up() bugunku
 * enum'u okur, down() ondan yalnizca Expired'i cikarir.
 *
 * PostgreSQL'de bir CHECK kisiti yerinde DEGISTIRILEMEZ; dusurulur ve yeniden
 * yazilir. Laravel her PostgreSQL migration'ini bir transaction icinde kosar,
 * dolayisiyla "kisitsiz tablo" ani disaridan hic gorunmez.
 *
 * `orders_paid_at_check`'e DOKUNULMAZ: `expired` odenmis sayilmaz
 * (OrderStatus::hasBeenPaid() false), paid_at'i NULL kalir.
 * Ayrintili aciklama: docs/rehber/database/migrations/2026_09_25_100000_add_expired_to_orders_status.md
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuildStatusCheck(OrderStatus::values());
    }

    public function down(): void
    {
        // 🔴 Once VERI, sonra kisit. `expired` satirlar eski kisiti ihlal eder
        // ve ADD CONSTRAINT butun tabloyu dogruladigi icin geri alma patlar.
        // Faz 9'un dunyasinda bu satirlarin karsiligi `failed` idi: komut
        // tam olarak onu yaziyordu. Donusum KAYIPLIDIR — yeniden up()
        // kosulursa bu satirlar `expired`'a donmez.
        DB::table('orders')
            ->where('status', OrderStatus::Expired->value)
            ->update([
                'status' => OrderStatus::Failed->value,
                'updated_at' => now(),
            ]);

        $this->rebuildStatusCheck(array_values(array_diff(
            OrderStatus::values(),
            [OrderStatus::Expired->value],
        )));
    }

    /**
     * Kaynak derleme zamani sabiti (enum case'leri) — kullanici girdisi degil,
     * dolayisiyla string birlestirme burada guvenlidir (create_orders_table
     * ile ayni gerekce).
     *
     * @param  list<string>  $statuses
     */
    private function rebuildStatusCheck(array $statuses): void
    {
        $allowed = "'".implode("', '", $statuses)."'";

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');

        DB::statement(
            "ALTER TABLE orders
             ADD CONSTRAINT orders_status_check CHECK (status IN ({$allowed}))",
        );
    }
};
