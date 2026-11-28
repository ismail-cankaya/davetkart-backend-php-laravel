# Faz 27–29 — v2.1 Önizleme ve Tasarım Stüdyosu (backend payı)

> **Tarih:** 28 Kasım 2026 · **Dallar:** `faz-27-v2.1-studio` (`faz-26-v2.0-texts`'ten) → `faz-29-v2.1-share` · **Kararlar:**
> K318–K329 (docs `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-27.md`) · **Takip:** docs `phases/faz-27/`, `faz-28/`, `faz-29/`
> Stüdyo, hızlı düzenleme ve paylaşım frontend'dedir; backend'in payı taslak katmanı ve imzalı önizleme. Faz 28'de backend değişmedi.

## Ne kuruldu

| Uç | Parçalar | Not |
|---|---|---|
| `PUT /api/invitations/{id}/pending` (sahip) | `InvitationPendingController@update`, `SavePendingChangesAction`, `PendingChanges`, migration `pending_changes` | Yayındaysa yalnızca canlıdan farklı kolonlar ve program taslağa; olaysız, `updated_at`'siz; paket sorulmaz. Taslak davetiyede doğrudan yazar (K323) |
| `POST /api/invitations/{id}/pending/publish` (sahip) | `PublishPendingChangesAction` | `UpdateInvitationAction` üzerinden: aynı kilit, aynı 402; başarıda taslak boşalır, 402'de kalır |
| `DELETE /api/invitations/{id}/pending` (sahip) | `DiscardPendingChangesAction` | Taslak atılır |
| `PUT /api/invitations/{id}` (mevcut) | `UpdateInvitationAction` | Yazdığı kolonlar taslaktan düşer (K324, hızlı düzenleme) |
| `POST /api/invitations/{id}/preview-link` (sahip) | `InvitationPreviewController@store`, `CreatePreviewLinkAction`, `PreviewLink`, `PreviewLinkResource` | 24 saatlik göreli imzalı frontend adresi (K328) |
| `GET /api/public/invitations/{id}/preview?expires&signature` | `InvitationPreviewController@show`, `BuildInvitationPreviewAction` | Canlı + taslak, kaydedilmeden; `no-store`, `noindex`; reddedilen imza 404 (H7) |

`InvitationResource` artık `hasPendingChanges` ve `pendingChanges` taşır (yalnızca sahibin yanıtı).

## Kapılar

| Adım | `composer check` |
|---|---|
| 27.3 | **1409/1409** ✅ (11 yeni: `InvitationPendingChangesTest`; `InvitationTest` sözleşme anahtarları) |
| 29.4 | **1415/1415** ✅ (6 yeni: `InvitationPreviewLinkTest`) |

Ortam: bu oturum, PHP 8.5.13 kaynaktan, PostgreSQL 16 (üretim 18).

## Elle doğrulama

1. Yayındaki davetiyeye `PUT /api/invitations/{id}/pending` `{"invitation":{"title":"Taslak"}}` → 200,
   `data.hasPendingChanges = true`; `GET /api/public/invitations/{id}` eski başlığı gösterir.
2. `POST …/pending/publish` → 200, misafir yanıtı yeni başlık; sahip olunan paketin üstünde bir değişiklikte 402 ve taslak kalır.
3. `POST /api/invitations/{id}/preview-link` → `data.url`; sorgu dizesini `/api/public/invitations/{id}/preview`'a ekleyince
   taslaklı yük; imzayı bozunca 404.
4. Sunucuda `FRONTEND_URL` doğru olmalı (bağlantı oradan kurulur); API ve frontend farklı alan adındaysa da imza göreli
   olduğu için çalışır.
