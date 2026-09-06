<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\AiProviderException;

/**
 * AI saglayicisinin arkasina saklandigi arayuz — K8, Strategy Pattern.
 *
 * 🔴 PaymentGateway'in birebir ikizi. Orada eksik olan bir ANLASMA'ydi
 * (Iyzico), burada eksik olan bir ANAHTAR ve bir MALIYET: her cagri para.
 * Ikisinde de cozum ayni: araya bir arayuz koy, bugun ucuz olani bagla,
 * yarin degisecek TEK sey AppServiceProvider'daki bir satir olsun.
 *
 *   AskAssistantAction · AssistantController · AssistantTest
 *   -> hicbiri hangi surucunun bagli oldugunu BILMEZ
 *
 * SOLID:
 *   - D: Action somut saglayiciya degil bu arayuze bagli
 *   - O: yeni saglayici (OpenAI, Claude) EKLENIR, var olan kod DEGISMEZ
 *   - I: IKI metot. "Sohbet gecmisi", "akis (streaming)", "gomme (embedding)"
 *     BILEREK yok — bugun hicbiri kullanilmiyor ve yazilsalardi her surucu
 *     bos govde yazardi.
 *
 * CLAUDE.md §1: dis servislerle iletisim arayuzler uzerinden app/Services/'te
 * ve TUM API anahtarlari yalnizca bu siniflarin erisiminde.
 * Ayrintili aciklama: docs/rehber/app/Services/Ai/AiProvider.md
 */
interface AiProvider
{
    /**
     * Surucunun adi — log ve teshis icin.
     *
     * PaymentGateway::name() bir KOLONA yaziliyordu (F4: config bugunu,
     * kolon gecmisi anlatir). Burada saklanan bir satir YOK, cunku sohbet
     * kaydedilmiyor (asagiya bak). Ad yine de arayuzde: bir hata log'unda
     * "hangi surucu patladi" sorusunun cevabi surucunun kendisinden gelmeli,
     * config'ten degil — config o an degismis olabilir.
     */
    public function name(): string;

    /**
     * Kullanicinin son mesajina bir yanit uretir.
     *
     * 🔴 SISTEM TALIMATI PARAMETRE DEGILDIR. `reply($systemPrompt, $userText)`
     * yazilabilirdi ve daha "esnek" gorunurdu — ama o esneklik, konu
     * sinirlamasini CAGIRANIN kararina cevirirdi. Talimat surucunun icinde,
     * config'ten okunur; boylece hicbir cagiri yolu onu zayiflatamaz.
     * PaymentNotification'in ayni fikri: bir kurali hatirlanmasi gereken bir
     * adim olmaktan cikarip GECILMESI ZORUNLU bir kapiya donusturmek (H12).
     *
     * 🔴 SOHBET GECMISI YOK. Frontend (useAssistantChat.ts) yalnizca son
     * mesaji gonderiyor; gecmisi biz de saklamiyoruz. Bu bir eksiklik degil
     * uc kazanc: (1) her istek ayni maliyette — gecmis buyudukce token
     * faturasi buyumez, (2) saklanmayan veri sizamaz (KVKK), (3) bir
     * kullanicinin gecmisi baska bir kullaniciya karisamaz.
     *
     * 🔴 Donus tipi `string`, bir DTO DEGIL. CheckoutSession UC alan
     * tasidigi icin sinif olmayi hak ediyordu; tek `text` alani tasiyan bir
     * sarmalayici bugun yalnizca toren olurdu. Token sayaci gibi ikinci bir
     * olcu gerektiginde tek tuketici (AskAssistantAction) oldugu icin donus
     * tipini degistirmek tek dosyalik istir (ders 52'nin tersten okunusu:
     * bir soyutlamanin bedeli de kaldirildigi gun olculur).
     *
     * @param  string  $prompt  Kullanicinin mesaji — FormRequest tarafindan
     *                          uzunlugu dogrulanmis, icerigi DOGRULANMAMIS
     *
     * @throws AiProviderException Surucu yapilandirilmamis ya da kullanilabilir
     *                             bir yanit alinamadi -> 503 (H8)
     */
    public function reply(string $prompt): string;
}
