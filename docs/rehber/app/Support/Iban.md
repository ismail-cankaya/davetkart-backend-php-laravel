# `app/Support/Iban.php`

> **Faz:** 10 — Dilim F, adım 10.62 · **Karar:** **K105** (IBAN her kayıtta doğrulanır)
> **Kullanan:** `iban` doğrulama kuralı (`AppServiceProvider::configureValidationRules()`) →
> `InvitationRequest` → `invitation.iban`
> **Frontend karşılığı:** `src/utils/iban.ts` (aynı algoritma)

---

## Ne yapıyor?

| Metot | İş |
|---|---|
| `normalize()` | Boşlukları atar, harfleri büyütür |
| `isValid()` | Biçim + (TR ise) uzunluk + ISO 13616 mod-97 |

Denetim sırası: biçim (`2 harf + 2 rakam + 11–30 hane/harf`) → `TR` ise tam 26 karakter → sağlama
toplamı (ilk dört karakter sona, harfler sayıya A=10 … Z=35, 97'ye bölümden kalan 1).

## Kararlar

### Neden her kayıtta? (K105)

İsmail'in kararı (1 Ekim 2026). Yanlış yazılmış bir IBAN, misafirin gönderdiği hediyenin başka bir
hesaba ya da hiçbir yere gitmesi demek. Sağlama toplamı tek hane hatalarının ve yan yana iki hanenin
yer değiştirmesinin **tamamını** yakalar.

Bilinen bedeli: editör yazarken otomatik kaydediyor. IBAN yarım yazılmışken kayıt 422 alır ve diğer
alanlar da o kayıtta yazılmaz. Frontend IBAN alanının altında anlık bir uyarı gösteriyor (FE 10.19)
ki kullanıcı neden kaydedilmediğini görsün.

### Neden değer normalize edilip saklanmıyor?

Kural kendi içinde normalize ediyor, değer **yazıldığı gibi** saklanıyor (`TR33 0006 …`). Saklarken
normalize etseydik otomatik kaydetmenin cevabı (`TR330006…`) kullanıcı yazarken input'a geri
yazılabilir ve imleci kaçırırdı. Gösterim zaten `formatIban()` ile yapılıyor.

Kolon 34 karakter (`max:34` korunuyor): dörtlü gruplanmış bir TR IBAN'ı 32 karakter, sığıyor. Boşluklu
uzun bir yabancı IBAN 422 `max` alır; 500 değil.

### Neden yalnızca TR için uzunluk?

Ülke başına uzunluk tablosu 80'e yakın satır ve sağlama toplamı yanlış uzunluğun çoğunu zaten yakalıyor.
TR, kullanıcıların neredeyse tamamı ve tek satırlık bir kontrol. Yabancı IBAN'lar (yurt dışındaki
akraba) biçim + sağlama toplamıyla kabul ediliyor.

### Neden kural nesnesi değil, adlı kural?

`ApiExceptionRenderer` başarısız kuralın **adını** hata zarfına yazıyor. Kural nesnesi sınıf adıyla
raporlanırdı ve framework/sınıf adı sözleşmeye sızardı (D6). `Validator::extend('iban', …)` hataya
`{"rule":"iban"}` olarak çıkıyor; frontend metni `validation.rules.iban` anahtarından buluyor.

## Testler

`tests/Unit/IbanTest.php`: 5 geçerli, 9 geçersiz vaka. İki vaka **sağlama toplamı doğru** olacak
şekilde hesaplandı (`TR23000610051978645784132`: 25 karakter; `DE1312345`: çok kısa). Ancak bu iki
vaka sayesinde uzunluk ve biçim kontrolleri tek başlarına sınanıyor. İlk sürümde geçersiz örneklerin
hepsi zaten sağlama toplamına takıldığı için biçim kontrolünü silen mutant yeşil kaldı.
