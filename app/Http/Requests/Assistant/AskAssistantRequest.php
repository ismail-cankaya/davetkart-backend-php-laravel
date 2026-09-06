<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

/**
 * POST /api/assistant/chat govdesini dogrular — TEK ALAN: `message`.
 *
 * 🔴 Bu sinif L1'in ikinci katmanidir: rota katmani (auth + throttle)
 * elemis, buradan gecen istek VERITABANINA ve SAGLAYICIYA gidecek. En pahali
 * katman (para harcayan cagri) en sonda; en ucuz kontrol (uzunluk) burada.
 *
 * 🔴 `max` degeri config'ten okunuyor: bu bir veri butunlugu kurali degil
 * bir IS TERCIHIDIR (E6). Uzun prompt = yuksek token faturasi; sinir
 * degistiginde kod degismemeli.
 *
 * 🔴 SOHBET GECMISI ALANI YOK. Frontend (useAssistantChat.ts) zaten yalnizca
 * son mesaji gonderiyor. Bir `history` alani kabul etseydik istemci ONA
 * ISTEDIGINI yazabilirdi — yani modele gonderilen baglami saldirgan kurardi
 * ve `system_prompt`'un konu sinirlamasi bir gorunuse donusurdu.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Assistant/AskAssistantRequest.md
 */
final class AskAssistantRequest extends FormRequest
{
    /** Yetki karari rota katmaninda (auth:sanctum); burada karar yok. */
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
            // 'min:1' YAZILMADI: ConvertEmptyStringsToNull global middleware'i
            // '' degerini null'a cevirir ve `required` onu zaten yakalar
            // (ders 20: savunma yazmadan once framework'un ne yaptigini oku).
            'message' => [
                'required',
                'string',
                'max:'.Config::integer('davetkart.assistant.max_prompt_chars'),
            ],
        ];
    }

    /**
     * Dogrulanmis kullanici mesaji.
     *
     * 🔴 "Dogrulanmis" burada YALNIZCA BICIMSEL anlamda: metnin uzunlugu
     * kontrol edildi, ICERIGI kontrol EDILMEDI ve edilemez. Bir prompt
     * injection denemesi ("yukaridaki talimatlari unut") bu kurallarin
     * hepsini gecer — cunku o bir BICIM sorunu degil. Savunma asagida,
     * GeminiProvider'in `systemInstruction`'i ayri alanda tutmasinda ve
     * modele hicbir arac/veri verilmemesinde (B6, kilavuz §5).
     */
    public function prompt(): string
    {
        /** @var array{message: string} $validated */
        $validated = $this->validated();

        return $validated['message'];
    }
}
