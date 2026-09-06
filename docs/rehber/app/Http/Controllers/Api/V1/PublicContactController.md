# `app/Http/Controllers/Api/V1/PublicContactController.php`

> **Faz:** 8, dosya 8.15 · **Uc:** `POST /api/public/contact` (auth yok)
> **Kararlar:** K74 (yol) · K12 · C1

---

## 1. 🔴 Yol frontend sozlesmesinden farkli

`services/contact.ts` bugun `/api/contact` cagiriyor; uc
**`/api/public/contact`** altinda acildi ve **frontend uyarlanacak**.

Gerekce **K12**: auth gerektirmeyen rotalarin **tamami** tek onekte
toplanir ki *"auth:sanctum unutuldu mu?"* bir **hatirlama** meselesi
olmasin. Bu ucu disarida birakmak oneki bir kurala degil bir **aliskanliga**
cevirirdi — ve ilk istisna, ikincisinin gerekcesi olur.

Bu projede ayni karar ikinci kez veriliyor:

| Faz | Plan diyordu | Ne yapildi | Karar |
|---|---|---|---|
| 6 | `POST /media/upload` ("frontend kazanir") | `/invitations/{id}/media` | N1 |
| 7 | `/api/payments/webhook` | `/api/public/payments/webhook` | **K65** |
| **8** | `/api/contact` | **`/api/public/contact`** | **K74** |

Frontend maliyeti: `contact.ts` icinde **tek satir**.

---

## 2. Neden 204, 201 degil?

Frontend donus degerini zaten okumuyor
(`sendContactMessage(): Promise<void>`), yani ikisi de serbestti.

201 bir **govde** ister ve o govdede dondurulecek hicbir sey yok:

| Alan | Neden dondurulmez |
|---|---|
| `id` | Kimsenin isine yaramaz; okuma ucu yok |
| `ip_hash` | **Sizinti** |
| `created_at` | Bilgi tasimaz |

**C1:** Resource bir beyaz listedir — beyaz listenin bos oldugu yerde
Resource'un kendisi de gereksizdir.

**Bedava gelen ikinci kazanc:** honeypot'un sessiz reddi ile gercek kayit
arasinda **ayirt edilecek bir sey kalmiyor**. LCV ucunda bunun icin sahte
bir model uretmek gerekmisti.

---

## 3. Controller'da `if` yok

Honeypot karari Action'da, bicim FormRequest'te, hata → HTTP eslemesi
`ApiExceptionRenderer`'da (`CLAUDE.md` §1). Govde dort satir.
