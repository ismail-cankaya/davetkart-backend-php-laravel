<?php

declare(strict_types=1);

use App\Enums\ContactSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            // 🔴 ULID DEGIL bigint — K40'in ayirt edici sorusu: "bu kimlik
            // bir URL'de geciyor mu?" Gecmiyor. Form yazar, kimse okumaz
            // (bugun bir listeleme ucu yok). timeline_events ve
            // assistant_usages ayni sebeple bigint.
            $table->id();

            $table->string('name', 120);

            // 255: RFC 5321'in yerel+alan adi ust siniri. users.email ile
            // ayni genislik — ama BURADA UNIQUE YOK: ayni kisi birden fazla
            // kez yazabilir ve yazmali.
            $table->string('email', 255);

            // K49/K39: gosterim metni degil makine-okunur kod; CHECK asagida.
            $table->string('subject', 20);

            $table->text('message');

            // 🔴 KVKK: HAM IP SAKLANMAZ (CLAUDE.md §3). Ayni gonderenin
            // tekrar yazip yazmadigini anlamaya yeter, kimin yazdigini
            // soylemeye yetmez. hash_hmac('sha256') -> 64 karakter (IpHasher).
            $table->string('ip_hash', 64);

            $table->timestamps();

            // Destek ekibinin tek sorgu deseni: "en yeniden en eskiye".
            // Konu bazli filtre henuz bir uc DEGIL; indeks de bugun yok (E1
            // ailesi: ihtiyac dogmadan yapi uretilmez).
            $table->index('created_at');
        });

        // Gecerli konular ENUM'DAN gelir; elle yazilsaydi enum degistiginde
        // kisit sessizce eskirdi. Kaynak derleme zamani sabiti oldugu icin
        // string birlestirme burada guvenlidir — kullanici girdisi degil.
        $allowed = "'".implode("', '", ContactSubject::values())."'";

        DB::statement(
            "ALTER TABLE contact_messages
             ADD CONSTRAINT contact_messages_subject_check CHECK (subject IN ({$allowed}))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
