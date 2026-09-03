<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Enums\SubscriptionTier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Odeme baslatma govdesinin dogrulanmasi — TEK ALAN: `tier`.
 *
 * 🔴 Govdede DAVETIYE KIMLIGI YOK. Aidiyet URL'nin YAPISINDA duruyor:
 *   POST /api/invitations/{invitation}/checkout  -> tekil alim
 *   POST /api/payments/checkout                  -> paket alim (K42)
 *
 * Gerekce N1 (Faz 3) ve Faz 6'nin medya uclarindaki ayni karar: kimlik
 * govdeden gelseydi ISTEMCININ SOZUNE kalirdi ve aidiyet kontrolu bir rota
 * baglamasi yerine elle yazilmis bir sorguya bagli olurdu.
 *
 * 🔴 Govdede FIYAT ALANI da yok ve olamaz. Istemcinin gonderdigi bir fiyat
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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Gecerli plan listesi ENUM'DAN turetilir (K39 ailesi): elle
            // yazilsaydi enum'a yeni bir plan eklendiginde kural sessizce
            // eskirdi.
            //
            // 🔴 D6: Rule::enum(SubscriptionTier::class) DEGIL, duz 'in:' kurali.
            // Kural NESNESI kullanildiginda $validator->failed() anahtari
            // SINIF ADI olur ve hata zarfina
            //   {"rule":"illuminate\\validation\\rules\\enum"}
            // diye sizar — Faz 3'te Password::min(8) tam olarak bu yuzden
            // 'min:8' string'ine cevrilmisti. Kural ADI sozlesmenin parcasidir.
            'tier' => ['required', 'string', 'in:'.implode(',', SubscriptionTier::values())],
        ];
    }

    /** Dogrulanmis plan — Action enum bekler, sihirli string degil. */
    public function tier(): SubscriptionTier
    {
        /** @var array{tier: string} $validated */
        $validated = $this->validated();

        return SubscriptionTier::from($validated['tier']);
    }
}
