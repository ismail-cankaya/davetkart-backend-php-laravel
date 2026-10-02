# `app/Http/Requests/Rsvp/UpdateRsvpRequest.php`

> **Faz:** 10 — Dilim F, adım 10.59 · **Karar:** **K101**
> **Önce oku:** [`StoreRsvpRequest.md`](StoreRsvpRequest.md) (kuralların gerekçeleri)

---

İlk gönderimle **aynı** kurallar (ortak taban `RsvpRequest`) ve bir alan daha:

```php
'editCode' => ['required', 'string', 'size:'.RsvpEditCode::LENGTH],
```

| Karar | Neden |
|---|---|
| Taban sınıf (`RsvpRequest`) | `InvitationRequest` ile aynı desen. Kurallar iki dosyada kopyalansaydı biri değişip öteki eskirdi (C3) |
| Honeypot **yok** | Bu uca kodu bilmeyen gelemez; honeypot ilk gönderimin (botlara açık) savunması |
| `size:40` | Biçimsiz kod veritabanına gitmeden 422 alıyor |
| Kodun **doğruluğu** burada sorulmuyor | Doğruluk davetiyeye ve yanıta bağlı; FormRequest ikisini de çözmemiş (H10). `UpdateRsvpAction` soruyor |

Yanlış ama biçimli bir kod 422 değil **404** alır (`UpdateRsvpAction.md` §3.1).
