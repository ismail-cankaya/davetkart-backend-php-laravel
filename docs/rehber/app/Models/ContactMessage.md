# `app/Models/ContactMessage.php`

> **Faz:** 8, dosya 8.13 · **Kurallar:** K14 · E7 · K40

---

## 1. `ip_hash` neden `#[Fillable]` listesinde yok?

`Rsvp` modelindeki ayni gerekce: onu **sunucu hesaplar**; istemciden gelen
bir "IP" veri degil **yalandir**. Beyaz liste burada bir konfor degil
savunmanin kendisidir — bu tablo **auth'suz yazma yolunun** ucundadir.

Diger dort alan (`name`, `email`, `subject`, `message`) toplu atanabilir
cunku dordu de kullanicinin **beyanidir** ve `ContactRequest` bicimlerini
dogrulamistir.

---

## 2. Neden `bigint` id?

**K40**'in ayirt edici sorusu: *bu kimlik bir URL'de geciyor mu?* Gecmiyor —
form yazar, bugun kimse okumaz (bir listeleme ucu yok). ULID'in gerekcesi
"tahmin edilemezlik"ti ve tahmin edilecek bir adres yok.

---

## 3. `email` neden UNIQUE degil?

Ayni kisi birden fazla kez yazabilir **ve yazmali**. UNIQUE koymak, ikinci
mesaji bir veritabani hatasina cevirirdi. `users.email`'deki UNIQUE bir
**kimlik** kuralidir; burada kimlik yok, **beyan** var.

---

## 4. Cast

```php
'subject' => ContactSubject::class,
```

Sihirli string yasagi (`CLAUDE.md` §1) modelin okuma tarafinda da gecerli:
`$message->subject` bir enum doner, string degil.
