# `deploy/nginx/install.sh`

> **Ne işe yarar:** Depodaki Nginx yapılandırmasını (site dosyası + güvenlik başlıkları snippet'i)
> sunucuya kurar, `nginx -t` ile sınar ve ancak yeşilse kesintisiz yeniden yükler. Test kırmızıysa
> **eski dosyaları geri koyar**, reload yapmaz: yayındaki site değişmez. Faz 18.6 · `SEC-04` · K173.
> İlk kurulum (sertifika, `sites-enabled` bağlantısı) bu betiğin işi değil: [`davetkart.md`](davetkart.md) §5.

```bash
# sunucuda, sudo yetkili kullanıcıyla ('deploy' değil: onun Nginx yetkisi yok)
cd /var/www/davetkart/backend
sudo bash deploy/nginx/install.sh
bash deploy/smoke/check-security-headers.sh https://davetkart.com
```

## 1. Neden bir betik? Elle `cp` yetmez miydi?

Faz 18.6'dan önce kılavuz şunu söylüyordu: `sudo cp …` → `sudo nginx -t` → `sudo systemctl reload nginx`.
Bu sırada bir boşluk var: `cp` diskteki **canlı** dosyayı değiştirir, `nginx -t` ondan **sonra** koşar.
Test kırmızıysa reload yapılmaz ve Nginx eski yapılandırmayı bellekte tutmaya devam eder. Site o an
çalışır, ama diskteki dosya bozuktur. Bir sonraki yeniden başlatmada site açılmaz. Bunu tetikleyen bir
sunucu açılışı ya da certbot'un yenileme sonrası `reload`'u olabilir, ve haftalar sonra olabilir.

Faz 18.6 ile kurulacak dosya sayısı da ikiye çıktı: site dosyası artık snippet'i `include` ediyor.
Biri kurulup diğeri unutulursa yine aynı durum doğar.

| Elle | Betik |
|---|---|
| İki `cp`; biri unutulabilir | İki dosya her zaman birlikte, önce snippet |
| Test kırmızı → diskte bozuk dosya kalır | Test kırmızı → eski dosyalar geri konur, çıkış 1 |
| Geri dönmek için eskinin kopyası yok | Her çalıştırmada `mktemp -d` yedeği; yol günlükte |

Desen: **önce sına, sonra uygula; sınama kırmızıysa hiçbir şey değişmemiş olsun.** `deploy-frontend.sh`'ın
`dist.new` → `dist` yer değiştirmesi de aynı fikir.

## 2. Akış

| # | Adım | Neden |
|---|---|---|
| 0 | `/etc/nginx` ise root mu? | `install` root ister; yetkisiz koşu yarıda kalmasın diye en başta durur |
| 1 | `sites-enabled/davetkart.conf` ve `snippets/` var mı? | Yoksa bu bir **ilk kurulum**dur: sertifika ve bağlantı adımları kılavuzda (§5). Betik hiçbir dosyaya dokunmadan durur |
| 2 | Var olan iki dosyayı `mktemp -d`'ye kopyala | Geri alma için |
| 3 | `install -m 644` snippet, sonra site | `install` = kopyala + izin ver, tek komut. Sıra: site snippet'i istediği için önce snippet |
| 4 | `nginx -t` | Sözdizimi, `include` edilen dosyalar, sertifika yolları |
| 4a | Kırmızı → `restore`, çıkış 1 | Yedekte olan geri konur; yedekte olmayan (ilk kez kurulan snippet) silinir |
| 5 | `systemctl reload nginx` | Kesintisiz: eski işçiler elindeki isteği bitirir, yenileri yeni yapılandırmayla başlar |
| 6 | Duman testi komutunu yazdır | Başlıkların gerçekten çıktığını `nginx -t` söylemez; duman testi söyler |

## 3. Bash notları

- `set -euo pipefail`: herhangi bir komut hata verirse betik durur (`-e`), tanımsız değişken hatadır
  (`-u`), bir borunun ortasındaki hata da hatadır (`pipefail`).
- `if ! nginx -t; then …`: `-e` altında hata veren komut betiği öldürürdü; `if` içinde kullanılan
  komut bu kuralın dışındadır, sonucuna biz karar veririz.
- `NGINX_DIR="${NGINX_DIR:-/etc/nginx}"`: değişken verilmezse `/etc/nginx`. Yalnızca yerel denemede
  geçici bir klasörle değiştirilir; root denetimi de yalnızca gerçek `/etc/nginx` için yapılır.

## 4. Kendin dene (yerelde, Git Bash)

Gerçek Nginx gerekmez: sahte `nginx` ve `systemctl` PATH'in başına konur, `/etc/nginx` yerine geçici
bir klasör verilir.

```bash
W=$(mktemp -d); mkdir -p $W/bin $W/etc/{sites-available,sites-enabled,snippets}
echo ESKI > $W/etc/sites-available/davetkart.conf; cp $W/etc/sites-available/davetkart.conf $W/etc/sites-enabled/
printf '#!/bin/sh\necho "nginx $*"; exit 1\n' > $W/bin/nginx        # testi kırmızı yap
printf '#!/bin/sh\necho "systemctl $*"\n' > $W/bin/systemctl; chmod +x $W/bin/*
PATH="$W/bin:$PATH" NGINX_DIR=$W/etc bash deploy/nginx/install.sh; echo "cikis: $?"
cat $W/etc/sites-available/davetkart.conf     # ESKI: geri kondu
ls $W/etc/snippets                             # boş: ilk kez kurulan snippet kaldırıldı
```

Sonra sahte `nginx`'te `exit 1`'i `exit 0` yap: dosyalar yeni hâlini almalı, `systemctl reload nginx`
yazmalı. Faz 18.6'da bu beş senaryo (güncelleme yeşil, güncelleme kırmızı, ilk snippet + kırmızı, ilk
kurulum yapılmamış, root değil) ve iki mutant koştu: `FAZ-18.md` §18.6.

