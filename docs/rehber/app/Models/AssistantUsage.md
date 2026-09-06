# `app/Models/AssistantUsage.php`

> **Faz:** 8, dosya 8.8 · **Kurallar:** L3 · E1 · E2 · K40

---

## 1. Bu bir sayac, bir gunluk degil

Tabloda **kullanicinin ne yazdigi yok**. Yalnizca:

```
user_id · usage_date · message_count
```

Iki gerekce:

| Gerekce | Aciklama |
|---|---|
| **KVKK** | Saklanmayan veri sizamaz. Bir `assistant_messages` tablosu sistemdeki en hassas metin deposu olurdu — sohbet icerigi cogu zaman kisisel bilgi tasir |
| **E1 ailesi** | Kotanin sorusu "kac mesaj", "hangi mesaj" degil. Bir sinir icin gereken **en az veri** saklanir |

---

## 2. 🔴 Neden `throttle` kovasi degil de tablo? (D1 sapmasi)

| | `RateLimiter` kovasi | `assistant_usages` |
|---|---|---|
| Nerede durur | Cache (file / Redis) | Veritabani |
| `cache:clear` | **Sifirlanir** | Durur |
| Deploy / Redis restart | **Sifirlanir** | Durur |
| Denetlenebilir mi | Hayir | Evet ("bu kullanici gercekten 30 gonderdi") |
| Amaci | Kotuye kullanim | **Fatura** |

`config/davetkart.php`'nin kendi yorumu soyluyordu: *"AI cagrisi ucretli;
kotasiz birakmak finansal risktir."* Bir **fatura kontrolu** cache'e emanet
edilmez.

Hiz siniri **ayrica** duruyor (`throttle:assistant`) — **L3**: limit "ne
siklikta"ya, kota "ne kadar"a bakar.

---

## 3. Sema kararlari

| Karar | Gerekce |
|---|---|
| `bigint` id, ULID degil | **K40**'in ayirt edici sorusu: bu kimlik bir URL'de geciyor mu? Gecmiyor. `timeline_events` de ayni sebeple bigint |
| `UNIQUE(user_id, usage_date)` | Kotanin **asil** korumasi: es zamanli iki istek iki ayri sayac acamaz. Ayni kisit sorgunun indeksi (**E2**) |
| `CHECK (message_count >= 0)` | PostgreSQL'de **UNSIGNED yoktur**; `unsignedInteger` negatif kabul eder ve negatif sayac kotayi genisletirdi |
| `date`, `timestamp` degil | Kota "bugun kac mesaj" sorusudur; saat tasimak gun sinirini bulaniklastirirdi (**E8**) |
| `cascadeOnDelete` | Sayac tamamen kullaniciya ait; hesap silinince gider (KVKK) |

---

## 4. `#[Fillable([])]` neden bos?

Bu satirin hicbir alani istemcinin mali degil. Zaten toplu atamayla da
yazilmiyor: `AskAssistantAction` sayaci **tek bir SQL deyimiyle** artiriyor.
`Order` modelindeki ayni gerekce.

---

## 5. Kendin dene

```php
// php artisan tinker
$u = App\Models\User::first();
App\Models\AssistantUsage::where('user_id', $u->id)->get();

// Kisiti sina — model KATMANI atlanarak:
DB::table('assistant_usages')->insert([
  'user_id' => $u->id, 'usage_date' => today(), 'message_count' => -1,
  'created_at' => now(), 'updated_at' => now(),
]);
// QueryException: assistant_usages_message_count_check
```
