# Etappe 3 — Stand und Ergebnisse

**Stand 2026-09-18, Plugin v0.3.1.** Offen ist nur noch der Rollout auf der Startseite (Punkt 4 unten) — der braucht das ausdrückliche OK des Nutzers.

Voller Auftrag: `prompt-hb-top-listen-plugin.md`. Umgebung, Etappe 1/2 und die Vorgeschichte stehen im Projekt-Memory (`hb-toplisten-etappen.md`).

---

## Umgebung (kurz)

- Repo `github.com/mlplugins/hb-toplisten`, Branch `main`. Plugin-Ordner `hb-top-listen/` ist per Junction nach `C:/Users/info/Local Sites/hempbasement-test/app/public/wp-content/plugins/hb-top-listen` verlinkt — im Repo editieren = WordPress sieht es sofort.
- Lokale Seite http://hempbasement.test/ (Local by Flywheel), WP 7.1.1, WooCommerce 11.1.0, HPOS aktiv, Zeitzone Europe/Zurich, Preise inkl. Steuer (CH 8.1 %). Startseite = Seite ID 2. **Nur lokal entwickeln/testen.**
- DB lesend: `C:/Users/info/AppData/Roaming/Local/lightning-services/mariadb-10.11.18+0/bin/win32/bin/mariadb.exe -h 127.0.0.1 -P 10004 -u root -proot local`
- **WordPress per CLI laden** (dauert ~2 Min): Locals PHP `lightning-services/php-8.2.30+1/bin/win64/php.exe -n -d extension_dir=<…>/ext` **plus die Extensions `mysqli mbstring curl openssl exif fileinfo gd intl sodium zip`** — mit weniger bricht `wp-load.php` still und ohne Ausgabe ab. Im Skript `define('DB_HOST','127.0.0.1:10004')` vor `require wp-load.php` (die Warnung «Constant DB_HOST already defined» ist gewollt).
- **Skripte nicht per Bash-Heredoc schreiben** — daran ist ein Versuch gescheitert; Datei direkt schreiben.
- **Datenlage:** Die echten Bestelldaten enden am 31.07.2026. Zeitraum-Logik immer über ein `custom`-Fenster im Juli prüfen, nie über «Letzte 30 Tage».
- `DISABLE_WP_CRON` steht in der lokalen `wp-config.php` auf `true`. Deshalb zeigt die Admin-Box «Nächste automatische Neuberechnung» ein Datum in der Vergangenheit — **kein Plugin-Fehler**, auf dem Live-Server läuft der Cron normal.

---

## 1. Fehler Nr. 1 «nur 2 Kategorien» — erledigt

Kein Logikfehler, sondern die Datenlage (siehe oben). Derselbe Code liefert für 01.–30.07.2026 volle 8 Kategorien.

## 2. Status-Entscheid (v0.2.1) — erledigt

`wc-pending-payment` und `wc-gedropped` werden **ausgeschlossen** — Konstante `HB_Top_Listen_Ranking::UNPAID_STATUSES`, dazu der Filter `hb_top_listen_excluded_statuses`. `wc-pickup` (Ladenverkauf) zählt weiterhin mit. Das Plugin weicht damit bewusst von WooCommerce Analytics ab, das diese zwei Status mitzählt.

## 3. Layout-Typ-Default «row» (v0.3.0) — erledigt und im Builder bestätigt

`HB_Top_Listen_Elements::DEFAULT_LAYOUT_TYPE = 'row'`, gesetzt als Panel-Default **und** in `with_default_type()` in die Ausgabe nachgezogen (der UX Builder schreibt Attribute auf Default-Wert nicht in den Shortcode). Im echten Builder gegengeprüft: Panel zeigt «Row», Ausgabe rendert `type="row"`.

## 4. Browser-Prüfung — erledigt (2026-09-18)

Alles im eingeloggten Chrome auf der lokalen Seite geprüft:

