# Faz 25 — v2.0 editör: metin önerisi altyapısı ve misafir bildirimi

> **Tarih:** 28 Kasım 2026 · **Dal:** `faz-25-v2.0-editor-ui` (`faz-24-v2.0-editor`'dan) · **Kararlar:** K310, K313
> (docs `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-24.md`) · **Takip:** docs `phases/faz-25/`
> Editörün kendisi (tıkla-düzenle, bölümler, geri al) frontend'dedir; backend'in payı iki uç.

## Ne kuruldu

| Uç | Parçalar | Not |
|---|---|---|
| `POST /api/invitations/{id}/text-suggestions` (sahip) | `InvitationTextSuggestionController`, `SuggestInvitationTextsRequest`, `SuggestInvitationTextsAction`, `ChargeAssistantQuotaAction` | İstem sunucuda; en çok 3 öneri; sözleşmeye uymayan atılır; sohbetle **aynı günlük kota** ve `throttle:assistant`. Kota `AskAssistantAction`'dan ayrı Action'a taşındı, davranış aynı |
| `POST /api/public/invitations/{id}/report` (misafir) | `PublicInvitationReportController`, `ReportInvitationRequest`, `ReportInvitationAction`, `InvitationReport`, `InvitationReportReason`, `InvitationReportedNotification`, migration `invitation_reports` | Yalnızca yayındaki davetiye; IP hash; honeypot sessiz 204; `throttle:contact`; `DAVETKART_REPORTS_EMAIL` doluysa kuyrukta posta |

## Kapılar

`composer check`: **1398/1398** ✅ (13 yeni test: `InvitationTextSuggestionTest`, `InvitationReportTest`).

## Elle doğrulama

1. `.env`'de AI sağlayıcısı tanımlıyken sahibin davetiyesine `POST /api/invitations/{id}/text-suggestions`
   `{"key":"rsvp.submit","current":"Katılımımı Bildir"}` → `{ data: { suggestions: [...] } }`, `assistant_usages` +1.
2. Başka hesabın davetiyesi → 404, kota değişmez.
3. Yayındaki davetiyeye `POST /api/public/invitations/{id}/report` `{"reason":"phishing"}` → 204, `invitation_reports`'ta
   satır, `ip_hash` dolu; taslağa → 404.
4. `DAVETKART_REPORTS_EMAIL` dolu ve kuyruk çalışırken bildirim postası gelir.
