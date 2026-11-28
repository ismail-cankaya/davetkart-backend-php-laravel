# `app/Actions/Assistant/SuggestInvitationTextsAction.php`

Faz 25 (K310) — **altyapı**. Bir şablon metni (`rsvp.submit` gibi bir sözleşme anahtarı) için en çok üç öneri.

- **Kota:** sohbetle aynı günlük kota (`ChargeAssistantQuotaAction`), aynı hız kovası (`throttle:assistant`).
- **İstem sunucuda kurulur:** etkinlik türü (`category_id`), anahtar, şimdiki metin, sözleşmedeki `maxLength` ve
  yer tutucular. Kullanıcı serbest istem yazamaz; gövdede yalnızca `key` ve `current` vardır.
- **Süzgeç:** yanıt satırlara bölünür; baştaki numara/madde işareti ve tırnaklar atılır; boş, şimdiki metnin aynısı,
  tekrar ve `TextSlots::isValidText()`'ten geçmeyen (uzun, satır sonlu, yer tutucusu eksik) öneri atılır.
  Hiç öneri kalmazsa boş liste döner (200).
- **Uygulanmaz:** öneri yalnızca döndürülür; frontend önizletir, kullanıcı "Kaydet" demedikçe `text_overrides`'a yazılmaz.

İsmail (28 Kasım): *"kota olacak ancak ai kısmını daha sonra ayrıntılı çalışacağız. Şu anda altyapısını hazırla."*
İstemin dili, ton seçimi ve çoklu dil sonraki fazda.
