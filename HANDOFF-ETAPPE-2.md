# Übergabe — Etappe 2: Plugin «HB Top-Listen» bauen

Kopiere diesen Text als ersten Prompt in eine neue Session, um bei Etappe 2 weiterzumachen.

---

**Kontext:** Wir bauen das WordPress-Plugin «HB Top-Listen» (Slug `hb-top-listen`) laut `prompt-hb-top-listen-plugin.md`. Etappe 1 (Analyse) ist abgeschlossen, die Befunde stehen im Projekt-Memory (`hb-toplisten-etappen.md`) — bitte zuerst lesen. Jetzt ist **Etappe 2 (Plugin bauen)** dran.

**Setup, das schon steht:**
- Git-Repo verbunden mit `github.com/mlplugins/hb-toplisten` (remote `origin`, Branch `main`).
- Plugin-Ordner lebt im Repo unter `hb-top-listen/` und ist per **Junction** in die Local-Seite gelinkt: `C:/Users/info/Local Sites/hempbasement-test/app/public/wp-content/plugins/hb-top-listen`. Also: im Repo `hb-top-listen/` editieren = WordPress sieht es sofort. Aktuell nur ein Stub `hb-top-listen.php` (Plugin-Header, Version 0.1.0).
- Lokale Seite: http://hempbasement.test/ (Local by Flywheel), **Startseite = Seite ID 2**. Entwickeln/testen NUR lokal.
- DB lesend prüfen mit Locals Client: `C:/Users/info/AppData/Roaming/Local/lightning-services/mariadb-10.11.18+0/bin/win32/bin/mariadb.exe -h 127.0.0.1 -P 10004 -u root -proot local`

**Verifizierte Datenlogik aus Etappe 1 (so umsetzen):**
- HPOS ist aktiv → Kompatibilität deklarieren (`before_woocommerce_init`, `FeaturesUtil::declare_compatibility('custom_order_tables', ...)`).
- Ranking aus `wp_wc_order_product_lookup l JOIN wp_wc_order_stats s ON s.order_id=l.order_id`, `GROUP BY l.product_id` (fasst Varianten zusammen), `SUM(product_qty)` und `SUM(product_net_revenue)`. Refunds sind negative Zeilen unter eigener Refund-ID mit eigener stats-Zeile → das blosse SUM nettet sie korrekt (Voll-Refund = 0), **nicht** zusätzlich abziehen.
- Status-Filter: die Status aus Option `woocommerce_excluded_report_order_statuses` ausschliessen (Default `pending, cancelled, failed`) — spiegelt WooCommerce Analytics. Slugs in stats sind mit `wc-`-Präfix.
- Datumsgrenzen in Europe/Zurich; `$wpdb->prepare` überall.
- Kategorien: Produkt→`wp_term_relationships`(object_id=product-ID)→`wp_term_taxonomy`(taxonomy=product_cat). Voller Wert je direkt zugeordneter Kategorie, keine Rollup-Summierung. «Unkategorisiert» + leere raus. Filter-Kategorien auf Nachkommen expandieren. Checkbox «6 Hauptkategorien (oberste Ebene) ausschliessen».
- Ausgabe über `do_shortcode('[ux_products ids="..." ...]')` bzw. `[ux_product_categories ids="..."]` — Flatsome 3.20.8 behält die `ids`-Reihenfolge (`post__in` bzw. `orderby=include`) und überspringt unsichtbare/gelöschte Produkte selbst.

**Zu bauen in Etappe 2:**
1. Grundgerüst/Autoload (wenige Dateien, kein Framework), HPOS-Deklaration, Aktivierung.
2. Berechnungs-Engine: Produkte + Kategorien. Zeiträume: letzte 30 Tage (rollierend), letzter Kalendermonat, Jahr bisher, manuell von/bis. Lager-Filter (nur lagernd), Kategorien-Filter (ausschliessen / nur diese, inkl. Unterkategorien). Auffüllen mit Bestsellern seit jeher (abschaltbar). Dedup: erst Umsatz-Liste (N), dann Stückzahl-Liste (M), Duplikate raus, nachrücken, sodass insgesamt N+M eindeutige Produkte.
3. Randfälle: Gleichstand → anderes Kriterium, dann Name. Null Treffer → gar keine Ausgabe, kein PHP-Fehler. Flatsome deaktiviert → keine Ausgabe, kein Absturz.
4. Caching: Transient je Einstellungs-Hash; Neuberechnung ~alle 6 h (Action Scheduler/WP-Cron) + bei Datumswechsel. UX-Builder-Vorschau muss funktionieren.
5. Zwei UX-Builder-Elemente via `add_ux_builder_shortcode` («HB Top Produkte», «HB Top Kategorien») mit allen Einstellungen aus dem Auftrag; Layout-Optionen an die Flatsome-Shortcodes durchreichen. Vorlage für das Options-Schema: `wp-content/themes/flatsome/inc/builder/shortcodes/ux_products.php`.
6. Admin-Kontrolltabelle unter WooCommerce: Zeitraum + Filter wählen → Rangliste (Umsatz + Stückzahl) zum Abgleich mit Analytics; Button «Cache neu berechnen». Sonst keine globalen Einstellungen.

**Technik:** PHP 8.x, WP-Coding-Standards, deutsche Texte in Schweizer Schreibweise («ss» statt «ß»). Schlank halten.

**Am Ende von Etappe 2:** über die Kontrolltabelle selbst die Zahlen prüfen und committen. Danach folgt Etappe 3 (Testbestellungen anlegen, Analytics-Abgleich, Rollout der Startseite — erst nach OK des Nutzers). NICHT ungefragt die Startseite ändern.
