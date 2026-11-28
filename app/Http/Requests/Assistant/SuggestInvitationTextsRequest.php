<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistant;

use App\Support\TextSlots;
use Illuminate\Foundation\Http\FormRequest;

/** Metin önerisi isteği (Faz 25, K310): yalnızca sözleşmedeki anahtar ve şimdiki metin; serbest istem yok. */
final class SuggestInvitationTextsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'in:'.implode(',', TextSlots::keys())],
            'current' => ['required', 'string', 'max:400'],
        ];
    }

    public function key(): string
    {
        /** @var array{key: string} $data */
        $data = $this->validated();

        return $data['key'];
    }

    public function current(): string
    {
        /** @var array{current: string} $data */
        $data = $this->validated();

        return $data['current'];
    }
}
