<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Rotalari
|--------------------------------------------------------------------------
| '/api' oneki bootstrap/app.php icindeki withRouting() tarafindan eklenir.
| K10: surum URL'de DEGIL, controller namespace'inde (Api\V1\).
| K12: auth gerektirmeyen rotalar '/api/public/' altinda gruplanir (Faz 4).
| Ayrintili aciklama: docs/rehber/routes/api.md
*/

// Sozlesme sagligi: API katmani ayakta ve JSON konusuyor mu?
// Closure DEGIL sinif referansi — route:cache closure'lari serilestiremez.
Route::get('/ping', HealthController::class)->name('health.ping');
