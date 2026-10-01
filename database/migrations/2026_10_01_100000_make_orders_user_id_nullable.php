<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hesap silinince siparis SILINMEZ, anonimlesir (Faz 10, 10.38 · K97 / H-1).
 *
 * Faz 7'de `orders.user_id` `cascadeOnDelete` idi: kullanici silinince
 * odeme kayitlari da gidiyordu. Bu K82 ile celisiyordu (muhasebe kaydi bir
 * tiklamayla yok olamaz) ve `invitation_id`'nin kendi `nullOnDelete`'i ile
 * de: davetiye silinince kalan kayit, kullanici silinince kayboluyordu.
 *
 * Yeni kural: kullanici silinince `user_id` NULL olur. Satirda kisisel veri
 * kalmaz (ad, e-posta users tablosunda; siparis yalnizca plan, tutar, durum,
 * saglayici referansi tasir). "Bu odemeyi kim yapti?" sorusunun cevabi
 * kaybolur, "bu odeme yapildi mi?" sorusununki kalir.
 *
 * PostgreSQL'de yabanci anahtarin ON DELETE davranisi yerinde degistirilemez:
 * kisit dusurulur, kolon NULL kabul eder hale gelir, kisit yeniden kurulur.
 * Laravel PostgreSQL migration'larini tek transaction'da kosar.
 * Ayrintili aciklama: docs/rehber/database/migrations/2026_10_01_100000_make_orders_user_id_nullable.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // 🔴 Anonimlesmis siparis varsa geri alinamaz: NOT NULL kisiti onlari
        // reddeder ve satirlari silmek, bu migration'in korudugu muhasebe
        // kaydini yok etmek olurdu. Karar bir insana birakilir.
        $anonymized = DB::table('orders')->whereNull('user_id')->count();

        if ($anonymized > 0) {
            throw new RuntimeException(
                "{$anonymized} anonim siparis var (user_id NULL); geri alma bu kayitlari silmeyi gerektirir. Elle karar verilmeli.",
            );
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
