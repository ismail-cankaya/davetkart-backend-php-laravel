<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Her yanita tarayici sertlestirme basliklarini ekler.
 *
 * 🔴 Bu basliklarin hepsi TARAYICIYA verilen talimatlardir; sunucuda hicbir
 * sey zorlamazlar. Bir saldirgan curl ile istek atarsa hicbiri onu durdurmaz.
 * Korudukları sey KULLANICININ TARAYICISIDIR — yani ucuncu bir sitenin bizim
 * yanitimizi kotuye kullanmasi.
 *
 * Neden middleware, nginx degil? Ikisi de olur; middleware secildi cunku:
 *   - Basliklar kodla birlikte surumlenir (B10: kalite kapisinin bagimli
 *     oldugu her sey depoda durur). nginx.conf sunucuda yasar ve bir sunucu
 *     tasimasinda sessizce geride kalir.
 *   - Testle dogrulanabilir. HardeningTest her basligi sinar.
 *   - Paylasimli hostingde nginx'e erisim OLMAYABILIR (K80).
 * Ayrintili aciklama: docs/rehber/app/Http/Middleware/SecurityHeaders.md
 */
final class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Tarayici, Content-Type'i "tahmin etmeye" calismasin. Yuklenen bir
        // dosya image/jpeg diye servis edilirken icinde HTML varsa, sniffing
        // acik oldugunda tarayici onu SAYFA gibi calistirabilir (K55'in
        // "yuklenenler web kokunde" riskinin tarayici ayagi).
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Bu yanitlar hicbir <iframe> icinde gosterilemez — clickjacking.
        // Saf bir JSON API'de gomulmesi gereken hicbir sey yok.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Referer basligi capraz kaynakta HIC gonderilmesin. Davetiye URL'leri
        // ULID tasiyor (K13/K40) ve o ULID paylasilan LINKIN KENDISIDIR:
        // misafir davetiyeden bir dis baglantiya tiklarsa, referer ile
        // davetiye kimligi ucuncu tarafa sizardi.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // 🔴 Deger '0' ve bu bir yazim hatasi DEGIL. Eski XSS-Auditor'lar
        // kendileri acik dogurdugu icin modern tavsiye onu ACMAK degil
        // KAPATMAKTIR. Kalan tarayicilarda buggy davranisi devre disi birakir.
        $response->headers->set('X-XSS-Protection', '0');

        // Saf JSON API: hicbir kaynak yuklenmemeli, hicbir yere gomulmemeli.
        // 'none' burada gercekten dogru — bir HTML uygulamasinda bu satir
        // her seyi kirardi.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
        );

        // 🔴 HSTS YALNIZCA HTTPS uzerinden gonderilir.
        //
        // http uzerinden gonderilirse tarayicilar onu zaten yok sayar (spec),
        // ama kosul yine de yazildi: yerel gelistirmede (http://davetkart.test)
        // yanlislikla is gorurse tarayici o alan adini AYLARCA https'e zorlar
        // ve gelistirici "sitem acilmiyor" diye saatlerce arar. Bir baslik geri
        // alinamiyorsa, gonderilmesi bir KARAR olmalidir.
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
