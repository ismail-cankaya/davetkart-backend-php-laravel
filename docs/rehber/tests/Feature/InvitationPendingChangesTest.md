# `tests/Feature/InvitationPendingChangesTest.php`

Faz 27.3 (K323, K324). Taslak misafire sızmaz; kaydetme `InvitationChanged` fırlatmaz ve `updated_at`'i değiştirmez;
canlıya eşit değer taslak değildir; taslaklar birleşir; yayınlama uygular ve boşaltır; paket üstü taslak 402 alır ve
kalır; vazgeç; doğrudan güncelleme yazdığı alanı taslaktan düşürür; taslak davetiye doğrudan yazar; yalnızca sahibi;
doğrulama normal güncellemeyle aynı.
