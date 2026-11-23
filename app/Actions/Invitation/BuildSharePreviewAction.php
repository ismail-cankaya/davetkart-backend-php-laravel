<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * FE-04: paylasim onizlemesinin (OG) degerleri. Nginx yalnizca bot User-Agent'ini buraya yollar;
 * UA sahtelenebilir, bu yuzden TEK savunma: ad/tarih/mekan yalnizca YAYINDAKI davetiyeden
 * (yuk `ResolvePublicInvitationAction` kapsamindan gecer). Yayinda degilse ya da yoksa genel onizleme.
 * Kilavuz: docs/rehber/app/Actions/Invitation/BuildSharePreviewAction.md
 */
final class BuildSharePreviewAction
{
    private const string GENERIC_DESCRIPTION = 'Size özel bir davetiye var. Açmak için dokunun.';

    public function __construct(private readonly PublicInvitationPayloadAction $payload) {}

    /**
     * @return array<string, string> url, image, title, description, siteName (Blade degiskenleri)
     */
    public function handle(Request $request, string $id): array
    {
        $frontend = rtrim(Config::string('davetkart.frontend.url'), '/');
        $preview = [
            'url' => $frontend.'/invite/'.strtolower($id),
            'image' => $frontend.Config::string('davetkart.share_preview.image_path'),
            'title' => 'DavetKart — Dijital Davetiye',
            'description' => self::GENERIC_DESCRIPTION,
            'siteName' => 'DavetKart',
        ];

        try {
            return [...$preview, ...$this->fromInvitation($this->payload->handle($request, $id))];
        } catch (ModelNotFoundException) {
            // Taslak, silinmis ve hic olmayan ayni genel onizlemeyi alir (H7: ayrim yok).
            return $preview;
        }
    }

    /**
     * @param  array<string, mixed>  $data  PublicInvitationResource ciktisi
     *
     * @return array<string, string>
     */
    private function fromInvitation(array $data): array
    {
        /** @var array<string, mixed> $inv */
        $inv = is_array($data['invitation'] ?? null) ? $data['invitation'] : [];
        // Saklama bicimindeki tek tarafli ayrac ("& Ali", frontend utils/names) gosterimde temizlenir.
        $names = trim($this->text($inv, 'names'), " &\t\n");
        $title = $names !== '' ? $names : $this->text($inv, 'title');

        $when = '';
        if ($this->text($inv, 'date') !== '') {
            $at = Carbon::createFromFormat('Y-m-d\TH:i', $this->text($inv, 'date'), $this->text($inv, 'timezone') ?: 'Europe/Istanbul');
            if ($at instanceof Carbon) {
                $at->locale('tr');
                $when = $at->translatedFormat('j F Y, H:i');
            }
        }
        $parts = array_values(array_filter([$when, $this->text($inv, 'venue')], fn (string $p): bool => $p !== ''));

        $preview = [
            'title' => $title !== '' ? $title.' — Davetiye' : 'Davetiye',
            'description' => $parts !== [] ? implode(' · ', $parts) : self::GENERIC_DESCRIPTION,
            // K102: Elit (logosuz) yayinda marka adi da yok.
            'siteName' => ($inv['showBranding'] ?? true) === false ? '' : 'DavetKart',
        ];

        $gallery = is_array($inv['galleryImages'] ?? null) ? $inv['galleryImages'] : [];
        $first = is_array($gallery[0] ?? null) ? $gallery[0] : [];
        if (is_string($first['url'] ?? null) && $first['url'] !== '') {
            $preview['image'] = $first['url'];
        }

        return $preview;
    }

    /** @param  array<string, mixed>  $inv */
    private function text(array $inv, string $key): string
    {
        return is_string($inv[$key] ?? null) ? $inv[$key] : '';
    }
}
