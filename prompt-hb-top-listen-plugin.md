# Auftrag: WordPress-Plugin «HB Top-Listen» (automatische Top-Produkte & Top-Kategorien für Flatsome)

## Kontext

- WooCommerce-Shop mit Flatsome-Theme. Die Startseite wird mit dem **UX Builder** gebaut.
- Testumgebung: https://hanfkeller.ch (Startseite = Seite ID 2). Erst dort entwickeln und testen, nicht auf dem Live-Shop.
- Auf der Startseite gibt es heute zwei Bereiche mit **manuell** gepflegten IDs:
  - `[ux_product_categories style="overlay" type="row" ids="3520,44,434,447,446,18,3519,25"]`
  - `[ux_products type="row" show_rating="0" show_quick_view="0" equalize_box="true" ids="105333,109014,…"]`
- Die eingebaute Flatsome-Sortierung «Bestseller» nutzt nur `total_sales` (Stückzahl seit jeher). Es gibt keinen Zeitraum und keinen Umsatz. Für Kategorien gibt es gar keine Verkaufs-Sortierung.

## Ziel

Ein kleines, eigenständiges Plugin. Es berechnet Top-Produkte und Top-Kategorien automatisch aus den echten Verkaufsdaten und zeigt sie im **bestehenden Flatsome-Design** an. Alle Einstellungen sollen direkt im UX Builder möglich sein. Halte es bewusst schlank: keine externen Libraries, kein eigenes Frontend-Design.

## Vorgehen (bitte einhalten)

1. **Zuerst analysieren, noch nichts bauen:**
   - Flatsome-Version prüfen. Im Theme-Code nachsehen, wie `ux_products` und `ux_product_categories` die Attribute verarbeiten, v.a. ob die Reihenfolge von `ids` erhalten bleibt (`post__in`) und welche Layout-Attribute es gibt.
   - Prüfen, wie eigene Elemente im UX Builder registriert werden (`add_ux_builder_shortcode`).
   - Prüfen, ob die WooCommerce-Analytics-Tabellen (`wc_order_product_lookup`, `wc_order_stats`) vollständig befüllt sind. Ist der historische Import abgeschlossen?
   - Prüfen, wie Rückerstattungen und stornierte Bestellungen in diesen Tabellen tatsächlich abgebildet sind (siehe Datenlogik unten). Mit echten Testbestellungen verifizieren, nicht annehmen.
2. **Danach** einen kurzen Umsetzungsplan vorlegen und auf mein OK warten.
3. Dann umsetzen, testen und die Ergebnisse zeigen.

## Anforderungen

### 1. Datenquelle und Berechnung

- Grundlage ist `wc_order_product_lookup` (mit `wc_order_stats` für Status und Datum). Keine direkten Abfragen auf `wp_posts`-Bestellungen, damit das Plugin HPOS-kompatibel ist (Kompatibilität deklarieren).
- **Umsatz** = `product_net_revenue`, also ohne MwSt. und **nach Abzug von Rabatten/Gutscheinen**. Versandkosten zählen nicht.
- **Stückzahl** = `product_qty`.
- **Rückerstattungen und Stornos müssen abgezogen werden:**
  - Stornierte, fehlgeschlagene, ausstehende und Entwurfs-Bestellungen zählen nicht.
  - Teil- und Vollrückerstattungen reduzieren Umsatz und Stückzahl. Die Refund-Zeilen haben in der Lookup-Tabelle negative Werte.
  - Achtung: Vollständig erstattete Bestellungen dürfen nicht doppelt abgezogen werden und dürfen auch nicht positiv stehen bleiben. Das richtige Vorgehen bitte anhand der echten Daten klären und mit Testfällen belegen.
- **Alle Verkaufskanäle zählen** (Online, Laden/Kasse, jede Zahlungsart). Also kein Filter auf `created_via` oder die Zahlungsmethode.
- **Varianten werden zum Hauptprodukt zusammengezählt** (Gruppierung nach `product_id`, nicht nach `variation_id`).
- Datumsgrenzen gelten in der Zeitzone der Website (Europe/Zurich).

### 2. Zeiträume (einstellbar)

- Letzte 30 Tage (rollierend bis heute)
- Letzter Kalendermonat
- Jahr bisher (1. Januar bis heute)
- Manuell: Von-Datum und Bis-Datum

### 3. Element «HB Top Produkte»

Einstellungen im UX Builder:

