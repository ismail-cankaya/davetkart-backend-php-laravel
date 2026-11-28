# `tests/Feature/InvitationTextOverridesTest.php`

Faz 24 (K302–K305). Sınananlar:

- **Kayıt ve okuma:** metinler kaydedilir ve geri okunur; değiştirilmemiş davetiye `textOverrides: {}` (nesne) döner;
  `{ tr: {} }` hepsini sıfırlar ve bayrağı indirir; alan gönderilmezse metinlere dokunulmaz.
- **Bayrak:** `has_custom_texts` `fill()` anında güncellenir (kaydetmeden önce), istekten doğrudan yazılamaz.
- **Doğrulama:** bilinmeyen anahtar, desteklenmeyen dil, boş metin, sınır aşımı, tek satırlıkta satır sonu, kontrol
  karakteri, yer tutucu eksik/iki kez, metin olmayan değer, liste → 422 `text_overrides`; çok satırlıda satır sonu ve
  tek yer tutucu geçer; HTML düz metin olarak saklanır; desteklenmeyen `locale` → 422 `in`.
- **Paket:** özel metin Elit ister; Gold siparişle yayın 402; Elit ile yayın 200; yayındaki Gold davetiyeye özel metin
  eklemek 402 ve satır yazılmaz; kendi içeriği (başlık, isimler, mekân) Standart'ta düzenlenir; özel metni kaldırmak
  yükseltme istemez.
- **Misafir:** misafir ucu `locale` ve `textOverrides` taşır.

Noktalı anahtarlar için `assertJsonPath` kullanılmaz; bkz. `docs/rehber/app/Support/TextSlots.md` "Noktalı anahtarlar".
