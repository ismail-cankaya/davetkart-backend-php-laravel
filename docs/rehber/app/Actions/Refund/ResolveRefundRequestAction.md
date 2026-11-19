# `ResolveRefundRequestAction` · `RefundRequestResolvedNotification`

> **Faz:** 14, adım 14.6 · **Karar:** K141 · **Çağıran:** `refunds:approve`, `refunds:reject`
> **Faz 18.4 (`SEC-02`):** K167, onay anında yeniden sınama (§5–§8) · **Test:** `tests/Feature/RefundApprovalRecheckTest.php`

## 1. Ne yapar?

| Karar | Talep | Sipariş | Hak |
|---|---|---|---|
| Onay | `approved` + `resolved_at` + not | `paid → refunded` | Sayaçta "iade edildi" |
| Onay, hak **kullanılmış** (Faz 18.4) | değişmez (`requested`) | değişmez | Komut hata verir; talep ret ile kapatılır |
| Ret | `rejected` + `resolved_at` + not | değişmez | Yeniden kullanılabilir |

## 2. 🔴 Para burada gönderilmez

Shopier'in iade API'si entegre değil. Yönetici **önce** Shopier panelinden iade eder, **sonra**
komutu çalıştırır. Kod tersini yapamaz: neyin gönderildiğini bilmiyor. Bu yüzden onay
komutu soruyla başlar (RefundCommands.md §2).

## 3. Kilitler ve iki yarış

- **Kullanıcı aynı anda "Vazgeç" der:** talep satırı kilitli; ikincisi kapalı talebi görür.
- **Shopier'in iade bildirimi aynı anda gelir:** sipariş satırı kilitli. Bildirim önce
  geldiyse sipariş zaten `refunded`; Action bunu hata saymaz, talebi kapatır.

Durum geçişleri iki ayrı makineden sorulur: `RefundRequestStatus::canTransitionTo()` ve
`OrderStatus::canTransitionTo()`. Kod "zaten onaylı mı?" diye elle sormaz.

## 4. Mail commit'ten SONRA

Bildirim transaction içinde kuyruğa atılsaydı ve transaction geri alınsaydı, kullanıcıya
"iadeniz onaylandı" maili gider ama kayıt değişmemiş olurdu. Bildirim model değil **değer**
taşır (plan, tutar, not): kuyruk işi çalıştığında sipariş değişmiş olabilir; mail kararın
anını anlatır. Hesabı silinmiş kullanıcıda (`user_id = NULL`) mail gönderilmez.

---

## 🆕 Faz 18.4 (`SEC-02` · K167): onay kendi anına bakar

## 5. Sorun: kontrol anı ≠ kullanım anı (TOCTOU)

Talep açılırken `RefundEligibility` "bu hak kullanılmamış" dedi. Onay o cevabı **yeniden sormadan**
kullanıyordu. Arada geçen sürede (saatler, günler) cevap bayatlar. İki somut yol:

1. **Düzeltmeden önce açılmış talep.** `SEC-02` kapanmadan önce yayınlanıp silinmiş bir davetiyenin
   hakkı için talep açılmış olabilir (denetimin P47 sondası tam olarak bunu yaptı). Kural artık o
   talebi açtırmaz, ama açık duran talep onaylanabilirdi.
2. **Donmuş hakkı aşan eşzamanlı yayın.** Açık talep hakkı dondurur (K138): `ClaimReleasedOrderAction`
   açık talebi olan siparişi bağlamaz. Ama o süzgeç bir alt sorgudur. "İptal et" ve "yayınla" aynı
   anda gelirse ve iptal önce kilitlerse, yayın kilidi bekler; kilit açıldığında sipariş satırı
   **değişmemiştir** (talep ayrı bir tabloya yazıldı), PostgreSQL satırı yeniden sınamaz ve yayın
   siparişi bağlar. Sonuç: yayınlanmış bir davetiye ve aynı hak için açık bir iade talebi.

İkisinin de çaresi aynı: kararı veren adım, kararın dayandığı gözlemi **kendi anında** yeniler.

```php
if ($decision === RefundRequestStatus::Approved) {
    $this->ensureRightIsStillUnused($request, $order);   // sipariş satırı kilitliyken
    $this->refundOrder($order);
}
```

## 6. Ne sorulur, ne sorulmaz?

`RefundEligibility::usageBlockerFor()`: hak bir davetiyede mi, yanmış mı, bir davetiyeyi yayınlamış
mı. Beş koşulun tamamı (`blockerFor()`) **sorulmaz**:

