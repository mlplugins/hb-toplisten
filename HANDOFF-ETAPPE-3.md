# Übergabe — Etappe 3: Fehler beheben, verifizieren, Rollout

Kopiere diesen Text als ersten Prompt in eine neue Session.

---

**Kontext:** WordPress-Plugin «HB Top-Listen» (Slug `hb-top-listen`), voller Auftrag in `prompt-hb-top-listen-plugin.md`. Etappe 1 (Analyse) und Etappe 2 (Plugin bauen) sind abgeschlossen — die Befunde stehen im Projekt-Memory (`hb-toplisten-etappen.md`), **bitte zuerst lesen**. Etappe 3 läuft bereits; der Stand unten ist aktuell.

**Stand (2026-09-17):**
- Repo `github.com/mlplugins/hb-toplisten`, Branch `main`, Plugin-Version **0.3.0**, lokal aktiv. Arbeitsverzeichnis ist sauber. `origin/main` steht auf `8b36574`; der Commit mit v0.2.1 + v0.3.0 liegt **nur lokal** und ist noch nicht gepusht (`git log origin/main..HEAD`).
- Plugin-Ordner `hb-top-listen/` im Repo, per Junction nach `C:/Users/info/Local Sites/hempbasement-test/app/public/wp-content/plugins/hb-top-listen` — im Repo editieren = WordPress sieht es sofort.
- Lokale Seite http://hempbasement.test/ (Local by Flywheel), Startseite = Seite ID 2. Nur lokal entwickeln/testen.
- DB lesend: `C:/Users/info/AppData/Roaming/Local/lightning-services/mariadb-10.11.18+0/bin/win32/bin/mariadb.exe -h 127.0.0.1 -P 10004 -u root -proot local`
- Dateien: `hb-top-listen.php` + `includes/class-hb-top-listen-{ranking,cache,elements,admin}.php` + `uninstall.php`.
- Shortcodes: `[hb_top_products]` (count_revenue, count_qty, period, date_from, date_to, in_stock, cat_mode, cats, fallback) und `[hb_top_categories]` (count, criterion, period, date_from, date_to, exclude_top, cat_mode, cats, fallback). Alle übrigen Attribute werden an `ux_products` / `ux_product_categories` durchgereicht.
- Admin: WooCommerce › HB Top-Listen (Kontrolltabelle + Button «Cache neu berechnen»).

**Wichtig — Datenlage der lokalen Kopie:** Die echten Bestelldaten enden am **31.07.2026**. August/September enthalten nur die manuellen Testbestellungen 118819–118844. Ein «Letzte 30 Tage»-Fenster ist deshalb praktisch leer und liefert eine sehr kurze Liste — **das ist kein Fehler**. Zeitraum-Logik immer über ein `custom`-Fenster im Juli (oder früher) prüfen.

