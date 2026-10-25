<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Exceptions\PaymentProviderException;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\Shopier\ShopierAmount;
use App\Services\Payment\Shopier\ShopierCallback;
use App\Services\Payment\Shopier\ShopierConfirmation;
use App\Services\Payment\Shopier\ShopierSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Shopier "API ile ödeme" sürücüsü — klasik imzalı form akışı (Faz 12).
 *
 * Üç iş, üç kanal:
 *   startCheckout()     -> imzalı form (FormPostAction); kart Shopier'de yazılır
 *   parseCallback()     -> tarayıcının dönüşü; YÖNLENDİRME bilgisi (K127)
 *   parseNotification() -> Otomatik Sipariş Bildirimi (OSB); 'paid' kararı (K117)
 *
 * 🔴 Kurucu sırları sınar (K123): boş sırla hesaplanan HMAC'i herkes üretebilir.
 * Eksikse sürücü KURULMAZ -> 503, sessizce çalışmaz.
 *
 * Paket değil kendi kodumuz (K120): topluluk SDK'sı HTML basıp `die` ediyor;
 * bizde formu frontend gönderiyor. Formüller ShopierSignature'da.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/ShopierGateway.md
 */
final class ShopierGateway implements PaymentGateway
{
    public const NAME = 'shopier';

    /**
     * Tarayıcı callback'inin yolu. Rota (`public.payments.shopier.callback`)
     * 12.7'de yazıldı; ShopierCallbackTest ikisinin aynı olduğunu kilitler.
     */
    public const CALLBACK_PATH = '/api/public/payments/shopier/callback';

    // Shopier'in sayısal sözlüğü (topluluk SDK'sı enum'ları).
    private const PRODUCT_TYPE_DIGITAL = '1';

    private const PLATFORM = '0';

    private const NOT_IN_FRAME = '0';

    private const LANGUAGE_TR = '0';

    private const MODULE_VERSION = '1.0.4';

    /** Bizim ISO kodumuz -> Shopier'in kodu. Yalnızca TL (davetkart.currency). */
    private const CURRENCY_CODES = ['TRY' => '0'];

    /** OSB'nin para birimi yazımı belirsiz (§A-c): bilinen üç yazım TRY sayılır. */
    private const OSB_CURRENCY_ALIASES = ['0' => 'TRY', 'TRY' => 'TRY', 'TL' => 'TRY'];

    private const STATUS_SUCCESS = 'success';

    private const STATUS_FAILED = 'failed';

    private readonly string $apiKey;

    private readonly string $apiSecret;

    private readonly ShopierConfirmation $confirmation;

    private readonly ?string $osbUsername;

    private readonly ?string $osbKey;

