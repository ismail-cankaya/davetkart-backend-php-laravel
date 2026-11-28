<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Report\ReportInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportInvitationRequest;
use Illuminate\Http\Response;

/** Misafirin "bu davetiyeyi bildir" ucu (Faz 25, K313). Honeypot yutmasında da 204: bot ayırt edemesin. */
final class PublicInvitationReportController extends Controller
{
    public function __invoke(ReportInvitationRequest $request, string $invitation, ReportInvitationAction $action): Response
    {
        $action->handle($invitation, $request->reportAttributes(), (string) $request->ip(), $request->isHoneypotTripped());

        return response()->noContent();
    }
}
