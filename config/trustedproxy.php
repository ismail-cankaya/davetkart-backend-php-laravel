<?php

declare(strict_types=1);

/**
 * Guvenilen ters vekiller (reverse proxy / yuk dengeleyici) — Faz 10, 10.18.
 *
 * Laravel'in global TrustProxies middleware'i bu anahtari HER ISTEKTE
 * kendisi okur (`config('trustedproxy.proxies')`); ek kod gerekmez,
 * config:cache altinda da calisir.
 *
 * Ayrintili aciklama: docs/rehber/config/trustedproxy.md
 */

return [

    // 🔴 VARSAYILAN BOS = hicbir vekile guvenilmez: $request->ip() baglantiyi
    // acan adresi doner, X-Forwarded-For yok sayilir.
    //
    // Yuk dengeleyici (ALB, CloudFront, Cloudflare) arkasinda bu bos kalirsa
    // ip() herkes icin DENGELEYICININ adresini doner: IP anahtarli butun hiz
    // siniri kovalari tek kovaya duser ve site dakikada 60 istekte kilitlenir.
    //
    // Deger: virgulle ayrilmis IP ya da CIDR ("10.0.0.0/8,172.16.0.0/12").
    // '*' HERKESE guvenmek demektir (0.0.0.0/0 ve ::/0). Yalnizca sunucuya
    // dogrudan erisim AG SEVIYESINDE kapaliysa guvenlidir; degilse sahte bir
    // X-Forwarded-For ile butun kovalar atlatilir.
    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
