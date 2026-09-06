<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Google Gemini surucusu — sistemin PARA HARCAYAN ilk dis cagrisi.
 *
 * 🔴 SIR YONETIMI. GEMINI_API_KEY'in gordugu tek yer burasidir:
 *
 *     .env  ->  config/ai.php  ->  BU SINIF
 *
 * Baska hicbir katman okumaz. Controller, Action, Resource ve testler
 * anahtarin varligindan bile habersizdir (CLAUDE.md §3). Frontend'e sizma
 * yolu MIMARI olarak yoktur: Vite yalnizca `VITE_` onekli degiskenleri
 * paketler ve bu degisken backend'in .env'indedir.
 *
 * 🔴 Anahtar SORGU DIZESINDE degil BASLIKTA gonderiliyor. Gemini her ikisini
 * de kabul eder (`?key=...` ve `x-goog-api-key`), ama URL'ler erisim
 * loglarina, proxy kayitlarina, hata izlerine ve `Referer` basliklarina
 * YAZILIR. Bir sir, kopyalanabilecek her yerde gorunmemelidir.
 * Ayrintili aciklama: docs/rehber/app/Services/Ai/GeminiProvider.md
 */
final class GeminiProvider implements AiProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    /**
     * @throws AiProviderException Anahtar yok / cagri basarisiz / yanit bos
     */
    public function reply(string $prompt): string
    {
        $response = $this->call($prompt);

        if ($response === null) {
            throw AiProviderException::unreachable($this->name());
        }

        /** @var mixed $text */
        $text = $response->json('candidates.0.content.parts.0.text');

        // 🔴 200 gelmesi, kullanilabilir bir cevap gelmesi DEMEK DEGILDIR.
        // Gemini guvenlik filtresine takilan istekte de 200 doner ama
        // `candidates` bos ya da `parts` yoktur. Bu kontrol olmasaydi
        // kullaniciya bos bir balon gosterirdik ve sebebi hicbir yerde
        // gorunmezdi (ders 34'un ailesi: bekledigin yaniti almak,
        // bekledigin SEBEPLE aldigin anlamina gelmez).
        if (! is_string($text) || trim($text) === '') {
            Log::warning('Gemini returned no usable candidate.', [
                'finishReason' => $response->json('candidates.0.finishReason'),
                'blockReason' => $response->json('promptFeedback.blockReason'),
            ]);

            throw AiProviderException::unreachable($this->name());
        }

        return trim($text);
    }

    /**
     * HTTP cagrisini yapar; kullanilabilir bir yanit yoksa `null` doner.
     *
     * 🔴 TEKRAR YALNIZCA BAGLANTI HATASINDA. `when` kapanisi olmasaydi
     * Laravel her basarisiz yaniti tekrar denerdi ve iki sey birden bozulurdu:
     *   1. Cevap VEREN bir saglayiciyi tekrar cagirmak PARAYI IKIYE KATLAR —
     *      Gemini ilk istegi zaten faturalamis olabilir.
     *   2. 15 saniye butcesi (config/ai.php'deki hesap) anlamsizlasir.
     * Bir 400 ("prompt cok uzun") tekrar denemekle duzelmez; bir kopmus TCP
     * baglantisi duzelebilir. Ayrim tam olarak burasi.
     */
    private function call(string $prompt): ?Response
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey()])
                ->timeout(Config::integer('ai.request.timeout_seconds'))
                ->retry(
                    Config::integer('ai.request.retry_times'),
                    Config::integer('ai.request.retry_delay_ms'),
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                )
                ->post($this->endpoint(), $this->payload($prompt));
        } catch (Throwable $exception) {
            // 🔴 H8: saglayicinin HAM hatasi yanita GIRMEZ. Zaman asimi
            // mesaji URL'i, dolayisiyla model adini ve altyapiyi ele verir.
            // Disariya yalnizca PROVIDER_UNAVAILABLE cikar.
            throw AiProviderException::unreachable($this->name(), $exception);
        }

        if ($response->failed()) {
            // Govdenin TAMAMI degil yalnizca saglayicinin hata metni: log da
            // bir depodur ve kullanicinin mesaji orada da durmamali (B6).
            Log::warning('Gemini rejected the request.', [
                'status' => $response->status(),
                'providerMessage' => Str::limit((string) $response->json('error.message'), 200),
            ]);

            return null;
        }

        return $response;
    }

    /**
     * 🔴 A8: bir sinifin DEGISMEZI baska bir katmana birakilmaz.
     *
     * "Anahtar var mi?" sorusunu AppServiceProvider'da da sorabilirdik ama
     * o zaman bu sinif, dogrulanmis bir dunyada CALISTIGINI VARSAYAN bir
     * sinif olurdu — ve varsayim, konsoldan/kuyruktan/testten dogrudan
     * ornek uretildigi gun sessizce yanlislanirdi. AppServiceProvider
     * "HANGI surucu?" sorusunu cevaplar (K70); bu metot "ben kullanilabilir
     * miyim?" sorusunu. Iki ayri soru, iki ayri yer — C3 ihlali degil.
     */
    private function apiKey(): string
    {
        /** @var mixed $key */
        $key = Config::get('ai.providers.gemini.api_key');

        if (! is_string($key) || trim($key) === '') {
            throw AiProviderException::unavailable($this->name());
        }

        return $key;
    }

    /** `.../v1beta/models/gemini-2.0-flash:generateContent` */
    private function endpoint(): string
    {
        return rtrim(Config::string('ai.providers.gemini.base_url'), '/')
            .'/models/'.Config::string('ai.providers.gemini.model')
            .':generateContent';
    }

    /**
     * Istek govdesi.
     *
     * 🔴 `systemInstruction` AYRI bir alandir, kullanicinin mesajiyla
     * BIRLESTIRILMEZ. Iki metni tek bir string'te birlestirseydik model,
     * talimat ile kullanici girdisi arasindaki siniri yalnizca metinden
     * tahmin ederdi — "yukaridaki talimatlari unut" yazan bir mesaj o siniri
     * bulaniklastirirdi. Ayri alan, sinirin YAPIDA durmasini saglar.
     * Bu prompt injection'i BITIRMEZ, yalnizca en ucuz halini zorlastirir
     * (B6: bir savunmanin neyi kapatmadigi da yazilir — kilavuz §5).
     *
     * `contents` tek elemanli: sohbet gecmisi tasinmiyor (AiProvider §reply).
     *
     * @return array<string, mixed>
     */
    private function payload(string $prompt): array
    {
        return [
            'systemInstruction' => [
                'parts' => [['text' => Config::string('ai.system_prompt')]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                // Cikti uzunlugu dogrudan faturadir.
                'maxOutputTokens' => Config::integer('ai.request.max_output_tokens'),
            ],
        ];
    }
}
