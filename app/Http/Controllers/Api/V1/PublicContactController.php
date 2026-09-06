<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Contact\SubmitContactAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\ContactRequest;
use Illuminate\Http\Response;

/**
 * Iletisim formu — sistemin DORDUNCU auth'suz yazma yolu.
 *
 * 🔴 204 doner, 201 DEGIL. Frontend zaten donus degerini okumuyor
 * (`sendContactMessage(): Promise<void>`), yani ikisi de serbestti. 204
 * secildi cunku 201 bir GOVDE ister ve o govdede dondurulecek hicbir sey
 * yok: `id` kimsenin isine yaramaz, `ip_hash` sizinti olurdu, `created_at`
 * bilgi tasimaz (C1: Resource bir beyaz listedir — beyaz listenin bos oldugu
 * yerde Resource'un kendisi de gereksizdir).
 *
 * Ikinci kazanc bedava geldi: honeypot'un sessiz reddi ile gercek kayit
 * arasinda AYIRT EDILECEK BIR SEY KALMIYOR. LCV ucunda bunun icin sahte bir
 * model uretmek gerekmisti (5.4).
 *
 * Controller hicbir `if` icermez (CLAUDE.md §1): honeypot karari Action'da,
 * bicim FormRequest'te, hata -> HTTP eslemesi ApiExceptionRenderer'da.
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/PublicContactController.md
 */
final class PublicContactController extends Controller
{
    public function __invoke(ContactRequest $request, SubmitContactAction $action): Response
    {
        $action->handle(
            $request->contactAttributes(),
            (string) $request->ip(),
            $request->isHoneypotTripped(),
        );

        return response()->noContent();
    }
}
