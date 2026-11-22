<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * 🔴 K185 (OPS-04): üretimde `log` posta sürücüsü maili sessizce yutar. Uygulama durmaz
 * (görünür, fail-closed değil); her gönderim denemesinde CRITICAL log + `about` satırı.
 * Ayrintili aciklama: docs/rehber/app/Support/ProductionMailCheck.md
 */
final readonly class ProductionMailCheck
{
    public function __construct(private Application $app) {}

    public function mailer(): string
    {
        return Config::string('mail.default');
    }

    public function isSilent(): bool
    {
        return $this->app->isProduction() && $this->mailer() === 'log';
    }

    /** MessageSending dinleyicisi: alıcı yazılmaz (KVKK), yalnızca sürücü. */
    public function reportIfSilent(): void
    {
        if ($this->isSilent()) {
            Log::critical('Mail not delivered: production mailer is log', ['mailer' => $this->mailer()]);
        }
    }

    public function aboutLine(): string
    {
        return $this->isSilent()
            ? $this->mailer().' (MAIL IS NOT DELIVERED in production)'
            : $this->mailer();
    }
}
