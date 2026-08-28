<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Actions\Invitation\ResolvePublicInvitationAction;
use App\Contracts\RsvpQuotaResolver;
use App\Enums\RsvpStatus;
use App\Exceptions\RsvpDeadlinePassedException;
use App\Exceptions\RsvpQuotaExceededException;
use App\Models\Invitation;
use App\Models\Rsvp;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Misafirin LCV yanitini kabul eder — sistemin TEK auth'suz yazma yolu.
 *
 * 🔴 Katmanli savunma (defense in depth) burada uygulanir. Her katman tek
 * basina yeterli DEGILDIR; birlikte anlam kazanirlar:
 *
 *   0. Hiz siniri      -> rota katmani (5.8), bu Action'a hic gelmez
 *   1. Honeypot        -> bot sessizce yutulur, VERITABANINA HIC GIDILMEZ
 *   2. Gorunurluk      -> yayinda degilse / modul kapaliysa 404
 *   3. Son tarih       -> gectiyse 403
 *   4. Kota            -> dolduysa 403 (kilitli transaction icinde)
 *   5. KVKK            -> ham IP yerine hash
 *
 * Sira tesadufi degil: en ucuz kontrol en basta. Bot trafigi tek bir sorgu
 * bile actirmadan elenir.
 * Ayrintili aciklama: docs/rehber/app/Actions/Rsvp/SubmitRsvpAction.md
 */
final class SubmitRsvpAction
{
    public function __construct(
        private readonly ResolvePublicInvitationAction $resolveInvitation,
        private readonly RsvpQuotaResolver $quota,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  StoreRsvpRequest::rsvpAttributes()
     * @param  string  $ip  Ham IP — SAKLANMAZ, yalnizca hash'lenir
     * @param  bool  $honeypotTripped  Gorunmez alan dolduruldu mu (5.4)
     *
     * @throws ModelNotFoundException Davetiye yok / yayinda degil / LCV kapali -> 404
     * @throws RsvpDeadlinePassedException Son tarih gecti -> 403
     * @throws RsvpQuotaExceededException Kota doldu -> 403
     */
    public function handle(
        string $invitationId,
        array $attributes,
        string $ip,
        bool $honeypotTripped,
    ): Rsvp {
        // 1. KATMAN — 🔴 En basta, cunku en ucuzu ve en cok isleyeni.
        // Bot ne bir sorgu actirir ne bir satir yazar; yine de basarili
        // gorunen bir yanit alir (5.4: sessizlik bir savunmadir).
        if ($honeypotTripped) {
            return $this->silentlyDiscard($attributes);
        }

        // 2. KATMAN — gorunurluk. Yayinda olmayan davetiye sorgudan HIC cikmaz
        // (P3 ailesi); kural ResolvePublicInvitationAction'da TEK yerde durur.
        $invitation = $this->resolveInvitation->handle($invitationId);

        // Modul kapaliysa uc "yok" sayilir. Bos yanit degil 404: kapali modulun
        // varligi da bir bilgidir ve misafir onu zaten gormedi (C6).
        if (! $invitation->show_rsvp) {
            throw (new ModelNotFoundException)->setModel(Invitation::class, [$invitationId]);
        }

        // 3. KATMAN — zaman.
        $this->assertDeadlineNotPassed($invitation);

        $rsvp = $invitation->rsvps()->make($attributes);

        // 🔴 ip_hash #[Fillable] listesinde YOK: toplu atamayla degil, sunucu
        // kodu tarafindan atanir (E7'nin ayni gerekcesi).
        $rsvp->ip_hash = $this->hashIp($ip);

        // 4. KATMAN — kota. Kontrol ve yazma AYNI transaction icinde:
        // aralarinda baska bir istek araya girerse ikisi birden kotayi asardi.
        DB::transaction(function () use ($invitation, $rsvp): void {
            $this->assertQuotaAvailable($invitation, $rsvp->guest_count);

            $rsvp->save();
        });

        return $rsvp;
    }

    /**
     * Bot yanitini yutar ama KAYDETMEZ.
     *
     * Donen nesne gercek bir ULID ve zaman damgasi tasir, yani yanit gecerli
     * bir kayittan AYIRT EDILEMEZ — ama hicbir yere yazilmadi. HasUlids
     * kimligi veritabanina gitmeden uretebildigi icin bu mumkun.
     *
     * 🔴 Buradaki tek "kaydedilmedi" kaniti testtir (T14: yaniti degil ETKIYI
     * dogrula). Yanit 201 oldugu icin baska hicbir sey sana soylemez.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function silentlyDiscard(array $attributes): Rsvp
    {
        $rsvp = new Rsvp($attributes);

        $rsvp->id = $rsvp->newUniqueId();
        $rsvp->created_at = now();
        $rsvp->updated_at = now();

        return $rsvp;
    }

    /**
     * `rsvp_deadline` bir TARIHTIR (saat tasimaz) ve son gun DAHILDIR.
     *
     * 🔴 `$deadline->isPast()` YAZILAMAZ: tarih kolonu gunun 00:00'ina denk
     * gelir, dolayisiyla son gun boyunca isPast() true doner ve misafirler bir
     * gun erken kapida kalirdi. Karsilastirma gunun BASLANGICIYLA yapilir.
     */
    private function assertDeadlineNotPassed(Invitation $invitation): void
    {
        $deadline = $invitation->rsvp_deadline;

        // Son tarih belirtilmemisse sinir yok — sahip bilerek acik birakti.
        if ($deadline === null) {
            return;
        }

        if ($deadline->lessThan(now()->startOfDay())) {
            throw new RsvpDeadlinePassedException;
        }
    }

    /**
     * 🔴 Kota COUNT(*) ile DEGIL SUM(guest_count) ile olculur.
     *
     * COUNT(*) olsaydi 100 kayit x 4 kisi = 400 misafir kotayi asmadan gecerdi
     * (docs/09 §Faz 5). Frontend'in LiveRsvpPanel'i de ayni metrigi kullaniyor;
     * iki taraf ayni seyi saymak ZORUNDA.
     *
     * Hangi durumlarin sayildigini enum soyler (K50), bu sorgu degil.
     */
    private function assertQuotaAvailable(Invitation $invitation, int $incomingGuests): void
    {
        $limit = $this->quota->limitFor($invitation);

        // Sinirsiz plan: SORGU BILE ACILMAZ.
        if ($limit === null) {
            return;
        }

        // Ust kaydin satirini kilitle. Es zamanli iki gonderim ayni SUM'i okuyup
        // ikisi de "yer var" diyebilirdi (check-then-act yaris kosulu, Faz 2 E2).
        // PostgreSQL'in varsayilan READ COMMITTED seviyesinde SELECT'ler birbirini
        // beklemez; bu kilit onlari siraya sokar.
        Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

        $used = (int) $invitation->rsvps()
            ->whereIn('status', RsvpStatus::quotaConsumingValues())
            ->sum('guest_count');

        if ($used + $incomingGuests > $limit) {
            throw new RsvpQuotaExceededException;
        }
    }

    /**
     * KVKK veri minimizasyonu: ham IP asla saklanmaz (CLAUDE.md §3).
     *
     * APP_KEY bir "pepper"dir: yalnizca sha256(ip) yazsaydik saldirgan tum
     * IPv4 uzayinin hash'ini onceden hesaplayip tabloyu geri cozebilirdi.
     * Anahtar karisima girdiginde bu sozluk saldirisi imkansizlasir.
     */
    private function hashIp(string $ip): string
    {
        return hash('sha256', $ip.Config::string('app.key'));
    }
}
