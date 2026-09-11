<?php

declare(strict_types=1);

/**
 * Cross-Origin Resource Sharing (CORS).
 *
 * 🔴 Bu dosya Laravel 11+ ile GELMEZ ve elle yayinlandi. Varsayilani
 * (vendor/laravel/framework/config/cors.php) su:
 *
 *     'allowed_origins'  => ['*']      <- HERKES
 *     'exposed_headers'  => []         <- ETag OKUNAMAZ
 *
 * Ikisi de bizim icin yanlis ve ikincisi SESSIZ bir hatadir.
 *
 * Faz 9'a kadar hicbiri gorunmedi: vite.config.ts'teki proxy sayesinde
 * tarayici acisindan istekler AYNI kaynaktan geliyordu ve CORS hic devreye
 * girmedi. Uretimde proxy YOK.
 * Ayrintili aciklama: docs/rehber/config/cors.md
 */

return [

    // API ve Sanctum'un cerez ucu. Web rotasi yok (9.1'de silindi) ama
    // sanctum/csrf-cookie varsayilan listede duruyor: bugun kullanilmiyor,
    // yarin SPA moduna gecilirse gerekir ve varligi zararsiz.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * 🔴 Izin verilen kaynaklar. `['*']` DEGIL.
     *
     * `*` ile calisan bir API'yi herhangi bir sitedeki JavaScript cagirabilir.
     * Token'imiz Authorization basliginda gittigi icin klasik CSRF gecerli
     * degil — ama `*` yine de yanlis: bir saldirgan kendi sayfasindan bizim
     * API'mize istek atip YANITI OKUYABILIR. Kullanicinin token'ini calmasi
     * gerekir, evet; ama savunmayi "baska bir savunma tutuyor" diye acmak,
     * L1'in (katmanli savunma) tam tersidir.
     *
     * Liste .env'den gelir cunku ORTAMA gore degisir (E6 / Y1 ayrimı):
     * gelistirme localhost, uretim gercek alan adi. `release_window_days`
     * gibi TICARI bir sabit degil, ORTAM farkidir — env'in dogru kullanimi.
     */
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
     * 🔴 BU SATIR OLMADAN FAZ 4 VE FAZ 5 SESSIZCE OLUR.
     *
     * ETag, CORS'un "guvenli liste" basliklarindan DEGILDIR. Yani farkli bir
     * origin'den yapilan bir istekte tarayici, yanitta ETag gelse bile
     * JavaScript'in onu OKUMASINA izin vermez. axios `response.headers.etag`
     * icin undefined gorur, `If-None-Match` gonderemez ve:
     *
     *   - /api/public/invitations/{id}  -> her istekte TAM govde
     *   - LCV polling (15 saniyede bir) -> her poll TAM govde
     *
     * K7 (Polling + ETag) ve K46 (SetEtag middleware) tamamen islevsiz kalir.
     * Hicbir test bunu goremez: testler ayni surecte kosar, CORS yoktur.
     */
    'exposed_headers' => ['ETag'],

    // On kontrol (preflight) yanitinin tarayicida saklanma suresi (saniye).
    // Varsayilan 0 = her istekten once bir OPTIONS daha. 24 saat, LCV
    // polling'inin istek sayisini ikiye katlamasini onler.
    'max_age' => 60 * 60 * 24,

    /*
     * 🔴 false KALMALI.
     *
     * Sanctum'u TOKEN modunda kullaniyoruz (Bearer basligi, cerez yok).
     * `true` yapmak tarayiciya cerez/kimlik gondermesini soyler ve:
     *   - `allowed_origins` icinde `*` kullanmayi YASAKLAR (spec geregi),
     *   - kullanmadigimiz bir kimlik kanalini acar,
     *   - CSRF yuzeyini geri getirir.
     * Kullanilmayan bir yetenek, kapali bir yetenektir.
     */
    'supports_credentials' => false,

];
