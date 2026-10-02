# `app/Console/Commands/ListContactMessages.php` — `contact:list`

> **Faz:** 10 — Dilim F, adım 10.65
> **Test:** `ContactTest` → *contact:list* bölümü

---

## 1. Neden var?

İletişim formu Faz 8'den beri mesaj topluyor ama okumanın bir yolu yoktu: yönetim paneli yok,
veritabanına elle bağlanmak gerekiyordu. KVKK başvuruları da bu formdan geliyor
(`subject = kvkk`) ve yanıt süresi yasal olarak sınırlı. Okunamayan bir başvuru, süresi geçmiş
bir başvuru demek.

Plan önerisi: panel yerine bir komut. Sunucuya erişimi olan kişi okur; yeni bir kimlik doğrulama
yüzeyi açılmaz.

## 2. Kullanım

```powershell
php artisan contact:list                     # en yeni 20 mesaj
php artisan contact:list --limit=100
php artisan contact:list --since=2026-10-01  # bu günden (İstanbul) sonrakiler
php artisan contact:list --full              # mesajların tamamı (varsayılan: ilk 80 karakter)
```

| Sütun | Kaynak |
|---|---|
| Tarih | `created_at`, **İstanbul** saatiyle (`davetkart.default_timezone`) |
| Konu | `subject` ham değeri (`general`, `support`, `pricing`, `partnership`, `kvkk`) |
| Ad · E-posta · Mesaj | kullanıcının yazdığı, kontrol karakterleri görünür kılınmış |

## 3. 🔴 Terminal kontrol karakterleri

Mesaj misafirden geliyor ve terminale yazılıyor. Terminaller bazı karakter dizilerini **komut**
sayar:

| Dizi | Etki |
|---|---|
| `\e[2J` | Ekranı siler: önceki satırlar (başka mesajlar) görünmez olur |
| `\e]8;;https://…\e\` | Metni tıklanabilir bir bağlantıya çevirir: görünen ile gidilen adres farklı olabilir |
| `\x9B` (C1 CSI) | Bazı terminallerde `\e[` ile aynı |

`safe()` bu karakterleri yazdırmak yerine görünür kılıyor: `\e[2J` → `\u001b[2J`. Satır sonu ve
sekme kalıyor. Aralık C1'i de (U+0080–U+009F) kapsıyor; ilk sürümde yoktu ve mutasyon testi
(*"C1 aralığı yok"*) bunun için var. `users:normalize-emails`'teki `visible()` ile aynı refleks:
operatör gördüğü şeyin gerçekten orada olduğundan emin olmalı.

## 4. Kararlar

- **Neden Action değil?** Tetikleyen bir HTTP isteği yok; mantık tek bir komutta yaşıyor
  (`PurgeExpiredData.md` §3.5 ile aynı gerekçe).
- **Neden `--since` İstanbul?** Mesajlar İstanbul saatiyle gösteriliyor; *"1 Ekim'den beri"*
  diyen operatör İstanbul'daki 1 Ekim'i kastediyor. UTC'de 21:00–24:00 arası gelen mesajlar
  aksi hâlde yanlış güne düşerdi (test sınırın iki yanını sınıyor).
- **Silme yok.** Mesajlar 12 ay sonra `data:purge` ile siliniyor (K98). Bu komut yalnızca okur.

## 5. KVKK

Komut kişisel veriyi (ad, e-posta, mesaj) terminale yazar; log'a yazmaz. Yalnızca sunucuya
erişimi olan kişi çalıştırabilir.

## 6. Mutasyon kanıtı (1 Ekim 2026)

| Mutasyon | Kırılan |
|---|---|
| Kontrol karakterleri temizlenmesin | kontrol karakteri testi |
| C1 aralığı çıkarılsın | aynı test (`\u009b`) |
| En eski üstte | sıralama testi · limit testi |
| `--since` UTC | gün/limit testi |
| Tarih UTC gösterilsin | sıralama testi |
| `--limit` yok sayılsın | gün/limit testi |
| Mesaj kısaltılmasın | sıralama testi |
