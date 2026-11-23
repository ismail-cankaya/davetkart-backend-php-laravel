<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\ConfirmEmailVerificationAction;
use App\Actions\Account\SendEmailVerificationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmEmailVerificationRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * K191 (SEC-09): doğrulama postasını yeniden gönder (kimlikli, saatte 3) ve bağlantıyı onayla
 * (kimliksiz; yetkinin kanıtı imza). Ayrıntılı açıklama: docs/rehber/app/Http/Controllers/Api/V1/EmailVerificationController.md
 */
final class EmailVerificationController extends Controller
{
    public function resend(Request $request, SendEmailVerificationAction $action): Response
    {
        /** @var User $user */
        $user = $request->user();
        $action->handle($user);

        return response()->noContent();
    }

    public function confirm(ConfirmEmailVerificationRequest $request, ConfirmEmailVerificationAction $action): Response
    {
        $action->handle($request->link());

        return response()->noContent();
    }
}
