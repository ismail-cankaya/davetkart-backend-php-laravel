# `app/Http/Controllers/Api/V1/GiftCodeController.php`

> **Faz:** 11, adım 11.8

---

Tek metot, üç satır:

```php
public function preview(PreviewGiftCodeRequest $request, PreviewGiftCodeAction $action): GiftCodeQuoteResource
{
    $user = $request->user();
    return new GiftCodeQuoteResource($action->handle($user, $request->code(), $request->tier()));
}
```

- Doğrulama FormRequest'te, iş Action'da, biçim Resource'ta (K3).
- Hata yolu yok: `GiftCodeException` `HasErrorCode` uyguladığı için `ApiExceptionRenderer`
  onu kendiliğinden 422 zarfına çevirir.
- Dönüş tipi `JsonResponse` değil Resource: Laravel 200 + `{data: ...}` zarfını kendisi
  kurar (CLAUDE.md §2).

**Neden "kodu kullan" diye ayrı bir uç yok?** Kod tek başına kullanılmaz, bir **satın almanın**
parçası olarak kullanılır. Ayrı bir `POST /gift-codes/redeem` ucu, "kod kullanıldı ama sipariş
yok" ara durumunu doğururdu. Kod checkout gövdesindeki `giftCode` alanıyla gelir ve sipariş ile
aynı transaction'da yazılır.

Rota: `POST /api/gift-codes/preview` (`/api/v1` değil, sürüm namespace'te: CLAUDE.md §2).