    /**
     * @throws PaymentProviderException Sır, OSB kimliği ya da mod eksik/geçersiz -> 503
     */
    public function __construct()
    {
        $this->apiKey = $this->secret('api_key') ?? throw PaymentProviderException::unavailable(self::NAME);
        $this->apiSecret = $this->secret('api_secret') ?? throw PaymentProviderException::unavailable(self::NAME);

        /** @var mixed $mode */
        $mode = Config::get('payment.providers.shopier.confirmation');
        $this->confirmation = (is_string($mode) ? ShopierConfirmation::tryFrom($mode) : null)
            ?? throw PaymentProviderException::unavailable(self::NAME);

        $this->osbUsername = $this->secret('osb.username');
        $this->osbKey = $this->secret('osb.key');

        // 'osb' modunda OSB kimlikleri olmadan HİÇBİR sipariş ödenemez: kurulmak
        // yerine 503. Aksi halde canlıda her ödeme sessizce 'pending' kalırdı.
        if ($this->confirmation === ShopierConfirmation::Osb && ! $this->osbConfigured()) {
            throw PaymentProviderException::unavailable(self::NAME);
        }
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * İmzalı ödeme formunu üretir. Ağ çağrısı YOK: formu tarayıcı gönderir.
     *
     * Tutar satırdan (amount_minor, indirim düşülmüş) okunur, istekten değil.
     * İmzaya giren metin ile forma yazılan metin AYNI değişken (K124).
     */
    public function startCheckout(Order $order): CheckoutSession
    {
        $user = $order->user ?? throw new RuntimeException('A Shopier checkout needs the buyer.');
        $currency = self::CURRENCY_CODES[$order->currency]
            ?? throw new RuntimeException("Shopier does not support currency '{$order->currency}'.");

        $orderId = (string) $order->getKey();
        $total = ShopierAmount::format($order->amount_minor);
        $randomNr = (string) random_int(100000, 999999);

        $fields = [
            'API_key' => $this->apiKey,
            'website_index' => (string) Config::integer('payment.providers.shopier.website_index'),
            'platform_order_id' => $orderId,
            'product_name' => 'DavetKart '.$order->tier->label(),
            'product_type' => self::PRODUCT_TYPE_DIGITAL,
            ...$this->buyerFields($user),
            'total_order_value' => $total,
            'currency' => $currency,
            'platform' => self::PLATFORM,
            'is_in_frame' => self::NOT_IN_FRAME,
            'current_language' => self::LANGUAGE_TR,
            'modul_version' => self::MODULE_VERSION,
            'random_nr' => $randomNr,
            'signature' => ShopierSignature::forPayment($randomNr, $orderId, $total, $currency, $this->apiSecret),
            'callback' => url(self::CALLBACK_PATH),
        ];

        return new CheckoutSession(
            // E5 (K121): sipariş kimliğini biz veriyoruz; provider_ref = orders.id.
            providerRef: $orderId,
            action: new FormPostAction(Config::string('payment.providers.shopier.payment_url'), $fields),
            expiresAt: null,
        );
    }

    /**
     * Tarayıcı callback'ini doğrular. Sıra: zorunlu alan -> imza -> durum (L1).
     *
     * @throws BadRequestHttpException Alan eksik / kimlik ULID değil / durum bilinmiyor
     * @throws InvalidWebhookSignatureException İmza tutmadı
     */
    public function parseCallback(Request $request): ShopierCallback
    {
        $orderId = $this->field($request, 'platform_order_id');
        $randomNr = $this->field($request, 'random_nr');
        $signature = $this->field($request, 'signature');
        $rawStatus = $this->field($request, 'status');

        // ULID dışı kimlik ne bizim siparişimizdir ne de yönlendirme adresine girebilir.
        if (! Str::isUlid($orderId)) {
            throw new BadRequestHttpException('Shopier callback order id is not a ULID.');
        }

        if (! ShopierSignature::verifyCallback($randomNr, $orderId, $signature, $this->apiSecret)) {
            throw new InvalidWebhookSignatureException;
        }

        $status = match ($rawStatus) {
            self::STATUS_SUCCESS => OrderStatus::Paid,
            self::STATUS_FAILED => OrderStatus::Failed,
            default => throw new BadRequestHttpException("Unknown Shopier status '{$rawStatus}'."),
        };

        /** @var mixed $paymentId */
        $paymentId = $request->input('payment_id');

        // 🔴 K117: 'osb' modunda bu callback HİÇBİR siparişi ilerletemez. Bildirim
        // nesnesi hiç üretilmez; yanlışlıkla işlenecek bir şey kalmaz.
        $notification = $this->confirmation === ShopierConfirmation::Callback
            ? new PaymentNotification(
                $orderId,
                $status,
                providerPaymentId: is_string($paymentId) ? $this->paymentId($paymentId) : null,
            )
            : null;

        return new ShopierCallback($orderId, $status === OrderStatus::Paid, $notification);
    }

    /**
     * Shopier'in sunucudan sunucuya bildirimi (OSB) — arayüzün "webhook"u.
     *
     * Eşlenemeyen bildirim (null) BadRequest değil: imzası doğru, Shopier'in
     * mağazasındaki başka bir satış olabilir. Tekrar göndermesin diye 200 alır.
     *
     * @throws InvalidWebhookSignatureException
     * @throws BadRequestHttpException
     */
    public function parseNotification(Request $request): PaymentNotification
    {
        return $this->parseOsb($request)
            ?? throw new BadRequestHttpException('Shopier notification could not be matched to an order.');
    }

    /**
     * OSB'yi doğrular ve çevirir. Sıra: kimlik -> imza -> çözme -> test -> tutar (L1).
     *
     * @return PaymentNotification|null null = imza doğru ama bizim siparişimizle eşlenemedi
     *
     * @throws InvalidWebhookSignatureException OSB kurulmamış ya da imza tutmadı -> 404
     * @throws BadRequestHttpException Gövde anlamsız, tutar okunamıyor ya da üretimde test bildirimi
     */
    public function parseOsb(Request $request): ?PaymentNotification
    {
        $res = $request->input('res');
        $hash = $request->input('hash');

        // OSB kurulmamışsa doğrulanamaz; doğrulanamayan istek imzası yanlış istekle aynıdır.
        if (! $this->osbConfigured() || ! is_string($res) || ! is_string($hash)) {
            throw new InvalidWebhookSignatureException;
        }

        if (! ShopierSignature::verifyOsb($res, $hash, (string) $this->osbUsername, (string) $this->osbKey)) {
            throw new InvalidWebhookSignatureException;
        }

        $json = base64_decode($res, true);
        /** @var mixed $data */
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data)) {
            throw new BadRequestHttpException('Shopier OSB body is not a JSON object.');
        }

