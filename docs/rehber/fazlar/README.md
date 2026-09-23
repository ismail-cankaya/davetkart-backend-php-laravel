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
| **5** | RSVP / LCV | ⚠️ kod ✅ · `composer check` ✅ · **elle doğrulama bekliyor** | [FAZ-5.md](FAZ-5.md) | 🔴 [16 adım](FAZ-5-ELLE-DOGRULAMA.md) |
| **6** | Media | ⚠️ kod ✅ · `composer check` ✅ · **elle doğrulama bekliyor** | [FAZ-6.md](FAZ-6.md) | 🔴 [18 adım](FAZ-6-ELLE-DOGRULAMA.md) |
| **7** | Ödeme ve paywall | ⚠️ kod ✅ · `composer check` ✅ · **elle doğrulama bekliyor** | [FAZ-7.md](FAZ-7.md) | 🔴 [20 adım](FAZ-7-ELLE-DOGRULAMA.md) |
| **8** | AI asistan + iletişim | ⚠️ kod ✅ · `composer check` ✅ · **elle doğrulama bekliyor** | [FAZ-8.md](FAZ-8.md) | 🔴 [betik](FAZ-8-ELLE-DOGRULAMA.md) |
| **9** | Üretim hazırlığı | ⚠️ kod ✅ · `composer check` ✅ (238 test) · **elle doğrulama bekliyor** | [FAZ-9.md](FAZ-9.md) | 🔴 [22 adım](FAZ-9-ELLE-DOGRULAMA.md) |
| 9+ | Faz 9 sonrası eklemeler (17–21 Eylül) | ⚠️ kod ✅ · `composer check` **kayıt yok** | `docs/07` §4 Faz 9 | — |

> 🔴 23 Eylül 2026: backend "bitti" gözden geçirmesi →
> [`../../../claude/GOZDEN-GECIRME-RAPORU.md`](../../../claude/GOZDEN-GECIRME-RAPORU.md)

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
