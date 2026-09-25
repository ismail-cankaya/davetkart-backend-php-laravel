<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Bicimsel olarak bozuk istegi DOGRULAMAYA ULASMADAN reddeder: 400 MALFORMED_REQUEST.
 *
 * Iki bozukluk, tek kapi (RsvpTest kilavuzu §4, D-1 secenek A):
 *   1) Cozulemeyen JSON govdesi. Laravel onu sessizce BOS govde sayar ve
 *      istemci "adi gondermedin" diye 422 alir — oysa gonderdi, govde yolda
 *      kesildi (08 §4: bicimsel bozukluk 400'dur).
 *   2) NUL bayti (\0). PostgreSQL metni ilk \0'da KESER: dogrulama PHP'de tam
 *      dizeyi, yazma veritabaninda kesik dizeyi gorur. "Z\0eynep" min:2'yi
 *      gecer, satira "Z" yazilir ve 201 yaniti yalan soyler.
 *
 * 🔴 Alan basina kural DEGIL, 'api' grubunda tek kapi: yeni bir uc eklendiginde
 * kimsenin bir kurali hatirlamasi gerekmez (fail-safe). \0 bir tarayici formuna
 * yazilamaz; gonderen bot ya da bozuk bir istemcidir, alan bazinda hata
 * mesajina ihtiyac yok.
 *
 * Yanit burada URETILMEZ, exception firlatilir: bicim karari tek yerde,
 * ApiExceptionRenderer'da verilir (400 -> MALFORMED_REQUEST).
 * Ayrintili aciklama: docs/rehber/app/Http/Middleware/RejectMalformedInput.md
 */
final class RejectMalformedInput
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasUnparseableJsonBody($request) || $this->containsNulByte($request->all())) {
            throw new BadRequestHttpException;
        }

        return $next($request);
    }

    /** Bos govde bozuk DEGILDIR (govdesiz DELETE gibi); yalnizca cozulemeyen govde bozuktur. */
    private function hasUnparseableJsonBody(Request $request): bool
    {
        if (! $request->isJson()) {
            return false;
        }

        $body = $request->getContent();

        return $body !== '' && ! json_validate($body);
    }

    /**
     * Anahtarlar da taranir: JSON kolonlari jsonb ve PostgreSQL jsonb icinde
     * \u0000'i hic kabul etmez — orada kesme degil 500 olur.
     *
     * @param  array<array-key, mixed>  $input
     */
    private function containsNulByte(array $input): bool
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && str_contains($key, "\0")) {
                return true;
            }

            if (is_string($value) && str_contains($value, "\0")) {
                return true;
            }

            if (is_array($value) && $this->containsNulByte($value)) {
                return true;
            }
        }

        return false;
    }
}
