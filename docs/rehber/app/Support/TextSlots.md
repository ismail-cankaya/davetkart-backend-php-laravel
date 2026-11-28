# `app/Support/TextSlots.php`

Faz 24 (K301, K303, K304). Düzenlenebilir davetiye metinleri sözleşmesinin (`contracts/text-slots.json`) okuyucusu ve
`text_overrides` doğrulaması.

## Sözleşme nereden gelir

Anahtarlar varsayılan metinlerle birlikte **frontend'de** doğar (`src/invitationText/locales/tr.json`). Frontend
`npm run text-slots:export` ile `src/contracts/text-slots.json`'ı üretir; dosya buraya **aynen** kopyalanır.
`plan-rules.json`'ın tersi yöndür: plan kuralı backend'in malı, metin anahtarı ise metnin kendisiyle birlikte frontend'in.
Frontend `verify:text-slots` iki kopyanın birebir aynı olduğunu (kardeş depo yanındaysa) sınar; burada
`TextSlotsContractTest` biçimi sınar.

Her anahtar: `group` (editör paneli grubu), `scope` (`shared` / `category` / `template`, şablon/kategori değişiminde
ne olacağı), `maxLength`, `multiline`, isteğe bağlı `placeholders` (`rsvp.deadline` → `{date}`).

## Doğrulama (`isValidOverrides`)

`InvitationRequest`'te `invitation.textOverrides` → `['sometimes', 'nullable', 'array', 'text_overrides']`. Kural adı
hata zarfına `{"rule": "text_overrides"}` diye çıkar (D6). Kural tek bir evet/hayır verir; hangi anahtarın bozuk olduğu
söylenmez (editör aynı sınırları istemcide uygular).

Reddedilenler: sözleşmede olmayan dil ya da anahtar, metin olmayan değer, boş/boşluk metin, `maxLength` aşımı
(`mb_strlen`), tek satırlıkta satır sonu, herhangi bir kontrol karakteri (çok satırlıda satır sonu hariç), yer
tutucunun tam bir kez geçmemesi, dil başına `maxOverrides`'tan fazla anahtar, liste (`["x"]`) biçimi.

HTML reddedilmez ve kaçışlanmaz: metin düz yazıdır, frontend React ile çizer (kaçış orada), `dangerouslySetInnerHTML`
şablonlarda yasak (frontend `verify:text-slots`).

### Noktalı anahtarlar

Laravel doğrulama sırasında veri anahtarlarındaki noktayı geçici bir yer tutucuyla değiştirir
(`rsvp.submit` → `rsvp__dot__<16 rastgele>submit`). Kural bunu görür; `restoreDots()` geri çevirir. `validated()`
gerçek anahtarları zaten geri verir, Action'a giden veri doğrudur. Bu yüzden testlerde `assertJsonPath` noktalı
anahtar için kullanılmaz (`$response->json('...')['rsvp.submit']`).

## Saklama (`normalize`)

Boş dil grupları düşer; hiç metin kalmadıysa `null`. Model mutator'ı (`Invitation::textOverrides()`) bunu kullanır ve
`has_custom_texts`'i aynı anda yazar (K305, ayrıntı `docs/rehber/app/Models/Invitation.md` §12).
