<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Invitation\BuildSharePreviewAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

/**
 * FE-04: paylasim onizlemesi botlari icin OG etiketli HTML (Nginx bot UA'sini buraya yollar).
 * Kilavuz: docs/rehber/app/Http/Controllers/SharePreviewController.md
 */
final class SharePreviewController extends Controller
{
    public function __invoke(Request $request, string $id, BuildSharePreviewAction $build): Response
    {
        // Blade {{ }}: her deger HTML-kacisli (adlar kullanici girdisi).
        return response()
            ->view('share-preview', $build->handle($request, $id))
            ->header('Cache-Control', 'public, max-age='.Config::integer('davetkart.share_preview.max_age'))
            // Ayni adres insana SPA, bota bu sayfayi verir: ara onbellek UA'ya gore ayirsin.
            ->header('Vary', 'User-Agent');
    }
}
