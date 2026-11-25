<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Actions\Refund\ResolveRefundRequestAction;
use App\Enums\RefundRequestStatus;
use RuntimeException;

/**
 * refunds:approve ve refunds:reject'in ortak gövdesi (Faz 14, 14.6); kalıtım değil trait.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/Concerns/ResolvesRefundRequests.md
 *
 * @mixin \Illuminate\Console\Command
 */
trait ResolvesRefundRequests
{
    private function resolve(ResolveRefundRequestAction $action, RefundRequestStatus $decision): int
    {
        $id = $this->argument('id');
        $id = is_string($id) ? $id : '';
        $note = $this->option('note');
        $note = is_string($note) && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;

        try {
            $request = $action->handle($id, $decision, $note);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Talep {$request->id}: {$request->status->value}. Kullanıcıya mail kuyruğa alındı.");

        return self::SUCCESS;
    }
}
