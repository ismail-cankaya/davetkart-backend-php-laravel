# `2026_10_26_120000_add_user_agent_to_personal_access_tokens_table`

> **Faz:** 14, adım 14.9 · **Karar:** K143

Sanctum'un token tablosuna tek kolon: `user_agent` (255, nullable). Oturum listesinde
"Chrome · Windows" yazabilmek için.

## 🔴 Neden IP yok?

"Aktif oturumlar" ekranlarının çoğu IP ve yaklaşık konum gösterir. Burada bilerek yok:

| Seçenek | Sorun |
|---|---|
| Ham IP | Kişisel veri; CLAUDE.md §3 ham IP saklamayı yasaklıyor |
| Hash'lenmiş IP (`IpHasher`) | Kullanıcıya hiçbir şey söylemez — `a3f9…` kimseye bir cihaz hatırlatmaz |
| Konum (GeoIP) | Bir dış servis veya veritabanı + daha fazla kişisel veri |

Cihazı tanıtmak için tarayıcı + işletim sistemi yeter. **Veri minimizasyonu** (KVKK md. 4):
amaca yetecek kadar veri.

## Sanctum'un tablosuna dokunmak riskli mi?

Sanctum bu tabloyu kendisi yönetmiyor; migration projeye **yayımlanmış** (`vendor:publish`) ve
bizim. Yeni bir nullable kolon Sanctum'un hiçbir sorgusunu etkilemez. Eski token'larda NULL
kalır: "bilinmeyen cihaz".
