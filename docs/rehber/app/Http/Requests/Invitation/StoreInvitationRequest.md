# `app/Http/Requests/Invitation/StoreInvitationRequest.php`

> **Kod dosyası:** `app/Http/Requests/Invitation/StoreInvitationRequest.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.8 (2/3)
> **Ortak kurallar:** [`InvitationRequest.md`](InvitationRequest.md) — asıl anlatım orada

---

## 1. Bu sınıf yalnızca bir farkı taşıyor

```php
final class StoreInvitationRequest extends InvitationRequest
{
    /** @return list<string> */
    protected function catalogPresence(): array
    {
        return ['required'];
    }
}
```

30 küsur kuralın tamamı üst sınıfta. Burada değişen tek şey: **katalog
anahtarları oluşturma anında zorunludur.**

```php
'invitation.categoryId' => ['required', 'string', 'max:32'],
'invitation.imageTheme' => ['required', 'string', 'max:48'],
'invitation.palette'    => ['required', 'string', 'max:16'],
```

---

## 2. Neden bu üçü zorunlu, diğerleri değil?

3.2'de içerik kolonlarının hepsini `nullable` yapmıştık (autosave yarım veri
gönderir). Ama bu üç kolon **NOT NULL** kaldı:

```php
$table->string('category_id', 32);   // nullable DEGIL
$table->string('preset_id', 48);
$table->string('palette', 16);
```

Sebep: sihirbaz onları her zaman doldurur. Kullanıcı önce kategoriyi seçer
(1. adım), sonra temayı (2. adım); tasarım ekranına ulaştığında üçü de bellidir.
Yani "yarım veri" durumu bu üçü için oluşmaz.

Doğrulamada `required` olmasının kazancı **hata kalitesidir**:

| | `required` var | `required` yok |
|---|---|---|
| Eksik istek | `422` + `{"rule":"required"}` | `500` — SQL NOT NULL ihlali |
| Frontend ne yapabilir | Alanı işaretler | Hiçbir şey, genel hata |
| Log'da ne görünür | Doğrulama hatası | Yığın izi |

> **İlke:** Bir kısıt veritabanında varsa, uygulamada da olmalı — **daha erken
> ve daha anlaşılır** biçimde. Veritabanı kısıtı son savunma hattıdır, ilk değil.

---

## 3. Sırada ne var?

[`UpdateInvitationRequest.md`](UpdateInvitationRequest.md) — aynı kuralların
güncelleme hâli ve `sometimes` + `required` ikilisinin neden birlikte yazıldığı.
