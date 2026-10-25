<?php

declare(strict_types=1);

use App\Enums\RefundRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kullanıcının kullanılmamış bir hakkı iptal edip iade istemesi (Faz 14, 14.2 · K138).
 *
 * 🔴 İade PARASI burada gönderilmez: Shopier panelinden elle yapılır, sonra
 * `refunds:approve` siparişi `refunded` yapar. Bu tablo talebin kaydı.
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_26_110000_create_refund_requests_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Sipariş hiçbir zaman silinmez (muhasebe); talep de onunla kalır.
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();

            // Hesap silinince talep anonimleşir, sipariş gibi (K97).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default(RefundRequestStatus::Requested->value);

            // Kullanıcının isteğe bağlı açıklaması.
            $table->string('reason', 500)->nullable();

            // Yöneticinin notu (ret gerekçesi); kullanıcıya mailde gider.
            $table->string('admin_note', 500)->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        $statuses = "'".implode("', '", RefundRequestStatus::values())."'";
        $open = RefundRequestStatus::Requested->value;

        DB::statement("ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_status_check CHECK (status IN ({$statuses}))");

        // Açık talebin kararı yok; kapanmış talebin kararı VAR. İki kolon tek olguyu anlatır.
        DB::statement("ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_resolved_check CHECK ((status = '{$open}') = (resolved_at IS NULL))");

        // 🔴 Bir siparişte en fazla BİR açık talep. Eşzamanlı iki "iptal et"
        // tıklaması kısıta çarpar (E2: benzersizlik `if` ile değil kısıtla).
        // Kapanmış talepler serbest: reddedilen bir talep yeniden açılabilir.
        DB::statement("CREATE UNIQUE INDEX refund_requests_one_open_per_order ON refund_requests (order_id) WHERE status = '{$open}'");
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