- **Admin-Kontrolltabelle** (WooCommerce › HB Top-Listen): rendert fehlerfrei, Zeitraum- und Status-Zeile stimmen, Kategorien-Mehrfachauswahl und Filterformular funktionieren, «Element-Ergebnis» inkl. Auffüll-Kennzeichnung und fertigem `ids="…"`-String.
- **Testseite #118857** «HB Top-Listen – Testseite» (**Entwurf**, Slug `hb-top-listen-testseite`) mit vier Abschnitten angelegt: Kategorien/Produkte je einmal mit Juli-Fenster (wie Startseite) und einmal mit Standard-Einstellungen. Vorschau: `http://hempbasement.test/?page_id=118857&preview=true`. Alle vier Abschnitte rendern mit je 8 Einträgen als Zeilen-Raster (2 × 4), Kategorien mit Overlay-Stil — optisch wie die Startseite.
- **UX Builder** auf der Testseite: lädt ohne Fehler, beide Elemente stehen im Layout-Baum, Live-Vorschau rendert. Options-Panel vollständig (Top-Liste, Kategorien-Filter, Stil, Layout, Meta, Bild, Text, Advanced) und liest die Shortcode-Werte korrekt zurück (Kriterium Umsatz, Zeitraum Manuell, Von/Bis, Stil Overlay, Layout-Typ **Row**).

## 5. Testbestellungen + Analytics-Abgleich — erledigt (2026-09-18)

Angelegt am 18.09.2026, alle mit Meta `_hb_test_data = 1` markiert, Analytics-Sync erzwungen (Action Scheduler läuft im CLI nicht). Beim Anlegen waren Mailversand, ausgehende HTTP-Requests und Lagerbuchungen abgeschaltet.

| # | Szenario | Bestellung | Produkt | Soll netto / Stk | Ist | OK |
|---|---|---|---|---|---|---|
| 1 | Normal, 2 Stk à 23.70 | 118846 | 118740 | 43.85 / 2 | 43.85 / 2 | ✔ |
| 2 | Gutschein 10 % auf 40.00 | 118847 | 118705 | 33.58 / 1 | 33.58 / 1 | ✔ |
| 3 | Teilrückerstattung (2 Stk, 1 zurück) | 118848 + Refund 118855 | 118614 | 26.82 / 1 | 26.82 / 1 | ✔ |
| 4 | Vollrückerstattung | 118849 + Refund 118856 | 118610 | 0.00 / 0 | 0.00 / 0 | ✔ |
| 5 | Storno (`wc-cancelled`) | 118850 | 118603 | gar nicht | gar nicht | ✔ |
| 6 | Variantenprodukt (2 Varianten, 1+2 Stk) | 118851 | 94718 | 146.20 / 3 | 146.20 / 3 | ✔ |
| 7 | Produkt in 2 Kategorien | 118852 | 115383 | 45.33 / 1 | 45.33 / 1 | ✔ |
| 8 | Ladenverkauf (`wc-pickup`) | 118853 | 118600 | 13.60 / 1 | 13.60 / 1 | ✔ |
| 9 | Unbezahlt (`wc-pending-payment`) | 118854 | 115385 | gar nicht | gar nicht | ✔ |

Belegt damit: Gutschein-Rabatt steckt bereits in `product_net_revenue`; Refund-Zeilen netten automatisch (Vollrückerstattung = 0, kein Doppelabzug); Refund-Zeilen zu einer weiter offenen Bestellung tragen deren Status (`wc-completed`) und zählen richtig; Varianten werden über `product_id` zusammengefasst; ein Produkt in zwei Kategorien zählt in der Produktliste einmal und in beiden Kategorien voll.

### Abgleich mit WooCommerce Analytics

Verglichen wurde gegen die Report-DataStores, die auch die Analytics-Oberfläche speist (`…\Admin\API\Reports\Products\DataStore` bzw. `…\Categories\DataStore`), **mit Paginierung** — ohne Paginierung liefert Analytics nur die ersten 100 Zeilen und der Vergleich täuscht Hunderte Abweichungen vor.

**Fenster 18.09.2026 (nur die Testbestellungen):**

| | Plugin | Analytics | Differenz |
|---|---|---|---|
| Produkte | 7 Zeilen / 309.38 / 9 Stk | 8 Zeilen / 354.71 / 10 Stk | −45.33 / −1 Stk |
| Kategorien | 5 Zeilen / 354.71 / 10 Stk | 5 Zeilen / 445.37 / 12 Stk | −90.66 / −2 Stk |

