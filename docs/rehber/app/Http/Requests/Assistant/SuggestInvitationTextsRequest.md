# `app/Http/Requests/Assistant/SuggestInvitationTextsRequest.php`

Faz 25 (K310). `key` sözleşmedeki anahtarlardan biri (`in:` kuralı, `TextSlots::keys()`), `current` en çok 400
karakterlik metin. Geçersiz istek kotadan düşmeden 422 döner (doğrulama Action'dan önce).
