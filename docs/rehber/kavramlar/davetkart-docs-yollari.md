# `davetkart-docs` yolları — bu depodan doküman deposuna atıf

> Faz 19.4 (`ARC-05`, 22 Kasım 2026). Eşleme: `davetkart-docs: _restructure/2026-11-backend-claude-map.md`.

## Neden

Kod dışı dokümanlar (bağlam, karar ekleri, faz planları, denetimler, prompt'lar, notlar) tek evde
durur: `davetkart-docs`. Bu depodaki `docs/` ve `docs/rehber/` yalnızca kodun kılavuzudur. Eskiden
backend'in kendi `claude/` klasörü ikinci bir ev olmuştu; K49–K106'nın kaynağı oradaydı ve 12 Kasım
düzenlemesinden sonra 25 dosya artık var olmayan `claude/…` yollarını gösteriyordu.

## Desen

Bu depodan docs deposundaki bir dosyayı gösterirken **tek biçim**:

```markdown
`davetkart-docs: context/PHP-LARAVEL-SETUP.md` §7
```

- Kod biçiminde düz metin; markdown bağlantısı değil. Depolar GitHub'da ayrıdır, `../../davetkart-docs/…`
  gibi göreli bağlantı orada kırılır ve yerelde klasör adına bağlı kalır.
- Yol, docs deposunun köküne göredir. Bölüm işareti (`§7`) kod biçiminin dışına yazılır.
- Tersi (docs → backend) docs deposunda: `` backend `docs/rehber/fazlar/FAZ-13.md` §4 ``.

## Hangi hataya karşı

Yer değiştiren dosya için sessizce kırılan yol. `claude/` biçimindeki her yol bugün yanlıştır; şu komut
0 dönmelidir (yalnızca `claude/README.md` hariç):

```bash
grep -rlE "claude[/\\](PHP-LARAVEL-SETUP|Notlar|FAZ-|GOZDEN|TEST-DENETIMI|PROMPT)" . \
  --include=*.md --include=*.php --exclude-dir=vendor --exclude-dir=node_modules | grep -v '^\./claude/'
```

## Sık hata

- Yeni plan, rapor ya da karar ekini bu depoya (`claude/` ya da `docs/`) yazmak: `davetkart-docs`'a gider
  (`davetkart-docs: CLAUDE.md` §3 "Yeni dosya nereye?").
- Docs deposunda dosya taşınınca buradaki `davetkart-docs:` yollarını aramamak:
  `grep -rn "davetkart-docs: <eski yol>" .`

## Nasıl denenir

Yukarıdaki `grep` 0 döner. Docs deposunda `python -I _restructure/build_decision_index.py --check` 0 döner
(K49–K106'nın tanımları artık `decisions/addenda/` ve `phases/faz-10/` içinde).