Einzige Ursache: die unbezahlte Bestellung 118854 (Produkt 115385, 45.33). Sie steckt in zwei Kategorien, daher dort 2 × 45.33.

**Fenster 01.–31.07.2026 (echte Daten):**

| | Plugin | Analytics | Differenz |
|---|---|---|---|
| Produkte | 487 Zeilen / 16 999.43 / 1336 Stk | 488 Zeilen / 17 036.43 / 1344 Stk | −37.00 / −8 Stk |
| Kategorien | 95 Zeilen / 17 469.95 | 96 Zeilen / 17 573.66 | −103.71 |

**Genau eine abweichende Produktzeile** im ganzen Monat: Produkt 88693, Bestellung 118679, Status `wc-gedropped`, 8 Stk / 37.00. Bei den Kategorien zwei Zeilen: «Vaporizer Zubehör» (−37.00, dasselbe Produkt) und «Unkategorisiert» (−66.71, vom Plugin bewusst immer weggelassen).

→ **Beide Abweichungen sind die dokumentierten Absichten, sonst stimmt es Zeile für Zeile.**

### Korrektur einer Annahme aus der Übergabe (v0.3.1)

Die bisherige Annahme «Analytics rollt bei Kategorien Unterkategorien in die Elternkategorien auf» ist **falsch**. Beleg aus dem Juli-Fenster: «Tabak Shop Schweiz» steht bei Plugin **und** Analytics auf 92.87, während die Unterkategorien zusammen 5500.57 ergeben; «Headshop Schweiz», «CBD Shop Schweiz», «Grow Shop Schweiz» und «Vape Shop Schweiz» kommen in **beiden** Listen gar nicht vor. Analytics rechnet also genauso wie das Plugin jede Kategorie einzeln. Der entsprechende Hinweistext in der Admin-Kontrolltabelle war dadurch irreführend und wurde in v0.3.1 durch die zwei tatsächlichen Abweichungen ersetzt.

---

## Offen: 6. Rollout — braucht ausdrückliches OK

Die zwei bestehenden Startseiten-Bereiche (Seite ID 2) durch die neuen Elemente ersetzen. Ist-Zustand:

```
[ux_product_categories style="overlay" type="row" ids="3520,44,434,447,446,18,3519,25"]
[ux_products type="row" show_rating="0" show_quick_view="0" equalize_box="true" ids="105333,109014,100381,1993,57655,52796,103194,105822"]
```

Vorgesehener Ersatz (Aussehen bleibt identisch, `type="row"` kommt jetzt ohnehin vom Default):

```
[hb_top_categories style="overlay" type="row" count="8" criterion="revenue" period="<offen>"]
[hb_top_products type="row" show_rating="0" show_quick_view="0" equalize_box="true" count_revenue="4" count_qty="4" period="<offen>"]
```

Vor dem Rollout zu klären:
1. **Welcher Zeitraum** für die Live-Listen — «Letzte 30 Tage», «Letzter Kalendermonat» oder «Jahr bisher»? Auf der lokalen Kopie ist «Letzte 30 Tage» wegen der Datenlage fast leer, auf dem Live-Shop wäre es der naheliegende Wert.
2. **Nicht lagernde Produkte**: aktuell dürfen sie erscheinen (`in_stock` aus, `woocommerce_hide_out_of_stock_items` = nein). Im Juli-Test trug ein Top-Produkt das Label «NICHT VORRÄTIG». Bei automatischen Listen ggf. `in_stock="true"` setzen.

**Die Startseite NICHT ungefragt ändern.**

---

## Testdaten wieder entfernen

Alle in dieser Etappe erzeugten Objekte tragen die Meta `_hb_test_data = 1`: Bestellungen 118846–118856, Gutschein 118845 (`hb-test-rabatt10`), Testseite 118857. Ein Aufräum-Skript liegt im Session-Scratchpad (`t-testdaten-entfernen.php`, ohne Argument nur Anzeige, mit `loeschen` wird gelöscht). Solange die Bestellungen stehen bleiben, prägen sie das «Letzte 30 Tage»-Fenster der lokalen Kopie.

**Technik:** PHP 8.x, WordPress-Coding-Standards, deutsche Texte in Schweizer Schreibweise («ss» statt «ß»), schlank halten.
