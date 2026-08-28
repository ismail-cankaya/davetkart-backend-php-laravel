# `docs/rehber/fazlar/` — Faz Özetleri

Her faz tamamlandığında buraya bir özet yazılır (kural **B3**).

Dosya bazlı kılavuzlar (`docs/rehber/app/...`, `docs/rehber/config/...`) *"bu
dosya neden böyle yazıldı"* sorusunu cevaplar. Buradaki faz özetleri ise
*"bu aşamada ne hedefledik, ne kurduk, hangi kurallar doğdu"* sorusunu.

## İçerik

| Faz | Konu | Durum | Özet | Elle doğrulama |
|---|---|---|---|---|
| **0** | Zemin ve kalite kapıları | ✅ | [FAZ-0.md](FAZ-0.md) | — |
| **1** | İlk endpoint + hata zarfı | ✅ | [FAZ-1.md](FAZ-1.md) | — |
| **2** | Auth özellik dilimi | ✅ | [FAZ-2.md](FAZ-2.md) | [13 adım](FAZ-2-ELLE-DOGRULAMA.md) |
| **3** | Invitation CRUD | ✅ | [FAZ-3.md](FAZ-3.md) | [betik](FAZ-3-ELLE-DOGRULAMA.md) |
| **4** | Public davetiye + cache | ✅ | [FAZ-4.md](FAZ-4.md) | [15 adım](FAZ-4-ELLE-DOGRULAMA.md) |
| **5** | RSVP / LCV | ⚠️ **DOĞRULANMADI** | [FAZ-5.md](FAZ-5.md) | 🔴 [16 adım](FAZ-5-ELLE-DOGRULAMA.md) |
| 6 | Media | ⬜ | — |
| 7 | Ödeme ve paywall | ⬜ | — |
| 8 | AI asistan + iletişim | ⬜ | — |
| 9 | Üretim hazırlığı | ⬜ | — |

## Her özette bulunacaklar

1. **Fazın amacı** — tek cümle + neden bu sırada
2. **Öğrenme hedefleri** — soru → kılavuz eşlemesi
3. **Hedefler ve sonuçlar** tablosu
4. **Yazılan dosyalar** ve kılavuz bağlantıları
5. 🔴 **Kurulan kurallar** — fazın kalıcı çıktısı
6. **Alınan kararlar** ve geçersiz kılınanlar
7. **Bir sonraki faza devir**

> ⚠️ **Faz 5 istisnadır.** Kodu yazıldı ama `composer check` hiç koşmadı
> (gerekçe: [FAZ-5.md](FAZ-5.md) §0). Durum alanı bilerek "tamamlandı" değil
> "doğrulanmadı" yazıyor — **B7**: faz özetindeki durum, gerçekten koşan bir
> komuta dayanır. Faz 5 ancak elle doğrulama betiği yeşil bittiğinde kapanır.

> Özetler **kuralları** kaydeder, süreç anlatmaz. "Şunu deneyip vazgeçtik"
> türü anlatım buraya girmez; yalnızca **yürürlükteki karar ve gerekçesi** yazılır.
> Geçersiz kılınan kararlar tek satırla, yerine geçenle birlikte listelenir.
