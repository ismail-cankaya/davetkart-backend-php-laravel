<?php

declare(strict_types=1);

use App\Http\Controllers\SharePreviewController;
use Illuminate\Support\Facades\Route;

/*
| FE-04 (Faz 19.23): paylasim onizlemesi. Nginx yalnizca bilinen onizleme botlarinin
| /invite/{id} istegini buraya yollar (deploy/nginx/davetkart.conf, $davetkart_preview_bot);
| insanlar SPA'yi alir. 'web' middleware grubu yok (bootstrap/app.php).
*/
Route::get('/invite/{id}', SharePreviewController::class)
    ->whereUlid('id')
    ->name('share-preview');