- **Anzahl nach Umsatz** (0–n) und **Anzahl nach Stückzahl** (0–n). Beispiel: 4 + 4.
- Ausgabe: erst die Umsatz-Liste, dann die Stückzahl-Liste. **Doppelte Produkte werden aussortiert** und das nächste Produkt der jeweiligen Liste rückt nach.
- Zeitraum (siehe oben)
- **Nur lagernde Produkte** (Checkbox)
- **Kategorien-Filter:** Modus «ausschliessen» oder «nur diese Kategorien», plus Auswahl der Kategorien. Unterkategorien der gewählten Kategorien gelten dabei mit.
- Nur veröffentlichte, im Katalog sichtbare Produkte. Gelöschte Produkte werden übersprungen.
- **Layout-Optionen** (Typ Row/Slider/Grid, Spalten, Style usw.) werden an `ux_products` durchgereicht, damit es genau wie heute aussieht.
- Die Ausgabe erfolgt intern über `do_shortcode('[ux_products ids="…" …]')` in der berechneten Reihenfolge.

### 4. Element «HB Top Kategorien»

Einstellungen im UX Builder:

- **Anzahl Kategorien**
- **Kriterium:** Umsatz oder Stückzahl
- Zeitraum (siehe oben)
- **Kategorien-Filter:** «ausschliessen» / «nur diese» (wie bei den Produkten). Dazu eine Checkbox **«Hauptkategorien (oberste Ebene) ausschliessen»**. Die 6 Hauptkategorien wie Headshop, CBD Shop, Growshop Schweiz usw. sollen nicht erscheinen.
- Unterkategorien jeder Ebene können in die Rangliste kommen.
- Umsatz und Stückzahl zählen für die Kategorien, denen ein Produkt **direkt zugeordnet** ist. Steckt ein Produkt in mehreren Kategorien, zählt der **volle Wert für jede** dieser Kategorien. Es gibt keine Aufsummierung in übergeordnete Kategorien.
- «Unkategorisiert» und leere Kategorien werden ignoriert.
- **Layout-Optionen** werden an `ux_product_categories` durchgereicht. Die Ausgabe erfolgt über `ids="…"` in der berechneten Reihenfolge.

### 5. Randfälle

- Bei Gleichstand entscheidet das jeweils andere Kriterium, danach der Name.
- Liefert der Zeitraum zu wenige Treffer, wird mit den Bestsellern seit jeher aufgefüllt. Das soll als Option abschaltbar sein.
- Bei null Treffern wird nichts ausgegeben. Es darf keine leere Box und keinen PHP-Fehler geben.

### 6. Performance / Caching

- Die Ranglisten dürfen **nicht bei jedem Seitenaufruf** berechnet werden. Das Ergebnis pro Einstellungs-Kombination wird zwischengespeichert (z.B. als Transient, Schlüssel = Hash der Einstellungen).
- Das Ergebnis wird regelmässig neu berechnet (z.B. alle 6 Stunden, per Action Scheduler oder WP-Cron) und zusätzlich bei Datumswechsel für die Zeiträume.
- Die Abfragen müssen auch bei vielen Bestellungen schnell sein (Indizes prüfen, `$wpdb->prepare` verwenden).
- Im UX-Builder-Editor soll die Vorschau funktionieren.

### 7. Kleine Admin-Seite (unter WooCommerce)

- Ein Button «Cache neu berechnen»
- Eine **Kontroll-Tabelle:** Zeitraum und Filter wählen, dann die Rangliste mit Umsatz und Stückzahl anzeigen. So kann ich die Zahlen mit WooCommerce Analytics vergleichen.
- Sonst keine globalen Einstellungen, alles andere läuft pro Element im UX Builder.

### 8. Technik

- Plugin-Slug: `hb-top-listen`, PHP 8.x, WordPress-Coding-Standards, deutsche Texte (Schweizer Schreibweise, «ss» statt «ß»).
- Sauber aufgebaut, aber schlank: wenige Dateien und kein Framework.
- Wird Flatsome deaktiviert, darf die Seite nicht abstürzen. Die Elemente geben dann einfach nichts aus.

## Tests / Abnahme

- Mit Testbestellungen verifizieren: normale Bestellung, Bestellung mit Gutschein, Teilrückerstattung, Vollrückerstattung, stornierte Bestellung, Variantenprodukt, Produkt in 2 Kategorien, Ladenverkauf.
- Die Zahlen in der Kontroll-Tabelle müssen mit WooCommerce Analytics (Produkte/Kategorien, gleicher Zeitraum) übereinstimmen. Abweichungen erklären.
- Doppelte-Produkte-Logik prüfen: Ein Produkt, das in beiden Listen steht, erscheint nur einmal, und es werden trotzdem insgesamt 8 Produkte angezeigt.
- Auf der Testseite die zwei bestehenden Bereiche durch die neuen Elemente ersetzen (erst nach meinem OK). Das Aussehen muss identisch bleiben.
