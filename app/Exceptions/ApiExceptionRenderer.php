<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Her exception'i K20 hata zarfina cevirir: { error: { code, fields?, params?, debug? } }.
 *
 * Tek cikis noktasidir: controller'lar ve action'lar hata YANITI uretmez,
 * yalnizca exception firlatir. Bicim karari burada, tek yerde verilir.
 * Ayrintili aciklama: docs/rehber/app/Exceptions/ApiExceptionRenderer.md
 */
final class ApiExceptionRenderer
{
    /**
     * Laravel kurallarinin konumlu parametrelerine ad verir.
     * Listede olmayan kuralin parametreleri 'values' altinda toplanir.
     *
     * @var array<string, list<string>>
     */
    private const RULE_PARAM_NAMES = [
        'between' => ['min', 'max'],
        'digits' => ['digits'],
        'digits_between' => ['min', 'max'],
        'gt' => ['value'],
        'gte' => ['value'],
        'lt' => ['value'],
        'lte' => ['value'],
        'max' => ['max'],
        'min' => ['min'],
        'size' => ['size'],
    ];

    public function render(Throwable $e): JsonResponse
    {
        $code = $this->resolveCode($e);

        /** @var array<string, mixed> $payload */
        $payload = ['code' => $code->value];

        if ($e instanceof ValidationException) {
            $payload['fields'] = $this->fields($e);
        }

        // H9: beyaz listede adi gecmeyen parametre disari cikamaz.
        $params = $code->filterParams($this->params($e));

        if ($params !== []) {
            $payload['params'] = $params;
        }

        // H3: uretimde bu blok HIC calismaz — unutulup acik kalmasi mumkun degil.
        if (config('app.debug') === true) {
            $payload['debug'] = $this->debug($e);
        }

        return response()->json(['error' => $payload], $code->status());
    }

    /** Exception turunu sozlesmedeki hata koduna esler. */
    private function resolveCode(Throwable $e): ErrorCode
    {
        return match (true) {
            $e instanceof ValidationException => ErrorCode::ValidationFailed,

            // H6: kayit hatasi ASLA `fields` tasimaz — enumeration savunmasi.
            $e instanceof RegistrationFailedException => ErrorCode::RegistrationFailed,

            $e instanceof AuthenticationException => ErrorCode::Unauthenticated,
            $e instanceof ThrottleRequestsException => ErrorCode::RateLimited,
            $e instanceof PostTooLargeException => ErrorCode::FileTooLarge,

            // H7: sahiplik yoksa 404. 403 kaynagin varligini dogrular.
            $e instanceof ModelNotFoundException,
            $e instanceof AuthorizationException => ErrorCode::ResourceNotFound,

            $e instanceof HttpExceptionInterface => $this->fromStatus($e->getStatusCode()),

            default => ErrorCode::ServerError,
        };
    }

    /** Tur bilgisi tasimayan HTTP exception'lari icin durum kodundan geri esleme. */
    private function fromStatus(int $status): ErrorCode
    {
        return match ($status) {
            400 => ErrorCode::MalformedRequest,
            401 => ErrorCode::Unauthenticated,
            403, 404, 405 => ErrorCode::ResourceNotFound,
            413 => ErrorCode::FileTooLarge,
            422 => ErrorCode::ValidationFailed,
            429 => ErrorCode::RateLimited,
            503 => ErrorCode::ProviderUnavailable,
            default => ErrorCode::ServerError,
        };
    }

    /**
     * Dogrulama hatalarini alan -> ihlal edilen kurallar seklinde cikarir.
     * Alan adlari istegin gonderdigi haliyle kalir (camelCase) — bkz. 08 §2.4.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function fields(ValidationException $e): array
    {
        $fields = [];

        foreach ($e->validator->failed() as $field => $rules) {
            foreach ($rules as $rule => $ruleParams) {
                $name = Str::snake($rule);
                $entry = ['rule' => $name];
                $named = $this->nameRuleParams($name, array_values($ruleParams));

                if ($named !== []) {
                    $entry['params'] = $named;
                }

                $fields[$field][] = $entry;
            }
        }

        return $fields;
    }

    /**
     * Konumlu kural parametrelerine ad verir: max:10 -> ['max' => 10].
     *
     * @param  list<mixed>  $params
     *
     * @return array<string, mixed>
     */
    private function nameRuleParams(string $rule, array $params): array
    {
        if ($params === []) {
            return [];
        }

        $names = self::RULE_PARAM_NAMES[$rule] ?? null;

        if ($names === null) {
            return ['values' => array_map($this->normalize(...), $params)];
        }

        $named = [];

        foreach ($names as $index => $name) {
            if (array_key_exists($index, $params)) {
                $named[$name] = $this->normalize($params[$index]);
            }
        }

        return $named;
    }

    /** Laravel kural parametrelerini string verir; sayisal olanlari sayiya cevirir. */
    private function normalize(mixed $value): mixed
    {
        return is_string($value) && is_numeric($value) ? $value + 0 : $value;
    }

    /**
     * Zarf disi parametreler. Su an yalnizca hiz siniri; digerleri kendi
     * exception siniflarini kazandiklari fazlarda eklenecek (Faz 5, Faz 7).
     *
     * @return array<string, mixed>
     */
    private function params(Throwable $e): array
    {
        if (! $e instanceof ThrottleRequestsException) {
            return [];
        }

        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

        return $retryAfter === null ? [] : ['retryAfter' => (int) $retryAfter];
    }

    /**
     * H8: yigin izi yanita GIRMEZ. Dosya yolu proje kokune gore kisaltilir.
     *
     * @return array<string, mixed>
     */
    private function debug(Throwable $e): array
    {
        return [
            'message' => $e->getMessage(),
            'exception' => $e::class,
            'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $e->getFile()),
            'line' => $e->getLine(),
        ];
    }
}
