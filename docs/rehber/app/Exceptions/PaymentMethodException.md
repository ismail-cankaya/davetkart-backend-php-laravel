# `app/Exceptions/PaymentMethodException.php` — kapalı ya da yanlış yazılmış yöntem

> **Faz:** 13, adım 13.1 · **Karar:** K134
> **Test:** `PaymentMethodsTest`, `PaymentMethodGateTest`

---

## 1. İki kurucu, iki ayrı dünya

| Kurucu | Kod | HTTP | Ne zaman? | Kim ilgilenir? |
|---|---|---|---|---|
| `disabled($method)` | `PAYMENT_METHOD_DISABLED` | 403 | Yöntem `.env`'de **bilerek** kapatılmış | Kullanıcı ("bu yöntem şu an yok") |
| `misconfigured($token)` | `PROVIDER_UNAVAILABLE` | 503 | `PAYMENT_METHODS` tanımsız bir kelime içeriyor | Sen (izleme alarmı) |

İkisini tek kodda birleştirmek cazip ama yanlış olurdu. Birincisi bir **iş kararı**,
ikincisi bir **yapılandırma hatası**. Biri 4xx (istemci tarafı: bu isteği böyle tekrar
etme), diğeri 5xx (sunucu tarafı: sorun bizde). İzleme grafiğinde 5xx'ler alarm çalar,
4xx'ler çalmaz. Kapattığın kart yöntemi her denemede alarm çaldırsaydı alarm anlamını
yitirirdi.

## 2. Neden 403, 402 değil?

`402 PAYMENT_REQUIRED` "önce öde" der. Kapalı yöntemde kullanıcı tam da ödemeye çalışıyor;
eksik olan bir ödeme değil, işletmenin açtığı bir kapı. 403 "kimliğin var ama bu işlem
yasak" demektir ve LCV kotası gibi kullanıcının aşamayacağı bir sınırı anlatır.

## 3. `method` parametresi

`errorParams()` yalnızca `disabled()` için `['method' => 'card']` döner ve `ErrorCode`'un
beyaz listesinden geçer (H9). Frontend bu bilgiyle doğru metni seçer ("Kartla ödeme şu
an kapalı"). `misconfigured()` kelimenin kendisini yanıta **koymaz**: yanlış yazılmış bir
ortam değişkeni iç bir ayrıntıdır (H8), log'a gider.
