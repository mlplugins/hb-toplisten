# Übergabe — Etappe 3: Fehler beheben, verifizieren, Rollout

Kopiere diesen Text als ersten Prompt in eine neue Session.

---

**Kontext:** WordPress-Plugin «HB Top-Listen» (Slug `hb-top-listen`), voller Auftrag in `prompt-hb-top-listen-plugin.md`. Etappe 1 (Analyse) und Etappe 2 (Plugin bauen) sind abgeschlossen — die Befunde stehen im Projekt-Memory (`hb-toplisten-etappen.md`), **bitte zuerst lesen**. Ich habe beim Ausprobieren weitere Fehler entdeckt und beschreibe sie gleich; bitte die zuerst anschauen, bevor es mit der Verifikation weitergeht.

**Stand:**
- Repo `github.com/mlplugins/hb-toplisten`, Branch `main`, letzter Commit `662bd73` (noch nicht gepusht). Plugin-Version 0.2.0, lokal aktiv.
- Plugin-Ordner `hb-top-listen/` im Repo, per Junction nach `C:/Users/info/Local Sites/hempbasement-test/app/public/wp-content/plugins/hb-top-listen` — im Repo editieren = WordPress sieht es sofort.
- Lokale Seite http://hempbasement.test/ (Local by Flywheel), Startseite = Seite ID 2. Nur lokal entwickeln/testen.
- DB lesend: `C:/Users/info/AppData/Roaming/Local/lightning-services/mariadb-10.11.18+0/bin/win32/bin/mariadb.exe -h 127.0.0.1 -P 10004 -u root -proot local`
- Dateien: `hb-top-listen.php` + `includes/class-hb-top-listen-{ranking,cache,elements,admin}.php` + `uninstall.php`.
- Shortcodes: `[hb_top_products]` (count_revenue, count_qty, period, date_from, date_to, in_stock, cat_mode, cats, fallback) und `[hb_top_categories]` (count, criterion, period, date_from, date_to, exclude_top, cat_mode, cats, fallback). Alle übrigen Attribute werden an `ux_products` / `ux_product_categories` durchgereicht.
- Admin: WooCommerce › HB Top-Listen (Kontrolltabelle + Button «Cache neu berechnen»).

**Was in Etappe 2 schon verifiziert wurde:** YTD-Summen der Engine = unabhängige SQL-Abfrage (1383 Produkte, 101'927.16 netto, 8494 Stk); Vollrückerstattung nettet auf 0; Dedup (4+4 → 8 eindeutige Produkte trotz 3 Überschneidungen); Kategorien-Filter ein/aus inkl. Unterkategorien; Randfälle (ungültiger Zeitraum, 0 Treffer, Flatsome deaktiviert) geben leer aus; Reihenfolge der Flatsome-Ausgabe = Rangliste; beide Elemente erscheinen im UX Builder.

**Aufgaben Etappe 3:**
1. **Neue Fehler des Nutzers aufnehmen und beheben** (kommen im Chat), jeweils lokal reproduzieren und gegenprüfen — Reihenfolge-/Hook-Fehler nur im echten Browser sichtbar, nicht im CLI-Test.
2. **Admin-Kontrolltabelle und UX-Builder-Vorschau im Browser prüfen.** Der Nutzer ist in Chrome eingeloggt (Claude kann sich nicht selbst einloggen). Vorschau eines platzierten Elements auf einer Testseite prüfen, **nicht** auf der Startseite.
3. **Testbestellungen anlegen und mit Analytics abgleichen:** normal, mit Gutschein, Teilrückerstattung, Vollrückerstattung, Storno, Variantenprodukt, Produkt in 2 Kategorien, Ladenverkauf. Zahlen der Kontrolltabelle mit WooCommerce › Analytics (Produkte/Kategorien, gleicher Zeitraum) vergleichen, Abweichungen erklären. Bekannte erwartete Abweichung: Analytics rollt bei Kategorien Unterkategorien in die Elternkategorien auf, das Plugin nicht. Offen prüfen: Status `wc-pickup`, `wc-gedropped`, `wc-pending-payment` zählen aktuell mit.
4. **Rollout erst nach ausdrücklichem OK des Nutzers:** die zwei bestehenden Startseiten-Bereiche (`ux_product_categories` + `ux_products` mit manuellen `ids`) durch die neuen Elemente ersetzen. Das Aussehen muss identisch bleiben. **NICHT ungefragt die Startseite ändern.**

**Technik:** PHP 8.x, WordPress-Coding-Standards, deutsche Texte in Schweizer Schreibweise («ss» statt «ß»), schlank halten.
