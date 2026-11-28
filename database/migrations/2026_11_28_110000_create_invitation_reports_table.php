<?php

declare(strict_types=1);

use App\Enums\InvitationReportReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 25 (K313): misafirin "bu davetiyeyi bildir" kayıtları. IP ham saklanmaz (KVKK, `IpHasher`).
 * Ayrıntı: docs/rehber/database/migrations/2026_11_28_110000_create_invitation_reports_table.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('invitation_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 16);
            $table->text('details')->nullable();
            $table->string('ip_hash', 64);
            $table->timestamps();

            $table->index(['invitation_id', 'created_at']);
        });

        $allowed = "'".implode("', '", InvitationReportReason::values())."'";
        DB::statement("ALTER TABLE invitation_reports ADD CONSTRAINT invitation_reports_reason_check CHECK (reason IN ({$allowed}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_reports');
    }
};
