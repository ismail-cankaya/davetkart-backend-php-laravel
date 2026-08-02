# Veritabanı, Migration ve İnşa Sırası — Kavram Dokümanı

> **Kapsanan dosya:** yok — bu doküman **tek bir kod dosyasına ait değildir**.
> Faz 2'de sorulan "veritabanı mantığı nedir, neden PHP'de yazdık, neden bu
> sırayla?" sorusunun cevabıdır ve Faz 3'te `invitations` tablosu yazılırken
> tekrar okunmalıdır.
> **Ön koşul:** yok. Sıfırdan başlar.
> **Bağlantılı:** [`create_users_table.md`](../database/migrations/0001_01_01_000000_create_users_table.md) ·
> [`app/Models/User.md`](../app/Models/User.md) ·
> [`fazlar/FAZ-0.md`](../fazlar/FAZ-0.md) §4.2

---

## 1. Ortada kaç program var?

Bilgisayarında birbirinden **tamamen bağımsız iki program** çalışıyor:

```
┌──────────────────────┐              ┌──────────────────────┐
│   PHP (Laravel)      │  ←────────→  │   PostgreSQL 18      │
│   senin kodun        │  port 5432   │   veriyi tutan program│
│   Herd başlatıyor    │              │   Windows servisi     │
└──────────────────────┘              └──────────────────────┘
```

Bu ayrımı kavramak her şeyin temelidir:

- PHP **veriyi saklamaz**. Bir HTTP isteği bittiğinde PHP'nin hafızasındaki her
  değişken silinir. PHP her istekte sıfırdan doğar, işini yapar, ölür.
- Kalıcı olan tek yer PostgreSQL'dir. O ayrı bir programdır; `php artisan serve`'ü
  kapatsan da çalışmaya devam eder, bilgisayarı yeniden başlatsan da verisi durur.
- İkisi **ağ üzerinden** konuşur — aynı makinede olsalar bile. `.env`'deki
  `DB_HOST=127.0.0.1` ve `DB_PORT=5432` tam olarak bunun adresidir.

> Faz 0'daki **Y5** kuralı (`localhost` değil `127.0.0.1`) burada anlam kazanır:
> `localhost` Windows'ta önce IPv6'yı (`::1`) dener, PostgreSQL orayı dinlemiyorsa
> saniyelerce bekler. İki program arasındaki bağlantı gerçek bir ağ bağlantısıdır.

## 2. PostgreSQL'in içi neye benzer?

Kabaca Excel'e:

| Excel'de | Veritabanında | Bizim örnekte |
|---|---|---|
| Çalışma sayfası | **Tablo** | `users` |
| Sütun başlığı | **Kolon** | `first_name` |
| Satır | **Kayıt (row)** | Bir kullanıcı |
| Hücre | **Alan (field)** | `"İsmail"` |

Kritik fark şu: **Excel'de bir hücreye ne istersen yazarsın; veritabanında
yazamazsın.**

Kolonları ve kurallarını önceden bildirmen gerekir: `first_name` bir metindir,
en fazla 60 karakterdir, boş bırakılamaz. Bu bildirime **şema (schema)** denir.

Bu katılık bir kısıtlama değil, bir **korumadır**. Excel'de yaş sütununa "mavi"
yazabilirsin ve hata ancak aylar sonra bir hesap patlayınca ortaya çıkar.
PostgreSQL yazdırmaz — hatayı veriye dönüşmeden önce yakalar.

> **Genel ilke — bu projenin her yerinde tekrar eder:** Bir kuralı ne kadar erken
> ve ne kadar aşağıda zorlarsan o kadar güvenlidir. Doğrulama kuralı atlanabilir
> (seeder, tinker, unutulmuş bir kod yolu); veritabanı kısıtı atlanamaz. Faz 0'ın
> "hatayı sola çek" grafiği ile aynı fikirdir.

## 3. İki program hangi dilde konuşuyor?

**SQL** ile. Bizim tablomuzun gerçek SQL karşılığı şudur:

