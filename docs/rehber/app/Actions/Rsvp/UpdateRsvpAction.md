# `app/Actions/Rsvp/UpdateRsvpAction.php`

> **Faz:** 10 — Dilim F, adım 10.59 · **Karar:** **K101** (misafire düzenleme kodu)
> **Uç:** `PUT /api/public/invitations/{invitation}/rsvps/{rsvp}` (`throttle:rsvp`)
> **Önce oku:** [`SubmitRsvpAction.md`](SubmitRsvpAction.md) (katmanlı savunma) ·
> [`RsvpEditCode.md`](../../Support/RsvpEditCode.md)
> **Test:** [`RsvpEditTest.md`](../../../tests/Feature/RsvpEditTest.md)

---

## 1. Çözdüğü sorun

Misafir formu iki kez gönderirse (fikrini değiştirdi, kişi sayısını yanlış yazdı, bağlantıyı
ikinci kez açtı) iki satır oluşuyordu:

- Davetiye sahibi aynı adı iki kez görüyordu.
- Kişi sayısı kotadan **iki kez** düşüyordu. Standart planın 100 kişilik kotası, 50 kişi
  fikrini değiştirdiğinde dolu görünürdü.

İsmail'in kararı (K101, 1 Ekim 2026): misafire gizli bir **düzenleme kodu** verilir. Aynı
tarayıcıdan ikinci gönderim yeni satır açmaz, eskisini günceller.

## 2. Akış

```
POST /rsvps            → 201 { data: { id, …, editCode } }     (kod yalnızca burada)
   frontend kodu bu davetiye için tarayıcıda saklar
PUT  /rsvps/{id}       → 200 { data: { id, …, editCode } }     (aynı satır)
     gövde: form + editCode
```

## 3. Katmanlar

| # | Katman | Hata |
|---|---|---|
| 0 | Hız sınırı (`throttle:rsvp`, gönderimle **aynı** kova) | 429 |
| 1 | Hedef açık mı: yayında + LCV modülü açık + son tarih geçmemiş (`ResolveOpenRsvpInvitationAction`) | 404 / 403 `RSVP_DEADLINE_PASSED` |
| 2 | Yanıt **bu davetiyenin** mi ve kod doğru mu | 404 |
| 3 | Medya aidiyeti (`ResolveGuestMediaAction`) | Sessizce düşer |
| 4 | Kota, eski kişi sayısı **hariç** (`EnsureRsvpQuotaAction`) | 403 `RSVP_QUOTA_EXCEEDED` |

Honeypot **yok**: bu uca gelebilmek için ilk gönderimin yanıtındaki kodu bilmek gerekiyor. Bot
o kodu ancak kendi (sahte) gönderiminden alabilir ve o gönderim hiçbir satır yazmamıştır.

### 3.1 🔴 Neden yanlış kod 404, 403 değil?

403 *"bu yanıt var ama kod yanlış"* demek olurdu. Kodu tahmin eden biri önce yanıt kimliklerini
tarayabilirdi. 404 ile *"yanıt yok"* ve *"kod yanlış"* aynı gövdeyi alıyor (H7):
`{"error":{"code":"RESOURCE_NOT_FOUND"}}`. Test iki gövdeyi birebir karşılaştırıyor.

### 3.2 🔴 Neden aidiyet URL'den?

Yanıt `$invitation->rsvps()->whereKey(…)` ile aranıyor, `Rsvp::find()` ile değil. Doğru kodu
bilen biri bile yanıtı **başka bir davetiyenin** adresinden güncelleyemez. Bu, iki davetiyenin
son tarih ve kotalarının karışmasını önlüyor: B davetiyesinin LCV'si açık diye A'nın kapanmış
yanıtı güncellenemez. Mutasyonla görüldü: kapsam kalkınca yalnızca bu test kırılıyor.

### 3.3 🔴 Kota neden eski sayıyı saymıyor?

Kota 5, başkaları 2 kişi, bu misafir 3 kişi: tam dolu. Misafir mesajını düzeltip aynı 3 kişiyle
gönderdiğinde, eski 3 de sayılsaydı `2 + 3 + 3 = 8 > 5` olur ve kendi yanıtını düzeltemezdi.
`EnsureRsvpQuotaAction` güncellenen satırı (`$replacing`) toplamdan çıkarıyor.

## 4. Kod neden değişmiyor?

Güncelleme aynı kodu geri veriyor; yeni kod üretilmiyor. Üretilseydi, iki sekmesi açık bir
misafirin eski sekmesindeki kod geçersiz olur ve ikinci güncelleme sessizce *"yanıt bulunamadı"*
alırdı. Kod tek bir yanıtın anahtarı. Davetiye sahibi yanıtı silerse kod da onunla birlikte gider.

## 5. Faz 10'dan önceki yanıtlar

`edit_code_hash` boş: o yanıtların kodu hiç verilmedi. `RsvpEditCode::matches(null, …)` her zaman
`false`; bu yanıtlar hiçbir kodla güncellenemez (test: `a_reply_without_a_code_cannot_be_updated`).

## 6. Bilinen sınırlar (B6)

- **Kod tarayıcıya bağlı.** Misafir başka bir cihazdan gönderirse kod yoktur ve yeni bir satır
  açılır. Bu kararın bilinen bedeli; sahip fazla satırı panelden silebilir.
- **Ad üzerinden birleştirme yok.** *"Aynı ad = aynı misafir"* varsayılmadı: iki ayrı *"Ayşe
  Yılmaz"* olabilir.
- **IP güncellenmiyor.** `ip_hash` ilk gönderiminki olarak kalıyor.