        // Test bildirimi canlıda kabul edilmez: test kartıyla "ödenmiş" sipariş olmasın.
        if ($this->scalar($data, 'istest') === '1' && App::isProduction()) {
            Log::warning('Shopier test notification refused in production');

            throw new BadRequestHttpException('Shopier test notifications are not accepted in production.');
        }

        $orderRef = $this->matchOsbToOrder($data);

        if ($orderRef === null) {
            Log::critical('Shopier OSB could not be matched to an order', [
                'shopier_order_id' => $this->scalar($data, 'orderid'),
            ]);

            return null;
        }

        $amount = ShopierAmount::parse((string) $this->scalar($data, 'price'));

        // Tutar okunamıyorsa doğrulanamaz; doğrulanamayan ödeme 'paid' yapılmaz (K126).
        if ($amount === null) {
            Log::critical('Shopier OSB price could not be read', ['order_id' => $orderRef]);

            throw new BadRequestHttpException('Shopier OSB price is not readable.');
        }

        $rawCurrency = strtoupper((string) $this->scalar($data, 'currency'));

        return new PaymentNotification(
            $orderRef,
            OrderStatus::Paid,
            providerPaymentId: $this->paymentId((string) $this->scalar($data, 'orderid')),
            amountMinor: $amount,
            // Tanınmayan yazım olduğu gibi gider; tutar kontrolü uyuşmazlık sayar.
            currency: self::OSB_CURRENCY_ALIASES[$rawCurrency] ?? $rawCurrency,
        );
    }

    /**
     * 🔴 OSB -> bizim siparişimiz. TEK yerde, çünkü TAHMİN (§A-c cevapsız).
     *
     * OSB'nin API ile başlatılan ödemelerde bizim `platform_order_id`'mizi taşıyıp
     * taşımadığı resmi kaynakla doğrulanmadı. Bugünkü kural: `platform_order_id`
     * alanı varsa o, yoksa `orderid` — ikisi de yalnızca ULID ise. Shopier'in kendi
     * sayısal sipariş numarası ULID olamayacağı için yanlış siparişle eşleşmez;
     * eşleşmezse null (critical log). Elle doğrulama adım 7 bu metodu kesinleştirir.
     *
     * @param  array<mixed>  $data
     */
    private function matchOsbToOrder(array $data): ?string
    {
        foreach (['platform_order_id', 'orderid'] as $key) {
            $value = $this->scalar($data, $key);

            if ($value !== null && Str::isUlid($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * E9 (K125): ad, soyad, e-posta kullanıcıdan; gerisi config'teki yer tutucular.
     *
     * @return array<string, string>
     */
    private function buyerFields(User $user): array
    {
        $p = 'payment.providers.shopier.buyer_placeholders.';
        $address = Config::string($p.'address');
        $city = Config::string($p.'city');
        $country = Config::string($p.'country');
        $postcode = Config::string($p.'postcode');

        return [
            'buyer_name' => $user->first_name,
            'buyer_surname' => $user->last_name,
            'buyer_email' => $user->email,
            // Gün cinsinden hesap yaşı (Shopier'in risk değerlendirmesi için).
            'buyer_account_age' => (string) max(0, (int) $user->created_at?->diffInDays(now())),
            'buyer_id_nr' => Config::string($p.'id_nr'),
            'buyer_phone' => Config::string($p.'phone'),
            'billing_address' => $address,
            'billing_city' => $city,
            'billing_country' => $country,
            'billing_postcode' => $postcode,
            'shipping_address' => $address,
            'shipping_city' => $city,
            'shipping_country' => $country,
            'shipping_postcode' => $postcode,
        ];
    }

    /** Kolon 64 karakter; boş ya da daha uzun kimlik saklanmaz (bilgi kolonu, ödemeyi durdurmaz). */
    private function paymentId(string $value): ?string
    {
        return $value !== '' && strlen($value) <= 64 ? $value : null;
    }

    private function osbConfigured(): bool
    {
        return $this->osbUsername !== null && $this->osbKey !== null;
    }

    /** Boş ya da yalnızca boşluk olan sır YOK sayılır (K123). */
    private function secret(string $key): ?string
    {
        /** @var mixed $value */
        $value = Config::get('payment.providers.shopier.'.$key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @throws BadRequestHttpException */
    private function field(Request $request, string $key): string
    {
        /** @var mixed $value */
        $value = $request->input($key);

        if (! is_string($value) || $value === '') {
            throw new BadRequestHttpException("Shopier callback is missing '{$key}'.");
        }

        return $value;
    }

    /**
     * JSON alanını metin olarak okur (sayı ya da metin gelebilir); yoksa null.
     *
     * @param  array<mixed>  $data
     */
    private function scalar(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