```sql
CREATE TABLE users (
    id                BIGSERIAL PRIMARY KEY,
    first_name        VARCHAR(60)  NOT NULL,
    last_name         VARCHAR(60)  NOT NULL,
    email             VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP    NULL,
    password          VARCHAR(255) NOT NULL,
    remember_token    VARCHAR(100) NULL,
    created_at        TIMESTAMP    NULL,
    updated_at        TIMESTAMP    NULL
);
```

### 🔴 İşte asıl cevap

Biz PHP'de **veritabanı yazmadık**. Bu SQL cümlesini **üretecek tarifi** yazdık.

```php
$table->string('first_name', 60);        ← PHP: tarif
            ↓  Laravel çevirir
first_name VARCHAR(60) NOT NULL          ← PostgreSQL: gerçek komut
```

Migration dosyası bir **talimat listesidir**. `php artisan migrate` çalıştığında
Laravel bu listeyi okur, `Blueprint` nesnesini doldurur, ondan SQL cümlesini kurar
ve PostgreSQL'e gönderir. **PHP dosyasının kendisi veritabanına hiç girmez.**

Bu dolaylılığın bir yan faydası daha var: SQL lehçeleri farklıdır (PostgreSQL'de
`BIGSERIAL`, MySQL'de `BIGINT AUTO_INCREMENT`). Tarifi PHP'de yazınca lehçe
farkı **sürücünün derdi** olur, senin değil. Faz 0'daki **V4** kuralı
("migration'lar sürüme özgü SQL kullanmaz") bu faydayı korumak içindir.

## 4. Neden SQL'i doğrudan yazmadık? — üç yolu eleyelim

### Yol A — pgAdmin'de fareyle tıklayarak tablo oluştur

Çalışır. Ama yaptığın **hiçbir yerde kayıtlı değil**.

- Yarın sunucuya kurulum yapacaksın: aynı 40 tıklamayı hatasız tekrarlaman gerek.
- Bilgisayarın bozulsa şemayı kimse bilmiyor.
- "Bu kolonu neden ekledik?" sorusunun cevabı hiçbir yerde yok.

### Yol B — `sema.sql` dosyasına SQL yaz

Daha iyi: artık versiyon kontrolünde, geçmişi var, gerekçesi yorumlanabilir.

Ama üç ay sonra `invitations` tablosuna kolon ekleyeceksin. Soru şu:

> *Bu dosya sunucuda çalıştı mı, çalışmadı mı?*

Takibi **sen** yapacaksın. İkinci dosyada zor, onuncu dosyada imkânsız. Eksik
uygulanmış bir şema, üretimde "column does not exist" hatası demektir.

### Yol C — Migration ✅

Laravel veritabanının **içine** `migrations` adında bir tablo açar ve koşan her
dosyanın adını oraya yazar:

| migration | batch |
|---|---|
| `0001_01_01_000000_create_users_table` | 1 |
| `0001_01_01_000001_create_cache_table` | 1 |
| `2026_07_28_210412_create_personal_access_tokens_table` | 1 |

`php artisan migrate` her çalıştığında önce bu tabloya bakar:
*"bu dosya listede var mı? Varsa atla, yoksa koş ve listeye ekle."*

**Migration'ın çözdüğü asıl problem SQL yazmak değildir** — hangi değişikliğin
hangi ortamda uygulandığını takip etmektir. Veritabanı şeması için `git` tutmaya
benzer.

### 🔴 `migrate` neden yetmedi, `migrate:fresh` neden gerekti?

Dosyayı düzenledik ama **adı değişmedi**. Laravel adı listede görüp "bu zaten
koştu" dedi ve hiçbir şey yapmadı. Laravel dosyanın *içeriğine* bakmaz, *adına*
bakar.

| Komut | Ne yapar |
|---|---|
| `migrate` | Yalnızca **listede olmayan** migration'ları uygular |
| `migrate:rollback` | Son grubu geri alır (`down()` metodunu çalıştırır) |
| `migrate:fresh` | 🔴 **Her tabloyu düşürür**, listeyi sıfırlar, hepsini baştan koşar |

`migrate:fresh` veriyi siler. Faz 2'de sorun değil (tablolar boş), Faz 3'ten
sonra sorun. Üretimde ise **çalıştırılamaz**: Faz 0'da `AppServiceProvider`
içine konan `DB::prohibitDestructiveCommands()` yapısal olarak engeller (V3).
Kural bir belgeye değil, koda yazılmıştır.

## 5. Migration ile Model farkı — kafa karışıklığının asıl kaynağı

İkisi de `users` tablosundan bahseder. Ama **bambaşka zamanlarda** çalışırlar.

```
KURULUM ZAMANI  —  ömründe bir kez, sen komut verince
────────────────────────────────────────────────────────
   php artisan migrate
        │
        ▼
   migration dosyası  →  CREATE TABLE  →  tablo oluşur
                                              │
                                              │
İSTEK ZAMANI  —  kullanıcı her tıkladığında, günde binlerce kez
────────────────────────────────────────────│───────────────────
                                              ▼
   tarayıcı → Laravel → User modeli → INSERT/SELECT → tablodaki satırlar
```

| | **Migration** | **Model** |
|---|---|---|
| Neyi tarif eder | Tablonun **yapısı** | Tablodaki **satırlar** |
| Ürettiği SQL | `CREATE TABLE`, `ALTER TABLE` | `SELECT`, `INSERT`, `UPDATE` |
| Ne sıklıkla çalışır | Bir kez | Her istekte |
| Nerede durur | `database/migrations/` | `app/Models/` |
| Değiştirmenin bedeli | Veritabanını dönüştürmek | Sadece kodu değiştirmek |

> **Benzetme:** Migration **inşaat projesidir**, model ise **binada oturmaktır**.
> Proje bir kez çizilir ve bina yapılır; sonra her gün içeri girip çıkarsın.
> `$fillable` "hangi odalara girilebilir"i söyler — ama odaları **migration**
> açmıştır. Olmayan odaya izin veremezsin.

Bu yüzden 2.0 (migration) 2.1'den (model) önce yazıldı.

## 6. Verinin izlediği yol — bir "Kayıt Ol" isteği

```
Tarayıcı  { firstName, lastName, email, password }
   │
   ▼
routes/api.php ······· "bu adrese gelen istek kime gider?"                  (2.7)
   │
   ▼
RegisterRequest ······ "bu veri geçerli mi?" boş mu, 60'ı aştı mı, e-posta mı?  (2.4)
   │                    ✗ geçersizse burada durur → 422 VALIDATION_FAILED
   ▼
AuthController ······· "işi kime devredeyim?" 3-8 satır, karar vermez        (2.6)
   │
   ▼
RegisterUserAction ··· İŞ KURALI: kullanıcıyı oluştur, token üret            (2.5)
   │
   ▼
User modeli ·········· parolayı hash'le, INSERT SQL'ini üret                 (2.1)
   │
   ▼
╔═══════════════════╗
║   PostgreSQL      ║   ← tablo 2.0'da oluşturuldu, satır şimdi yazılıyor
╚═══════════════════╝
   │
   ▼
UserResource ········· "dışarıya ne göstereyim?"                             (2.3)
   │                    first_name + last_name → fullName
   │                    parola hash'i ASLA çıkmaz
   ▼
Tarayıcı  { user: { id, fullName, email }, token }
```

### Neden bu kadar çok kutu var?

Her kutunun **tek bir işi** var (Single Responsibility). Faydası bir şey
bozulduğunda ortaya çıkar:

| Belirti | Bakılacak tek yer |
|---|---|
| "Boş isim kabul ediliyor" | `RegisterRequest` |
| "JSON'da alan adı yanlış" | `UserResource` |
| "Token üretilmiyor" | `RegisterUserAction` |
| "404 dönüyor" | `routes/api.php` |

Hepsi controller'ın içinde olsaydı, her arıza için 200 satırlık tek bir dosyada
arama yapardın — ve bir düzeltme başka bir şeyi sessizce bozardı.

> Bunun bedeli de var: 8 dosya, 1 dosyadan fazla iş. Bu bilinçli bir takas.
> `CLAUDE.md` bu takası **yalnızca değişme ihtimali olan yerlerde** kabul eder —
> K4 (Repository Pattern yok) ve K15 (DDD yok) kararlarının gerekçesi budur.

## 7. Neden bu sırayla yazıyoruz?

Yazma sırası, yukarıdaki okun **ters yönüdür**. Kimseye ihtiyaç duymayandan
başlanır:

| Sıra | Dosya | Öncesinde yazılsa ne olurdu |
|---|---|---|
| 2.0 | migration | Kolon adı bilinmeden `$fillable` yazılamaz |
| 2.1 | model | Factory hangi kolonlara veri üreteceğini bilemez |
| 2.2 | factory | Test kullanıcı üretemez |
| 2.3 | resource | Controller ne döndüreceğini bilemez |
| 2.4 | request | Action'ın "veri temiz geldi" varsayımı boşta kalır |
| 2.5 | action | Controller devredecek bir yer bulamaz |
| 2.6-2.7 | controller + rota | Uç nokta açılır |
| 2.10 | test | Test edilecek şey artık mevcuttur |

Buna **bağımlılık yönünde inşa** denir. Alternatifi (önce controller yazıp aşağı
inmek) sürekli "burası daha yazılmadı" duvarına çarpar ve geçici sahte kod
(*stub*) yazmayı zorunlu kılar.

> ⚠️ Bunu Faz 0'ın dersiyle karıştırma. Faz 0'da öğrenilen şey *"aşağıdan
> yukarı inşa öğrenmeyi zorlaştırır"*tı ve K17'yi (özellik-özellik inşa)
> doğurdu. O karar **fazlar arası** sıra hakkındadır: tüm migration'ları,
> sonra tüm modelleri yazmıyoruz. Buradaki sıra ise **bir fazın içindedir**.
> İkisi çelişmez: dilim dilim ilerliyoruz, her dilimin içinde bağımlılık
> yönünde.

## 8. Sık karıştırılanlar

| Karışıklık | Doğrusu |
|---|---|
| "Migration veriyi de oluşturur" | Hayır — **yapıyı** oluşturur. Veri üretmek seeder ve factory'nin işi |
| "Model tabloyu oluşturur" | Hayır — model var olan tabloyu **kullanır** |
| "`migrate` dosyadaki değişikliği görür" | Hayır — dosya **adına** bakar, içeriğine değil |
| "PHP verileri saklıyor" | Hayır — istek bitince PHP'nin hafızası silinir |
| "SQL öğrenmeme gerek yok" | Gerek var. Eloquent SQL üretir; ürettiğini okuyamazsan N+1 gibi sorunları göremezsin |
| "`migrate:fresh` zararsız" | Üretimde felakettir. Faz 0'da yapısal olarak engellendi (V3) |

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Şema (schema)** | Veritabanının yapısı: tablolar, kolonlar, kısıtlar |
| **Tablo / kolon / satır** | Excel'deki sayfa / sütun / satırın karşılığı |
| **SQL** | Veritabanıyla konuşulan dil |
| **DDL** | SQL'in yapı komutları: `CREATE`, `ALTER`, `DROP` |
| **DML** | SQL'in veri komutları: `SELECT`, `INSERT`, `UPDATE`, `DELETE` |
| **Migration** | Şema değişikliğinin, uygulanma takibi yapılan kod hâli |
| **Seeder / Factory** | Veri üreten araçlar (migration'la karıştırılmamalı) |
| **ORM** | Nesne ile tablo satırı arasında çeviri yapan katman (Eloquent) |
| **Active Record** | Bir sınıfın hem veriyi hem veritabanı erişimini taşıdığı desen |
| **Kısıt (constraint)** | `NOT NULL`, `UNIQUE`, `FOREIGN KEY` — veritabanının kendi savunması |
| **Bağımlılık yönünde inşa** | Kimseye ihtiyaç duymayan dosyadan başlama sırası |

## 10. Bağlantılar

| İlgili | Nerede |
|---|---|
| Bu kavramın uygulandığı dosya | [`create_users_table.md`](../database/migrations/0001_01_01_000000_create_users_table.md) |
| Modelin karşılığı | [`app/Models/User.md`](../app/Models/User.md) |
| Veritabanı kuralları (V1-V4) | [`fazlar/FAZ-0.md`](../fazlar/FAZ-0.md) §4.2 |
| Veri modelinin tamamı | [`docs/03-MIMARI-PLAN.md`](../../03-MIMARI-PLAN.md) §3 |
| Katman sorumlulukları | [`CLAUDE.md`](../../../CLAUDE.md) §1 |
