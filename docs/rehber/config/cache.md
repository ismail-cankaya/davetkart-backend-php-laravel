# `config/cache.php` — Kılavuz

Cache = pahalı bir hesabın sonucunu saklayıp bir daha hesaplamamak.
Bu dosya "nereye saklansın?" sorusunu cevaplar.

## Sürücüler

| Sürücü | Nerede saklar | Hız | Ne zaman |
|---|---|---|---|
| `array` | RAM, istek bitince silinir | En hızlı | Testlerde |
| `file` | `storage/framework/cache/` | Hızlı | Tek sunucu, geliştirme |
| `database` | `cache` tablosu | Orta | Ek altyapı istemiyorsan |
| `redis` | Redis sunucusu | Çok hızlı | Üretim, çok sunucu |

## DavetKart'ta cache neden kritik?

Davetiye linki WhatsApp grubuna düşer; **500 kişi 2 dakikada açar.** Veri ise
neredeyse hiç değişmez. Bu, cache'in en verimli olduğu senaryodur
(*okuma-ağırlıklı yük*).

```php
Cache::remember("davetkart:invitation:{$slug}", $ttl, fn () => /* sorgu */);
```

Cache olmadan 500 sorgu, cache ile 1 sorgu.

## 🔴 Yerelde `database` yerine `file`

`.env` şu an `CACHE_STORE=database`. Bizde veritabanı **SQLite** ve SQLite'ta
yazma işlemi dosyayı kilitler. Cache yazmaları, asıl uygulama sorgularıyla aynı
dosya için yarışır — cache hız kazandırmak yerine kaybettirir.

Öneri: yerelde `CACHE_STORE=file`, üretimde Redis.

## Cache tazeleme stratejisi

İki yöntem vardır:

1. **Zaman tabanlı (TTL):** "6 saat sonra yenilensin."
2. **Olay tabanlı:** "Davetiye güncellenince cache'i sil."

Biz **ikincisini** kullanıyoruz: `InvitationPublished` / `InvitationUpdated`
event'leri `ClearInvitationCache` listener'ını tetikleyecek (Adım 9).
`config('davetkart.cache.public_invitation_ttl')` yalnızca **emniyet ağıdır** —
bir event kaçarsa veri en fazla 6 saat bayat kalır.

## `prefix`

Aynı Redis sunucusunu başka uygulama da kullanabilir; önek çakışmayı önler.
Bizde iş anahtarlarının öneki `config('davetkart.cache.key_prefix')` → `davetkart`.

## Dikkat

- `Cache::remember` içindeki closure **yalnızca cache boşken** çalışır.
- Cache'e Eloquent modeli koymak yerine, mümkünse Resource çıktısı (dizi) koymak
  daha güvenlidir: model yapısı değişince eski cache kaydı hataya yol açmaz.
- `php artisan cache:clear` tüm cache'i siler.
