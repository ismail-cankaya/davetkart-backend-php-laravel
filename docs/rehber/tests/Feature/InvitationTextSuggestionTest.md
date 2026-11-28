# `tests/Feature/InvitationTextSuggestionTest.php`

Faz 25 (K310). Sahibi üç geçerli öneri alır (numara/madde/tırnak temizlenir, istem anahtarı ve şimdiki metni taşır);
sözleşmeye uymayan, şimdiki metnin aynısı ve tekrar öneri atılır; yer tutuculu anahtarda yer tutucusuz öneri atılır;
başkasının davetiyesi 404 ve kota düşmez; bilinmeyen anahtar 422 ve kota düşmez; sohbetle aynı kota (dolunca 429
`ASSISTANT_QUOTA_EXCEEDED`); kimliksiz 401.