| Koşul | Onayda | Neden |
|---|---|---|
| Açık talep var | sorulmaz | Onaylanan şey o talebin kendisi |
| 14 günlük pencere | sorulmaz | Kullanıcı süresinde istedi; yöneticinin gecikmesi onu cezalandırmamalı |
| Ödenmiş / ücretli | sorulmaz | `OrderStatus::canTransitionTo()` zaten sınar; tutar değişmez |
| **Hak kullanıldı mı** | **sorulur** | Talepten sonra değişebilen tek şey |

**Zaten `refunded` olan sipariş istisnadır.** Shopier'in iade bildirimi önce geldiyse para geri
gitmiştir; korunacak bir şey kalmadı. Talep kapanır (Faz 14'teki davranış, §3).

## 7. Reddedilince ne olur?

Action `RuntimeException` fırlatır; transaction geri alınır, talep `requested` kalır, sipariş `paid`
kalır, mail gitmez. Durum makinesine yeni bir durum **eklenmedi**: kullanılmış hakkın talebi var
olan yolla, `refunds:reject --note="..."` ile kapanır ve hak sahibinde kalır.

```
Refund request 01J… cannot be approved: the right has been used (used).
Do not send the money; close the request with refunds:reject.
```

Parantezdeki değer `RefundBlocker`: `in_use` (şu an bir davetiyede ya da yanmış) ya da `used`
(serbest ama bir davetiyeyi yayınlamış).

🔴 **Sıra sorunu.** K141'e göre yönetici **önce** parayı Shopier'den gönderir, **sonra** komutu
çalıştırır. Reddi yalnızca burada yapsaydık para çoktan gitmiş olurdu. Bu yüzden üç katman var:

| Katman | Ne zaman | Kim |
|---|---|---|
| `refunds:list` "Yayın" sütunu | Yönetici karar vermeden önce | [`RefundCommands.md`](../../Console/Commands/RefundCommands.md) §4 |
| `refunds:approve` ön denetimi | "Parayı gönderdiniz mi?" sorusundan **önce** | aynı, §2 |
| Bu Action | Kilit altında, kesin karar | burası |

⚠️ **Açık kalan:** para yine de gönderildiyse ve Shopier iade bildirimi gelmezse sipariş `paid`
kalır ve talep onaylanamaz. Bilinçli olarak "yine de onayla" bayrağı **eklenmedi**: yeni denetimin
etrafından dolanan bir yol olurdu. Karar İsmail'in (`FAZ-18.md` §18.4 "Açık kalanlar").

## 8. Testler ve mutantlar (`RefundApprovalRecheckTest`)

"Talep açıkken yayın" sırayla üretilemez (donmuş hak 402 alır, `RefundRequestTest`); yalnızca
eşzamanlı iki istekle oluşur. Testler o yarışın **sonucunu** satıra doğrudan yazar.

| Test | Kanıtladığı | Öldürdüğü mutant |
|---|---|---|
| `approval_is_refused_when_the_right_was_published_while_the_request_was_open` | 🔴 Bağlı + damgalı hak: onay yok, hiçbir şey değişmez, mail yok (`in_use`) | Yeniden sınamayı silmek |
| `approval_is_refused_for_a_released_right_that_had_published` | 🔴 Düzeltmeden önce açılmış talep (`used`) | aynı |
| `the_approve_command_stops_before_asking_about_the_money` | Komut soruyu sormadan durur | Ön denetimi `if (false)` yapmak |
| `a_request_for_a_used_right_can_still_be_rejected` | Ret yolu açık; hak sahibinde kalır | — |
| `an_already_refunded_order_is_closed_even_if_the_right_was_used` | Para zaten geri gitmişse talep kapanır | `Refunded` istisnasını silmek |
| `an_unused_right_is_still_approved` | Regresyon: K139, K141 | — |

Tuzak: `try { …; $this->fail(…); } catch (RuntimeException $e)` yazma. PHPUnit'in `fail()` hatası da
bir `RuntimeException`'dır; yakalanır ve test yanlış nedenle kırmızı (ya da yeşil) olur. Testler
istisnayı bir değişkene alıp `try`'ın dışında sınar.

```powershell
php artisan test --filter=RefundApprovalRecheckTest
```

Mutant: `$this->ensureRightIsStillUnused($request, $order);` satırını sil → ilk iki test kırmızı.