**Was bereits verifiziert ist:** YTD-Summen der Engine = unabhängige SQL-Abfrage (1383 Produkte, 101'927.16 netto, 8494 Stk); Vollrückerstattung nettet auf 0; Dedup (4+4 → 8 eindeutige Produkte trotz 3 Überschneidungen); Kategorien-Filter ein/aus inkl. Unterkategorien; Randfälle (ungültiger Zeitraum, 0 Treffer, Flatsome deaktiviert) geben leer aus; Reihenfolge der Flatsome-Ausgabe = Rangliste; beide Elemente erscheinen im UX Builder.

**Bereits erledigte Etappe-3-Punkte:**
1. **Fehler Nr. 1 «Top-Kategorien, Letzte 30 Tage, Auffüllen aus → nur 2 Kategorien»** — kein Logikfehler, sondern die Datenlage (siehe oben). Belegt: derselbe Code liefert für 01.–30.07.2026 volle 8 Kategorien; die Lookup-Tabelle ist vollständig (keine Bestellung ohne Lookup-Zeile).
2. **Status-Frage entschieden (v0.2.1):** `wc-pending-payment` und `wc-gedropped` werden neu **ausgeschlossen** — Konstante `HB_Top_Listen_Ranking::UNPAID_STATUSES`, dazu der Filter `hb_top_listen_excluded_statuses`. `wc-pickup` (Ladenverkauf) zählt weiterhin mit. Ebenfalls in v0.2.1: `base_where()` gegen eine leere Statusliste gehärtet (sonst `s.status NOT IN ()` = SQL-Fehler).
   → **Das Plugin weicht damit bewusst von WooCommerce Analytics ab**, das diese beiden Status mitzählt. Betroffen sind im ganzen Datenbestand 3 Bestellungen, 39.88 netto / 12 Stk — beim Abgleich unten einrechnen.
3. **Layout-Typ-Default auf «row» gesetzt (v0.3.0):** Der Nutzer meldete, beide Elemente zeigten einen Slider statt des Zeilen-Rasters (8 Einträge = 2 × 4). Kein Bug — die Wahl steckt in der Flatsome-Gruppe «Layout» › «Type» (slider/slider-full/row/masonry/grid) und wird korrekt durchgereicht, nur stand der Default auf Flatsomes `slider`. Neu: `HB_Top_Listen_Elements::DEFAULT_LAYOUT_TYPE = 'row'`, gesetzt als Panel-Default **und** in `with_default_type()` in die Ausgabe nachgezogen (der UX Builder schreibt Attribute auf Default-Wert nicht in den Shortcode, sonst würde das Panel «Row» zeigen und Flatsome `slider` rendern). Slider/Grid/Masonry bleiben frei wählbar.
   → Für 1:1-Gleichheit mit der Startseite fehlen noch `style="overlay"` bei den Kategorien (Flatsome-Default ist `badge`) und `show_rating="0" show_quick_view="0" equalize_box="true"` bei den Produkten. Beides sind Builder-Optionen; Defaults dafür wurden bewusst **nicht** angefasst — beim Rollout setzen oder vorher mit dem Nutzer klären.

**Offene Aufgaben Etappe 3:**
1. **Weitere Fehler des Nutzers aufnehmen und beheben** (kommen im Chat), jeweils lokal reproduzieren und gegenprüfen — Reihenfolge-/Hook-Fehler sind nur im echten Browser sichtbar, nicht im CLI-Test.
2. **Admin-Kontrolltabelle und UX-Builder-Vorschau im Browser prüfen.** Der Nutzer ist in Chrome eingeloggt (Claude kann sich nicht selbst einloggen). Vorschau eines platzierten Elements auf einer Testseite prüfen, **nicht** auf der Startseite.
3. **Testbestellungen anlegen und mit Analytics abgleichen:** normal, mit Gutschein, Teilrückerstattung, Vollrückerstattung, Storno, Variantenprodukt, Produkt in 2 Kategorien, Ladenverkauf. Zahlen der Kontrolltabelle mit WooCommerce › Analytics (Produkte/Kategorien, gleicher Zeitraum) vergleichen, Abweichungen erklären. Bekannte erwartete Abweichungen: (a) Analytics rollt bei Kategorien Unterkategorien in die Elternkategorien auf, das Plugin nicht; (b) die unbezahlten Status aus Punkt 2 oben.
4. **Rollout erst nach ausdrücklichem OK des Nutzers:** die zwei bestehenden Startseiten-Bereiche (`ux_product_categories` + `ux_products` mit manuellen `ids`) durch die neuen Elemente ersetzen. Das Aussehen muss identisch bleiben. **NICHT ungefragt die Startseite ändern.**

**Technik:** PHP 8.x, WordPress-Coding-Standards, deutsche Texte in Schweizer Schreibweise («ss» statt «ß»), schlank halten.

**WordPress per CLI laden** (dauert ~2 Min): Locals PHP `lightning-services/php-8.2.30+1/bin/win64/php.exe -n -d extension_dir=<…>/ext -d memory_limit=1G -d display_errors=1` **plus die Extensions `mysqli mbstring curl openssl exif fileinfo gd intl sodium zip`** — mit weniger bricht `wp-load.php` still und ohne jede Ausgabe ab. Im Skript `define('DB_HOST','127.0.0.1:10004')` vor `require wp-load.php` setzen (die Warnung «Constant DB_HOST already defined» ist harmlos und gewollt); für Admin-Render zusätzlich `wp-admin/includes/template.php` nachladen. Nach Code-Änderungen mit Versions-Bump ist «Cache neu berechnen» nicht nötig — `HB_TOP_LISTEN_VERSION` steckt im Transient-Schlüssel.
