# Oturumlar: `IssueApiTokenAction` · `SessionController` · `DeviceLabel`

> **Faz:** 14, adım 14.9 · **Karar:** K143
> **Uçlar:** `GET /api/auth/sessions` · `DELETE /api/auth/sessions/{session}` · `DELETE /api/auth/sessions`
> **Dosyalar:** `IssueApiTokenAction`, `RevokeSessionAction`, `RevokeOtherSessionsAction`, `SessionResource`,
> `SessionController`, `App\Support\DeviceLabel` · migration `2026_10_26_120000`
> **Test:** `tests/Feature/SessionTest.php`, `tests/Unit/DeviceLabelTest.php`

---

## 1. Neden "oturum" = Sanctum token'ı?

Bu projede oturum çerezi yok; frontend her istekte `Authorization: Bearer <token>` gönderiyor.
Her girişte `personal_access_tokens`'a bir satır yazılıyor. O satırı silmek = o cihazdan çıkış.
Ayrı bir `sessions` tablosu yazmak aynı bilgiyi ikinci kez tutmak olurdu.

## 2. `IssueApiTokenAction`: tek üretici (C3)

Login ve Register'ın ikisi de `createToken('api')` çağırıyordu. Cihaz bilgisini iki yerde
ayrı ayrı yazmak, birinin unutulması demek. Artık ikisi de bu Action'ı çağırıyor.

- `createToken()` Sanctum'un; ek alan kabul etmiyor. Satır oluştuktan sonra `forceFill()`
  ile `user_agent` yazılıyor (bir UPDATE). Giriş başına bir sorgu fazlası; karşılığı
  basit ve okunur kod.
- User-Agent **istemcinin sözü**: 255 karaktere kırpılıyor, güvenlik kararı için
  kullanılmıyor.

`LoginUserAction` ve `RegisterUserAction` imzalarına `?string $userAgent` eklendi; controller
`$request->userAgent()` veriyor. Action HTTP'yi bilmiyor (CLAUDE.md §1.2): başlığı okumak
controller'ın, ne yapılacağına karar vermek Action'ın işi.

## 3. Uçlar

| Uç | Sonuç | Not |
|---|---|---|
| `GET /sessions` | `{data: [...]}` | Süresi dolmuş (30 gün, K90) token listelenmez |
| `DELETE /sessions/{id}` | 204 | Kendi token'ı değilse 404 (H7) |
| `DELETE /sessions` | 204 | İsteği yapan hariç hepsi |

🔴 **Token kimliği sıralı tamsayı**, tahmin edilebilir. Bu yüzden sahiplik Policy'ye değil
**sorguya** bağlı: `$user->tokens()->whereKey($id)`. Başkasının kimliği verilirse satır
bulunmaz; 403 değil 404 (varlığı sızmaz).

## 4. `DeviceLabel` ve `SessionResource`

Ham User-Agent dışarı **gitmez**; yalnızca `browser` ve `system` (ör. `Chrome`, `Windows`).
Ham başlık bir parmak izi, kullanıcıya da bir şey anlatmıyor.

Sıra tuzağı: Edge'in başlığında `Chrome/` ve `Safari/` da var; Chrome'unkinde `Safari/`.
Desenler özelden genele dizili, ilk eşleşen kazanıyor (testi: `DeviceLabelTest`).

Tam bir UA ayrıştırıcı paketi bu iş için ağır: tek amaç kullanıcıya "hangi cihazdı?" diye
hatırlatmak. Tanınmayan başlık `null` → frontend "Bilinmeyen cihaz" yazar.

`current: true` isteği yapan oturum: frontend o satırda "kapat" yerine "bu cihaz" rozeti gösterir.

## 5. Neden `index` için Action yok?

Tek sorgu + Resource (K15, `OrderController::index` ile aynı). Kapatma uçlarının Action'ı var,
çünkü onlar bir **yazma** ve kuralları (sahiplik, "bu cihaz kalır") test edilebilir bir yerde
durmalı.

## 6. Testler

| Test | Kanıtladığı |
|---|---|
| `login_records_the_device` · `register_records_the_device` · `a_huge_user_agent_is_truncated` | Tek üretici, kırpma |
| `the_list_names_devices_and_marks_the_current_one` | Etiket + `current` + alan listesi |
| `no_secret_leaves_the_server` | 🔴 Token hash'i ve ham UA gitmez |
| `an_expired_token_is_not_listed` | 30 gün sınırı |
| `one_session_can_be_closed` | Uçtan uca: kapatılan cihaz 401 |
| `another_users_session_cannot_be_closed` | 🔴 H7 |
| `all_other_sessions_can_be_closed` | Bu cihaz kalır, başkasının token'ı etkilenmez |
