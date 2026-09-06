<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Exceptions\AiProviderException;
use App\Exceptions\AssistantQuotaExceededException;
use App\Models\AssistantUsage;
use App\Models\User;
use App\Services\Ai\AiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Kullanicinin mesajini AI saglayicisina vekaleten iletir.
 *
 * 🔴 Katmanli savunma (L1) — en ucuzdan pahaliya, ve buradaki "pahali"
 * kelimesi mecaz degil: son katman PARA HARCAR.
 *
 *   0. auth:sanctum        -> rota katmani; kimliksiz istek buraya HIC gelmez
 *   1. throttle:assistant  -> rota katmani; cache'te bir sayac (mikrosaniye)
 *   2. Uzunluk siniri      -> AskAssistantRequest; tek strlen (mikrosaniye)
 *   3. GUNLUK KOTA         -> burada; iki SQL deyimi (milisaniye)
 *   4. Saglayici cagrisi   -> burada; ag + para (saniyeler)
 *
 * Sira tesadufi degil: her katman kendinden sonrakinin maliyetini haklı
 * cikaracak kadar ucuz olmali.
 * Ayrintili aciklama: docs/rehber/app/Actions/Assistant/AskAssistantAction.md
 */
final class AskAssistantAction
{
    public function __construct(
        // 🔴 Somut surucu DEGIL arayuz (D — Dependency Inversion). Bu Action
        // Gemini'yi hic duymamistir; testte NullProvider baglanir ve tek bir
        // satiri degismez (K8, ders 52).
        private readonly AiProvider $provider,
    ) {}

    /**
     * @param  string  $prompt  AskAssistantRequest::prompt() — uzunlugu
     *                          dogrulanmis, icerigi DOGRULANMAMIS metin
     *
     * @throws AssistantQuotaExceededException Gunluk butce doldu -> 429
     * @throws AiProviderException Saglayici yapilandirilmamis/erisilemiyor -> 503
     */
    public function handle(User $user, string $prompt): string
    {
        // 🔴 SIRA: once kota DUSULUR, sonra cagri yapilir.
        //
        // Tersi daha "adil" gorunurdu (cagri basarisizsa hak yanmasin) ama
        // yanlis tarafa hata yapardi: cagri yapildiktan SONRA sayaci
        // artirirken bir hata olursa, saglayici ZATEN FATURALAMIS olur ve biz
        // bunu hic saymamis oluruz. Bir zaman asiminda dahi Gemini istegi
        // islemis olabilir — "cevap alamadim" ile "para harcanmadi" ayni sey
        // DEGILDIR.
        //
        // Bu, L7'nin ("dis servis transaction'a dahil degildir, geri
        // alinamayan is en sona") kota eksenindeki tamamlayicisidir: geri
        // alinamayan is en sonda, ama onun BEDELI en basta yazilir.
        // Fail-safe yon: supheli durumda kullanici bir mesaj kaybeder,
        // sistem para kaybetmez (B6: kilavuz §6 bu bedeli acikca yaziyor).
        $this->chargeDailyQuota($user);

        return $this->provider->reply($prompt);
    }

    /**
     * Gunluk sayaci bir artirir; butce doluysa exception firlatir.
     *
     * 🔴 CHECK-THEN-ACT YOK. "Once oku, sonra karsilastir, sonra yaz" uc
     * ayri adim olsaydi es zamanli iki istek ayni degeri okur ve ikisi de
     * "yer var" derdi (Faz 2'nin E2'si, Faz 5'in kota yarisi). Cozum orada
     * satir kilidiydi; burada kilide bile gerek yok, cunku kontrol ve yazma
     * TEK BIR SQL DEYIMINDE birlesiyor:
     *
     *     UPDATE assistant_usages SET message_count = message_count + 1
     *      WHERE user_id = ? AND usage_date = ? AND message_count < ?
     *
     * Veritabani bu deyimi atomik uygular. Kota doluysa WHERE hicbir satira
     * uymaz ve etkilenen satir sayisi 0 doner — reddin kaniti budur.
     */
    private function chargeDailyQuota(User $user): void
    {
        $limit = Config::integer('davetkart.assistant.daily_message_limit_per_user');
        $today = $this->today();

        // 1. Satiri VAR ET. insertOrIgnore: satir zaten varsa hicbir sey
        // yapmaz ve UNIQUE(user_id, usage_date) ihlali HATA FIRLATMAZ. Iki
        // es zamanli ilk mesajdan biri sessizce eler (E2: benzersizlik `if`
        // ile degil veritabani kisitiyla korunur).
        AssistantUsage::query()->insertOrIgnore([
            'user_id' => $user->id,
            'usage_date' => $today,
            'message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Kosullu artirim. Eloquent'in increment()'i updated_at'i da
        // gunceller ve ETKILENEN SATIR SAYISINI doner.
        $charged = AssistantUsage::query()
            ->where('user_id', $user->id)
            ->where('usage_date', $today)
            ->where('message_count', '<', $limit)
            ->increment('message_count');

        if ($charged === 0) {
            throw new AssistantQuotaExceededException($this->secondsUntilReset(), $limit);
        }
    }

    /**
     * Kotanin gunu — UYGULAMANIN saat diliminde (config/app.php: UTC).
     *
     * 🔴 B6 — bunun kapatMADIGI sey: Istanbul'daki bir kullanici icin kota
     * gece yarisi degil SABAH 03:00'te yenilenir. Davetiyenin saat dilimini
     * (K71) burada KULLANMIYORUZ, cunku o alan bir ETKINLIGIN yerel saatini
     * anlatir; asistan sohbetinin bir etkinligi yok. Kullanicinin kendi
     * saat dilimi ise hicbir yerde saklanmiyor (users tablosunda boyle bir
     * kolon yok). Ihtiyac dogarsa dogru cozum o kolonu eklemektir; bugun
     * eklemek, hicbir yerden okunmayan bir alan uretirdi (ders 26).
     */
    private function today(): string
    {
        return CarbonImmutable::now()->toDateString();
    }

    /**
     * Butcenin yenilenmesine kalan saniye — 429'un Retry-After degeri.
     *
     * Carbon'un diff metotlari surum ve `absolute` varsayilanina gore float
     * dondurebilir; iki zaman damgasinin cikarilmasi belirsizlik birakmayan
     * tam sayidir (ders 18: aracin davranisini tahmin etme, kesin olani yaz).
     */
    private function secondsUntilReset(): int
    {
        $now = CarbonImmutable::now();

        return max(1, $now->addDay()->startOfDay()->getTimestamp() - $now->getTimestamp());
    }
}
