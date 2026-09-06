# `app/Http/Controllers/Api/V1/AssistantController.php`

> **Faz:** 8, dosya 8.11 · **Uc:** `POST /api/assistant/chat` (auth)
> **Kararlar:** K73 (uc auth'lu) · C2 (zarf) · K15

---

## 1. 🔴 Bir sozlesme celiskisi ve cozumu

Frontend'de `AssistantWidget`, `AppLayout` icinde render ediliyor — yani
**her sayfada**, giris yapmamis ziyaretcide de. Plan ise ucu "auth'lu
(kotali)" diye tanimliyordu. Ikisi ayni anda dogru olamaz.

Karar **auth'lu** yonunde verildi. Gerekce tek cumlede: **her cagri
paradir ve bir maliyet kontrolunun calismasi icin harcamanin bir kimlige
yazilabilmesi gerekir.**

Kimliksiz cagrida elimizdeki tek anahtar IP olurdu ve IP **iki yonde
birden** basarisizdir:

| Yon | Sonuc |
|---|---|
| **Cok genis** | CGNAT arkasinda bir mobil operatorun on binlerce abonesi tek IP'dir. Gunluk kotayi ilk kullanan tuketir, geri kalan herkes kapida kalir |
| **Cok dar** | Saldirgan icin IP dondurmek saatlik birkac kurustur. Kota, kacmak isteyene engel degil |

Yani IP anahtari kotayi **mesru kullanici icin siki, saldirgan icin
gevsek** yapar — bir guvenlik kontrolunun olabilecegi en kotu hali.

Ustelik ticari modelde **ucretsiz katman yok**: parayla ilgisi olan herkesin
zaten hesabi var.

**Celiskinin maliyeti frontend'dedir ve oraya aittir:** widget giris
yapmamis ziyaretcide render edilmemeli ya da "sohbet icin giris yap"
demeli — `AppLayout`'ta tek kosul. Backend, bir widget'in yerlesimini
duzeltmek icin olculemeyen bir para muslugunu internete acamaz (K12'nin
ayni fail-safe refleksi).

---

## 2. Zarf neden korundu?

```json
{ "data": { "reply": "..." } }
```

Zarfsiz donmek frontend'in tek dikis yerini (`generateReply`) bir satir
kisaltirdi. Ama **C2** zarf istisnasini **ad ad** tanimliyor ve o listede
yalnizca auth uclari var. Bir istisnayi "kolay oldugu icin" buyutmek,
sozlesmeyi kuralsiz birakmanin ilk adimidir.

Frontend uyarlamasi:

```ts
async function generateReply(userText: string): Promise<string> {
  const { data } = await api.post('/assistant/chat', { message: userText });
  return data.data.reply;
}
```

---

## 3. Neden Resource degil duz `JsonResponse`?

Ortada bir **model yok**. Yanit tek bir metin; beyaz listelenecek alani,
gizlenecek kolonu, camelCase'e cevrilecek adi yok. Bir Resource burada
yalnizca bos bir tabaka olurdu — **K15**: soyutlama butcesi gercekten
degisecek yerlere harcanir.

---

## 4. Controller'da `if` yok

Honeypot yok, kota Action'da, bicim FormRequest'te, hata → HTTP eslemesi
`ApiExceptionRenderer`'da. Govde uc satir (`CLAUDE.md` §1).
