# `app/Actions/Auth/DeleteAccountAction.php`

> **Faz:** 10 — Dilim D, adım 10.39 · **Karar:** **K97** (H-1: anonimleştirme)
> **Çağıran:** `AuthController::destroy` → **204**
> **Önce oku:** [`DeleteInvitationAction.md`](../Invitation/DeleteInvitationAction.md) ·
> [migration `2026_10_01_100000`](../../../database/migrations/2026_10_01_100000_make_orders_user_id_nullable.md)
> **Test:** [`AccountDeletionTest.md`](../../../tests/Feature/AccountDeletionTest.md)

## 1. Ne gider, ne kalır?

| Veri | Sonuç | Nasıl |
|---|---|---|
| Kullanıcı (ad, e-posta, parola hash'i) | 🗑️ silinir | `$user->delete()` |
| Davetiyeler (çöp kutusundakiler dahil) | 🗑️ kalıcı silinir | **model üzerinden** `forceDelete()` |
| Program adımları, LCV yanıtları | 🗑️ | davetiyenin FK'leri (`cascade`) |
| Medya satırları | 🗑️ | davetiyenin FK'si |
| Medya **dosyaları** (galeri + misafir) | 🗑️ | commit'ten sonra `Storage::delete()` |
| Oturumlar (Sanctum token'ları) | 🗑️ | `$user->tokens()->delete()` |
| Bekleyen parola sıfırlama bağlantısı | 🗑️ | `Password::broker()->deleteToken()` |
| Asistan kullanım sayaçları | 🗑️ | FK `cascade` |
| **Siparişler** | ✅ **kalır**, `user_id = NULL`, `invitation_id = NULL` | FK `nullOnDelete` (10.38, K82) |
| İletişim formu mesajları | kalır | Kullanıcıya bağlı değil; 12 ay sonra `data:purge` siler (K98) |

## 2. 🔴 Neden veritabanı cascade'ine bırakılmıyor? (plan tuzak #8)

`users` satırını silmek tek başına davetiyeleri, LCV'leri ve medya **satırlarını**
FK'ler aracılığıyla siler. Ama:

1. **Dosyalar diskte kalır.** Bir veritabanı kısıtı diski bilmez. Misafirlerin
   fotoğrafları ve videoları, sahibinin hesabı silindikten sonra sunucuda kalırdı.
   Bu bir KVKK sorunu.
2. **Model olayları ateşlenmez.** `Invitation`'ın `deleted` olayı → `InvitationChanged`
   → `ClearInvitationCache`. Cascade'de bu zincir hiç çalışmaz ve silinmiş davetiye,
   public önbelleğin süresi dolana kadar (6 saat) misafire açık kalır.

İkincisini yalnızca bir test yakalıyor: `a_cached_public_invitation_disappears_with_the_account`.
Mutasyonla kanıtlandı: davetiyeler yalnızca cascade'le silinince satırlar ve dosyalar
yine gidiyor, **sadece** bu test kırılıyor.

## 3. Sıra: satırlar önce, dosyalar sonra

```
transaction {
    dosya yollarını topla
    davetiyeleri model üzerinden sil (olaylar → önbellek, commit'ten sonra)
    sıfırlama token'ı, oturumlar, kullanıcı
}
dosyaları sil
```

| Ters sıra (önce dosyalar) | Bu sıra (önce satırlar) |
|---|---|
| Transaction başarısız olursa hesabı **hâlâ var olan** kullanıcının fotoğrafları silinmiş olur: **veri kaybı** | Dosya silme başarısız olursa diskte sahipsiz bir dosya kalır: **yer kaybı**, loglanır |

`PruneOrphanMedia`'nın sırası (*"önce dosya, sonra satır"*) bunun tersi. O komut tek
bir yetim satırla çalışıyor ve satır kalırsa ertesi gece yeniden dener; burada ise
geri dönüşü olmayan bir hesap silmesi var.

## 4. Bilinen sınırlar (B6)

- **Eşzamanlı yükleme:** dosya yolları toplandıktan sonra ama davetiye silinmeden
  önce commit'lenen bir misafir yüklemesi, satırı cascade'le silinir ama dosyası
  toplanmamıştır. Pencere milisaniyeler; sonuç sahipsiz bir dosya.
- **Optimize edilmiş görselin eski hâli:** `DeleteReplacedMediaFile` işi onu 24 saat
  sonra kendisi siliyor (`replaced_file_grace_hours`); bu eylem ona dokunmuyor.
- **Sağlayıcıdaki kayıt:** ödeme sağlayıcısının kendi müşteri kaydı bizim kontrolümüzde değil.
