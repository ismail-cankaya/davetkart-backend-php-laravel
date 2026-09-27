<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Odeme penceresi dolmus `pending` siparisleri `expired` isaretler.
 *
 * 🔴 Faz 10 (K89): `failed` DEGIL. `failed` saglayicinin kesin reddidir;
 * bu komut ise yalnizca BEKLEMEYI BIRAKIR. Faz 9'daki hali `failed` yaziyordu
 * ve o satira gec gelen imzali bir `paid` sessizce yutuluyordu (K-4). Artik
 * `expired -> paid` gecisi acik: para gec de gelse hak acilir.
 *
 * 🔴 `orders.expires_at` Faz 7'den beri YAZILIYOR ama HIC OKUNMUYORDU.
 * StartCheckoutAction her siparise bir son kullanma damgasi basiyor
 * (config: payment.order_expires_after_minutes) ve o damgayi kimse
 * sormuyordu. Ders 26'nin en somut hali: yazilip okunmayan bir kolon,
 * DOGRU OLDUGU VARSAYILAN bir kolondur — ve bu faza kadar ne dogrulugu
 * ne yanlisligi gorulebilirdi.
 *
 * Neden onemli? Odenmemis bir siparis sonsuza kadar 'pending' kalirsa:
 *   - Kullanicinin "siparislerim" ekrani (ilerideki) yillar oncesinden
 *     "odeme bekliyor" satirlari gosterir.
 *   - Terk edilmis odeme orani olculemez; hangi checkout'un gercekten
 *     basarisiz oldugu bilinemez.
 *   - `provider_ref` UNIQUE alani, hicbir zaman sonuclanmayacak satirlarla
 *     dolu kalir.
 * Ayrintili aciklama: docs/rehber/app/Console/Commands/ExpireStaleOrders.md
 */
final class ExpireStaleOrders extends Command
{
    protected $signature = 'orders:expire
                            {--dry-run : Yazma, yalnizca kac satir etkilenecegini soyle}';

    protected $description = 'Odeme penceresi dolmus bekleyen siparisleri expired isaretler';

    public function handle(): int
    {
        $query = $this->staleOrders();

        if ($this->option('dry-run') === true) {
            $this->components->info(sprintf(
                '%d siparis suresi dolmus gorunuyor (yazilmadi).',
                $query->count(),
            ));

            return self::SUCCESS;
        }

        // 🔴 TEK deyimde toplu UPDATE — satir satir donup save() etmiyoruz.
        // Iki sebep: (1) binlerce satirda N sorgu yerine 1 sorgu, (2) ve daha
        // onemlisi, dongu sirasinda bir webhook gelip siparisi 'paid' yapsaydi
        // dongu onu ESKI haliyle okumus olurdu. `where status = pending`
        // kosulu UPDATE'in KENDI icinde durdugu icin, veritabani o kosulu
        // yazma aninda dogrular: bu adimda 'paid' olan bir satir hicbir sekilde
        // 'expired' olamaz (E2 — kural `if` ile degil sorgunun kapsaminda).
        // Ters sira da guvenli: once bu komut, sonra webhook gelirse
        // `expired -> paid` gecisi mesrudur (K89).
        $affected = $query->update([
            'status' => OrderStatus::Expired->value,
            'updated_at' => now(),
        ]);

        $this->components->info(sprintf('%d siparis expired isaretlendi.', $affected));

        return self::SUCCESS;
    }

    /**
     * Suresi dolmus, HALA bekleyen siparisler.
     *
     * 🔴 `expires_at IS NULL` olanlar DISARIDA: N4 geregi "sure sinirsiz" ile
     * "sure dolmus" farkli bilgilerdir ve Order::isExpired() de tam olarak
     * bunu soyluyor. `whereDate` degil `where(..., '<', now())` — karsilastirma
     * bir AN uzerinde, bir takvim gunu uzerinde degil.
     *
     * @return Builder<Order>
     */
    private function staleOrders(): Builder
    {
        return Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }
}
