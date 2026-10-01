# `app/Console/Commands/PurgeExpiredData.php`

> **Faz:** 10 — Dilim D, adım 10.43 · **Karar:** **K98** (S-1: saklama süreleri)
> **Zamanlayıcı:** her gece 03:45 ([`routes/console.md`](../../../routes/console.md))
> **Önce oku:** [`PruneOrphanMedia.md`](PruneOrphanMedia.md) (*"önce dosya, sonra satır"*)
> **Test:** [`MaintenanceTest.md`](../../../tests/Feature/MaintenanceTest.md) §6e

---

## 1. Neden var?

KVKK'nın *veri en aza indirme* ilkesi: amacı biten kişisel veri saklanmaz. Faz 9'a kadar
hiçbir şey silinmiyordu. Çöp kutusundaki davetiyeler, bir düğünden yıllar sonra
misafirlerin adları ve fotoğrafları, iletişim formuna yazılan e-postalar süresiz kalıyordu.

## 2. Üç kategori (K98, İsmail'in kararı 1 Ekim 2026)

| Ne | Ne zaman | Silinen | Kalan |
|---|---|---|---|
| Çöp kutusundaki davetiye | `deleted_at` + **30 gün** | Davetiye, program, LCV'ler, medya **satırları ve dosyaları** | Siparişi (`invitation_id = NULL`) |
| Misafir verisi | `event_at` + **6 ay** | LCV satırları (ad, mesaj, menü), misafirin foto/videosu (**dosyalarıyla**) | Davetiye ve sahibinin **galerisi** |
| İletişim mesajı | `created_at` + **12 ay** | Satır (ad, e-posta, mesaj) | — |

Sayılar `config/davetkart.php` → `retention`'da. `env()` yok: ortam farkı değil,
kullanıcıya verilen bir söz. KVKK aydınlatma metni bu sayıları yazacak.

## 3. Kararlar

### 3.1 Misafir verisi neden yalnızca misafirinki?

Davetiyenin kendisi ve galerisi **sahibinin** verisi. Sahibi onları ne zaman isterse
siler (ya da hesabını siler, 10.39). Misafirler ise verilerini bir kez, bir etkinlik
için verdiler; etkinlik bitince amaç da biter. Galeri `MediaKind::guestUploadableValues()`
süzgeciyle dışarıda kalıyor (mutasyon M3: süzgeç kalkınca galeri de siliniyor ve test kırılıyor).

### 3.2 Tarihsiz davetiye (`event_at = NULL`)

Dokunulmaz. *"Etkinlik ne zaman bitti?"* sorusunun cevabı yok; `NULL` *"bitmedi"*
anlamına gelmez, *"bilinmiyor"* anlamına gelir (N4). Silmek yerine beklemek, yanlış
tarafa düşmenin ucuz olan yolu.

### 3.3 Önce dosya, sonra satır

`PruneOrphanMedia` ile aynı sıra. Satır önce silinip dosya silme başarısız olsaydı,
dosyanın yolunun kayıtlı olduğu tek yer de gitmiş olurdu. Bu sırada yarım kalan iş
ertesi gece satırı yeniden bulur. (Hesap silmede sıra tersti; gerekçesi
`DeleteAccountAction.md` §3: orada geri dönüşü olmayan bir kullanıcı işlemi var.)

### 3.4 Siparişler

Dokunulmaz. Kalıcı silinen davetiyenin siparişi FK ile `invitation_id = NULL` olur
ve muhasebe kaydı olarak kalır (K82, K97). Test bunu iddia ediyor (`orders_survive_the_purge`).

### 3.5 Neden Action değil de komut?

`ExpireStaleOrders` ve `PruneOrphanMedia` ile aynı: tetikleyen bir HTTP isteği ya da
kullanıcı yok, mantık tek bir zamanlanmış işte yaşıyor. Bir gün bir yönetim ekranı
*"şimdi temizle"* derse mantık o gün bir Action'a taşınır.

## 4. `--dry-run`

```powershell
php artisan data:purge --dry-run
#  INFO  3 davetiye kalıcı silindi (yazılmadı).
#  INFO  41 LCV yanıtı ve 12 misafir dosyası silindi (yazılmadı).
#  INFO  0 iletişim mesajı silindi (yazılmadı).
```

**K84:** yayına alındığı gün ilk koşu elle ve `--dry-run` ile yapılır. Faz 10'dan önce
hiçbir şey silinmediği için ilk gerçek koşu birikmiş verinin hepsini bir kerede siler.

## 5. Bilinen sınırlar (B6)

- **Yedekler:** silinen veri veritabanı yedeklerinde yaşamaya devam eder. Yedek
  saklama süresi deploy fazının konusu (`docs/10`).
- **Optimize edilmiş görselin eski hâli:** 24 saatlik bekleme işi (`DeleteReplacedMediaFile`)
  kendisi siliyor.
- **Saat dilimi:** `event_at` karşılaştırması UTC üzerinden. 6 aylık bir sürede birkaç
  saatlik kaymanın anlamı yok (K71'in tersi, `routes/console.md` §2).
