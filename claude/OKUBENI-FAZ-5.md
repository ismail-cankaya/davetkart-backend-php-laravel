# `claude/` — Faz 5 sonunda eklenen dosyalar

> **Tarih:** 28 Ağustos 2026 · **Ekleyen:** Faz 5 oturumu

---

## 🔴 Önce bu uyarıyı oku

Bu oturumda çalışılan depo kopyasında **`claude/` klasörü yoktu**. Klasör
git'te izlenmiyor ve `.gitignore`'da da değil — yani her makinede yerel olarak
duruyor ve sürüm kontrolüne hiç girmemiş.

Sonuçları:

1. **`claude/PHP-LARAVEL-SETUP.md` bu oturumda OKUNAMADI.** İçindeki 48 karar
   ve 41 ders görülmedi. Bu yüzden o dosya **yeniden yazılmadı** — yazılsaydı,
   görülmemiş içerik silinirdi.
   Yerine [`PHP-LARAVEL-SETUP-EK-FAZ-5.md`](PHP-LARAVEL-SETUP-EK-FAZ-5.md)
   yazıldı: master dosyaya **elle eklenecek** bölümler.

2. Buradaki dosyalar **çakışmayacak adlarla** seçildi (`PROMPT.md` değil
   `PROMPT-FAZ-6.md`, `Notlar/03` değil `Notlar/04`). Sebebi: bu dal
   `git checkout` edildiğinde, aynı adlı **izlenmeyen** yerel bir dosya varsa
   git checkout'u reddeder ve *"untracked working tree files would be
   overwritten"* der.

3. `claude/` artık **git'te izleniyor**. Bu bir alışkanlık değişikliğidir:
   klasörün iki bilgisayar arasında senkron kalması için gerekliydi. İstemiyorsan
   `.gitignore`'a ekleyip `git rm --cached -r claude/` diyebilirsin — ama o
   zaman bu dosyalar yine yalnızca tek makinede kalır.

---

## Bu klasördeki dosyalar

| Dosya | Kimin için | Ne işe yarar |
|---|---|---|
| [`FAZ-5-DEVIR.md`](FAZ-5-DEVIR.md) | 🤖 **Yeni AI asistanı** | Projenin bugünkü tam durumu. Yeni bir sohbete başlayan asistanın okuyacağı ilk dosya |
| [`PROMPT-FAZ-6.md`](PROMPT-FAZ-6.md) | 🤖 Yeni AI asistanı | **Kopyala-yapıştır** başlangıç mesajı — Faz 6 (Media) için |
| [`PHP-LARAVEL-SETUP-EK-FAZ-5.md`](PHP-LARAVEL-SETUP-EK-FAZ-5.md) | 👤 İsmail | Master devir dosyasına elle eklenecek Faz 5 bölümleri |
| [`Notlar/04-FAZ-5-FRONTEND-YAPILACAKLAR.md`](Notlar/04-FAZ-5-FRONTEND-YAPILACAKLAR.md) | 👤 + 🤖 | LCV modülünün frontend borcu — 7 dosya |

---

## Yapılacaklar (İsmail)

- [ ] `PHP-LARAVEL-SETUP-EK-FAZ-5.md` içeriğini master `PHP-LARAVEL-SETUP.md`'ye işle
- [ ] `Notlar/03-FRONTEND-YAPILACAKLAR.md`'ye `Notlar/04`'ün özetini ekle (ya da
      04'ü referans olarak bağla)
- [ ] `claude/` klasörünün git'te kalıp kalmayacağına karar ver
