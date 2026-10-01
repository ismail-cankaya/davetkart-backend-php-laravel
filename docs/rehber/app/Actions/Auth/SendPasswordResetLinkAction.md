# `app/Actions/Auth/SendPasswordResetLinkAction.php`

> **Faz:** 10 — Dilim D, adım 10.32
> **Çağıran:** `AuthController::forgotPassword` → **202**, gövdesiz
> **Mail:** [`ResetPasswordNotification.md`](../../Notifications/ResetPasswordNotification.md) (10.34)

## 1. Tek satır, üç sonuç, sıfır bilgi

```php
Password::broker()->sendResetLink(['email' => $email]);
```

Laravel'in parola aracı (*password broker*) üç sonuçtan birini döndürür:

| Sonuç | Ne oldu |
|---|---|
| `passwords.sent` | Token üretildi, `User::sendPasswordResetNotification()` çağrıldı |
| `passwords.user` | Bu e-postayla kullanıcı yok, hiçbir şey olmadı |
| `passwords.throttled` | Aynı e-postaya son 60 sn içinde zaten gönderildi (`auth.passwords.users.throttle`) |

Action sonucu **bilerek döndürmüyor** (`void`). Controller hangisi olursa olsun aynı
202'yi döner. Sonuç dönseydi, yarın biri onu kullanmak isteyebilirdi (*"kullanıcı
yoksa 404 dönelim, daha net olur"*) ve sıfırlama formu bir hesap tarayıcısına
dönerdi. Bir bilgiyi sızdırmamanın en güvenli yolu onu hiç taşımamak.

Mutasyonla kanıtlandı: kayıtsız e-postada 404 dönen bir sürüm
`a_registered_and_an_unknown_email_get_the_same_answer`'ı kırıyor.

## 2. 🔴 Zaman farkı: neden kuyruk şart?

Kayıtlı e-postada mail **senkron** gönderilseydi:

```
kayıtlı:   token yaz + SMTP'ye bağlan + gönder  → ~1500 ms
kayıtsız:  bir SELECT                           →    ~5 ms
```

Yanıt gövdesi aynı olsa bile **süre** hesabın varlığını söylerdi. Bildirim
`ShouldQueue` olduğu için kayıtlı e-postada yalnızca bir token satırı ve bir kuyruk
kaydı yazılıyor. Fark birkaç milisaniyeye iniyor; sıfırlanmıyor ama ölçülmesi çok
daha zor. Bu, `LoginUserAction.md` §5'teki zamanlama savunmasının kardeşi.

## 3. Hız sınırı

Rota `throttle:auth` grubunda: e-posta + IP başına 5/dk, IP başına 20/dk. İki
tehdide karşı: hesap tarama ve **mail bombalama** (bir kurbanın gelen kutusunu
sıfırlama mailleriyle doldurmak). Aracın kendi 60 saniyelik e-posta başına sınırı
ikinci bir katman: aynı adrese dakikada en fazla bir mail gider.
