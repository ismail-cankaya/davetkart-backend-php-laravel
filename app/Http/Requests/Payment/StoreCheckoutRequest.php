<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Enums\SubscriptionTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/payments/checkout` govdesinin dogrulanmasi.
 *
 * D1: kurallar istegin GONDERDIGI adlarla (camelCase) yazilir.
 * D5: Action'a giden veri validated()'ten gelir, all()'dan degil.
 *
 * 🔴 Govdede FIYAT ALANI YOK ve olmamali. Istemcinin gonderdigi bir fiyat
 * "dogrulanabilir" bir sey degildir — bicimsel olarak her zaman gecerlidir.
 * Fiyat sunucudaki config'ten okunur (StartCheckoutAction §4. katman).
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Payment/StoreCheckoutRequest.md
 */
final class StoreCheckoutRequest extends FormRequest
{
    /** Yetki karari Policy'nin isi; controller Gate::authorize ile cagirir. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Gecerli plan listesi ENUM'DAN turetilir (K39 ailesi): elle
            // yazilsaydi enum'a yeni bir plan eklendiginde kural sessizce
            // eskirdi. Rule::enum ayrica degeri enum'a cevirmez — donusum
            // asagida acikca yapiliyor.
            'tier' => ['required', Rule::enum(SubscriptionTier::class)],

            // 🔴 K42: davetiye kimligi OPSIYONEL.
            //   verilirse -> TEKIL alim (yalnizca o davetiye)
            //   yoksa     -> PAKET alim (hesabin tamami)
            //
            // 'exists' kurali BILEREK YOK: varligi dogrulamak, var olmayan bir
            // kimlik icin 422, baskasinin kimligi icin 200 dondurerek
            // KIMLIK UZAYINI TARANABILIR yapardi (A1'in Faz 2'de kurdugu
            // gerekce, IDOR eksenine tasinmis hali). Aidiyet controller'da
            // Gate ile soruluyor ve reddi 404 (H7).
            'invitationId' => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }

    /** Dogrulanmis plan — Action enum bekler, string degil. */
    public function tier(): SubscriptionTier
    {
        /** @var array{tier: string} $validated */
        $validated = $this->validated();

        return SubscriptionTier::from($validated['tier']);
    }

    /** Davetiye kimligi; paket aliminda null. */
    public function invitationId(): ?string
    {
        /** @var array{invitationId?: string|null} $validated */
        $validated = $this->validated();

        $id = $validated['invitationId'] ?? null;

        // Bos string ile eksik alan AYNI SEY DEGIL diye dusunulebilir ama
        // burada oyle: ConvertEmptyStringsToNull global middleware'i (Faz 2,
        // ders 20) bos string'i zaten null'a cevirir; bu satir yalnizca
        // tipi daraltir.
        return $id === '' ? null : $id;
    }
}
