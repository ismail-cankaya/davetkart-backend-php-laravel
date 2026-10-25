# `deploy/sudoers/davetkart-deploy`

> **Ne işe yarar:** `deploy` kullanıcısına, `deploy.sh`'ın ihtiyaç duyduğu **üç
> komutu** parolasız çalıştırma izni verir; başka hiçbir şeyi değil. Sunucuda
> `/etc/sudoers.d/davetkart-deploy` olarak durur (root:root, `440`).

## 1. Sorun neydi?

`deploy` kullanıcısı Y5.3'te bilerek **sudo yetkisiz** açıldı. Ama `deploy.sh` üç
yerde başka bir kullanıcı adına iş yapmak zorunda:

| Komut | Kimin adına | Neden |
|---|---|---|
| `php artisan ...` | `www-data` | Önbellek ve log dosyaları `www-data`'nın olsun; yoksa PHP-FPM kendi log dosyasına yazamaz |
| `chmod 640 bootstrap/cache/config.php` | `www-data` | Dosyayı `www-data` üretti, iznini de o değiştirebilir |
| `systemctl reload php8.5-fpm` | `root` | OPcache temizlensin, yeni kod yüklensin |

İlk sürümde betik `sudo -v` ile parola soruyordu. `deploy`'un parolası yok, yani
bu yol hiç çalışmazdı. İki seçenek vardı:

| Seçenek | Sonuç |
|---|---|
| `deploy`'a tam sudo (`ALL=(ALL) ALL`) | `deploy` fiilen root olur. Deploy anahtarını ele geçiren sunucuyu ele geçirir |
| **Sınırlı sudoers** ✅ | Yalnızca listelenen komutlar. Geri kalan her `sudo` reddedilir |

## 2. Satır satır

```
deploy ALL=(www-data) NOPASSWD: /usr/bin/php, /usr/bin/chmod
```
- `deploy`: kural kimin için.
- `ALL=`: hangi makinede (tek sunucu, önemsiz).
- `(www-data)`: **kimin adına** çalıştırabilir. `root` değil.
- `NOPASSWD:`: parola sormaz (betik etkileşimsiz çalışır).
- Komutlar **tam yolla** yazılır. `php` yazılsaydı PATH'teki herhangi bir `php`
  adlı dosya eşleşebilirdi.

**`php` için argüman sınırı neden yok?** `php` ile her şey çalıştırılabilir, yani
argümanı sınırlamak sahte bir güven verirdi. Asıl soru şu: `deploy`, `www-data`
olarak kod çalıştırabilmeli mi? Zaten çalıştırabiliyor: `www-data`'nın
(PHP-FPM'in) çalıştırdığı kodun **sahibi** `deploy`. Bu kural yeni bir yetki
vermiyor, var olan ilişkiyi açıkça yazıyor. Ters yön (`www-data` → `deploy`)
kapalı; asıl güvenlik sınırı orası (bkz. Y8.2b izinleri).

```
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.5-fpm
```
- Komutun **argümanları da yazıldığı için** sudo birebir eşleşme arar.
  `systemctl stop nginx`, `systemctl reload php8.5-fpm ; bash` gibi her türev
  reddedilir. root olarak çalışabilen tek şey bu servis yeniden yüklemesi.

## 3. Betikte `sudo -n`

`deploy.sh` her `sudo` çağrısında `-n` (non-interactive) kullanır: yetki yoksa
**parola sormadan hemen hata verir**. `-n` olmasaydı eksik bir kural, betiği
ekranda kimsenin cevaplayamayacağı bir parola isteminde asılı bırakırdı. Betik
başta `sudo -n -u www-data /usr/bin/php -v` ile kuralın kurulu olduğunu da
doğrular ve anlaşılır bir mesajla durur.

## 4. Kurulum (sunucuda, `ubuntu` ile)

```bash
sudo visudo -cf deploy/sudoers/davetkart-deploy        # önce sözdizimi
sudo install -m 440 -o root -g root deploy/sudoers/davetkart-deploy /etc/sudoers.d/davetkart-deploy
sudo visudo -c                                          # tüm sudoers yeniden kontrol
sudo -l -U deploy                                       # deploy neye izinli?
```

🔴 **Sudoers dosyasını asla doğrudan `nano` ile düzenleme.** Sözdizimi hatalı bir
sudoers dosyası `sudo`'yu **tamamen** kilitler; o zaman `ubuntu` da yönetici
olamaz ve sunucuya yalnızca Lightsail konsolundan kurtarma ile girilir. Bu yüzden
önce `visudo -cf` ile test, sonra `install`. Dosya adında `.` olmamalı:
`sudoers.d` içinde noktalı adlar **sessizce yok sayılır**.

## 5. Kendin dene

```bash
sudo -u deploy sudo -n -u www-data /usr/bin/php -v              # izinli
sudo -u deploy sudo -n /usr/bin/systemctl reload php8.5-fpm     # izinli
sudo -u deploy sudo -n /usr/bin/systemctl stop nginx            # REDDEDİLİR
sudo -u deploy sudo -n -u root /usr/bin/php -v                  # REDDEDİLİR
```

## 6. Sözlük

- **sudoers:** `sudo`'nun "kim, kimin adına, hangi komutu" kurallarının dosyası.
- **NOPASSWD:** Kural eşleşince parola sorulmaz.
- **`visudo`:** Sudoers dosyasını sözdizimi kontrolüyle düzenleyen/test eden araç.