## 5. Neyi yapmaz

- **İlk kurulumu.** Sertifika (certbot), `sites-enabled` bağlantısı, Ubuntu'nun `default` sitesinin
  kaldırılması kılavuzda (§5), bir kez ve elle.
- **Başlıkların doğruluğunu sınamaz.** `nginx -t` yalnızca "Nginx bunu okuyabilir mi?" sorusunu
  cevaplar. Bir `location`'dan `include` satırı düşse `nginx -t` yine yeşildir; bunu duman testi yakalar.
- **`deploy.sh`'ın parçası değil.** Nginx yapılandırması kod gibi her dağıtımda değişmez, `deploy`
  kullanıcısının da Nginx yetkisi yok (`deploy/sudoers`). Yapılandırma değiştiğinde elle, `sudo` ile.
- **Yedeği temizlemez.** `/tmp` altındaki yedek klasörü küçük; sunucu açılışında sistem temizler.

## 6. Sık hatalar

| Belirti | Sebep |
|---|---|
| `root yetkisi gerekli` | `sudo` unutuldu |
| `… sites-enabled/davetkart.conf … yok: ilk kurulum icin rehber §5` | Bu sunucuda site hiç kurulmamış; önce kılavuzdaki ilk kurulum |
| `nginx -t kirmizi: eski dosyalar geri kondu` | Yeni yapılandırmada hata var; üstteki `nginx: [emerg] …` satırı nerede olduğunu söyler. Site etkilenmedi |
| Betik yeşil, duman testi kırmızı | Yapılandırma okunuyor ama bir `location`'da `include` eksik ya da snippet'ten bir başlık silinmiş |

## 7. Sözlük

- **`nginx -t`:** Yapılandırmayı yükler ama uygulamaz; yalnızca "geçerli mi?" diye bakar.
- **`reload`:** Nginx'i durdurmadan yeni yapılandırmaya geçirir. `restart`'ın tersine bağlantı kopmaz.
- **`install`:** Dosyayı kopyalayıp izinlerini tek komutta ayarlayan coreutils aracı.
