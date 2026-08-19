<?php

declare(strict_types=1);

use App\Enums\InvitationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            // K40: id hem dahili kimlik hem paylasilan link. ULID tahmin edilemez
            // ama zaman sirali — UUIDv4'un indeks parcalanmasini yasatmaz.
            $table->ulid('id')->primary();

            // Hesap silinirse davetiyeleri de gider (KVKK: unutulma hakki).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // K38/K39: durum makinesi backend'in malidir — CHECK ile korunur.
            $table->string('status', 16)->default(InvitationStatus::default()->value);

            // Frontend katalogunun anahtarlari — kisitlanmaz, bkz. kilavuz §4.
            $table->string('category_id', 32);
            $table->string('preset_id', 48);
            $table->string('palette', 16);

            // Icerik alanlari: autosave yarim veriyi de kaydeder, hepsi nullable.
            // Eksiksizlik kurali yayin aninda uygulanir (Faz 7), kayit aninda degil.
            $table->string('title', 120)->nullable();
            $table->string('subtitle', 255)->nullable();
            $table->string('names', 120)->nullable();
            $table->string('venue', 180)->nullable();
            $table->text('map_url')->nullable();
            $table->timestamp('event_at')->nullable();

            // K6: paywall SQL ile dogrulanabilsin diye JSON degil ayri kolonlar.
            $table->boolean('show_envelope')->default(false);
            $table->boolean('show_timer')->default(false);
            $table->boolean('show_timeline')->default(false);
            $table->boolean('show_gallery')->default(false);
            $table->boolean('show_gift')->default(false);
            $table->boolean('show_rsvp')->default(false);

            $table->string('bank_name', 80)->nullable();
            $table->string('account_holder', 120)->nullable();
            $table->string('iban', 34)->nullable();          // ISO 13616 ust siniri
            $table->jsonb('gift_options')->nullable();       // sorgulanmayacak kucuk dizi

            $table->date('rsvp_deadline')->nullable();
            $table->boolean('ask_menu_preference')->default(false);

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Dashboard sorgusu: WHERE user_id = ? [AND status = ?]
            $table->index(['user_id', 'status']);
        });

        // Gecerli degerler enum'dan gelir; elle yazilmaz ki enum degisince
        // kisit sessizce eskimesin. Kaynak derleme zamani sabiti oldugu icin
        // burada string birlestirme guvenlidir — kullanici girdisi degildir.
        $allowed = "'".implode("', '", InvitationStatus::values())."'";

        DB::statement(
            "ALTER TABLE invitations
             ADD CONSTRAINT invitations_status_check CHECK (status IN ({$allowed}))",
        );
    }

    public function down(): void
    {
        // Kisit tabloya bagli oldugu icin tabloyla birlikte dusser.
        Schema::dropIfExists('invitations');
    }
};
