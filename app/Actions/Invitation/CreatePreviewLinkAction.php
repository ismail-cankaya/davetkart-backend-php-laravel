<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Models\Invitation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * "Telefonda aç" (Faz 29.4, K328): sahibine, taslağı da gösteren süreli ve imzalı bir önizleme bağlantısı üretir.
 * İmza GÖRELİ (alan adından bağımsız); frontend aynı sorgu dizesiyle API'yi çağırır.
 * Ayrıntı: docs/rehber/app/Actions/Invitation/CreatePreviewLinkAction.md
 */
final class CreatePreviewLinkAction
{
    public function handle(Invitation $invitation): PreviewLink
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(Config::integer('davetkart.preview_link.ttl_minutes'));
        $signed = URL::temporarySignedRoute('public.invitations.preview', $expiresAt, ['invitation' => $invitation->getKey()], absolute: false);
        $query = (string) parse_url($signed, PHP_URL_QUERY);

        $frontend = rtrim(Config::string('davetkart.frontend.url'), '/');
        $path = Config::string('davetkart.preview_link.path');

        return new PreviewLink("{$frontend}{$path}/{$invitation->getKey()}?{$query}", $expiresAt);
    }
}
