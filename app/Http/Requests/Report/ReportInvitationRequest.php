<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use App\Enums\InvitationReportReason;
use App\Http\Requests\Concerns\HasHoneypot;
use Illuminate\Foundation\Http\FormRequest;

/** Misafirin bildirimi (Faz 25, K313): neden zorunlu, ayrıntı isteğe bağlı ve kısa. */
final class ReportInvitationRequest extends FormRequest
{
    use HasHoneypot;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'in:'.implode(',', InvitationReportReason::values())],
            'details' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array{reason: string, details?: string|null} */
    public function reportAttributes(): array
    {
        /** @var array{reason: string, details?: string|null} $data */
        $data = $this->validated();

        return array_intersect_key($data, ['reason' => true, 'details' => true]);
    }
}
