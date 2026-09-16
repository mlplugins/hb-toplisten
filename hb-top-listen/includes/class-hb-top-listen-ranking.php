<?php
/**
 * Berechnungs-Engine: Ranglisten für Produkte und Kategorien.
 *
 * Datenquelle sind ausschliesslich die WooCommerce-Analytics-Tabellen
 * wc_order_product_lookup (Positionen) und wc_order_stats (Status/Datum).
 * Refunds stehen dort als negative Zeilen unter eigener Refund-ID mit eigener
 * stats-Zeile; das einfache SUM() nettet Teil- und Vollrückerstattungen daher
 * korrekt (Vollrückerstattung = 0) – es wird nichts zusätzlich abgezogen.
 *
 * @package HB_Top_Listen
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ranglisten-Berechnung.
 */
class HB_Top_Listen_Ranking {

	/**
	 * Zusätzliche Kandidaten je Liste, damit beim Rendern (Lager/Sichtbarkeit
	 * geändert, Duplikate) Produkte nachrücken können.
	 */
	const CANDIDATE_BUFFER = 20;

	/**
	 * Obergrenze für Anzahl-Einstellungen.
	 */
	const MAX_COUNT = 48;

	/**
	 * Verfügbare Zeiträume.
	 *
	 * @return array<string,string>
	 */
	public static function period_labels(): array {
		return array(
			'last_30_days' => __( 'Letzte 30 Tage', 'hb-top-listen' ),
			'last_month'   => __( 'Letzter Kalendermonat', 'hb-top-listen' ),
			'year_to_date' => __( 'Jahr bisher', 'hb-top-listen' ),
			'custom'       => __( 'Manuell (von/bis)', 'hb-top-listen' ),
		);
	}

	/**
	 * Rechnet einen Zeitraum in lokale Datumsgrenzen (Zeitzone der Website) um.
	 *
	 * «Letzte 30 Tage» umfasst heute und die 29 Tage davor.
	 *
	 * @param string $period    Zeitraum-Schlüssel.
	 * @param string $date_from Von-Datum (JJJJ-MM-TT), nur bei «custom».
	 * @param string $date_to   Bis-Datum (JJJJ-MM-TT), nur bei «custom»; leer = heute.
	 * @return array{from:string,to:string}|null Null bei ungültigem Zeitraum.
	 */
	public static function resolve_period( string $period, string $date_from = '', string $date_to = '' ): ?array {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );

		switch ( $period ) {
			case 'last_month':
				$from = $today->modify( 'first day of last month' );
				$to   = $today->modify( 'last day of last month' );
				break;
			case 'year_to_date':
				$from = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
				$to   = $today;
				break;
			case 'custom':
				$from = self::parse_date( $date_from );
				$to   = '' === $date_to ? $today : self::parse_date( $date_to );
				if ( ! $from || ! $to || $from > $to ) {
					return null;
				}
				break;
			case 'last_30_days':
			default:
				$from = $today->modify( '-29 days' );
				$to   = $today;
				break;
		}

		return array(
			'from' => $from->format( 'Y-m-d' ) . ' 00:00:00',
			'to'   => $to->format( 'Y-m-d' ) . ' 23:59:59',
		);
	}

	/**
	 * Parst ein Datum im Format JJJJ-MM-TT in der Zeitzone der Website.
	 *
	 * @param string $date Datum.
	 * @return DateTimeImmutable|null
	 */
	private static function parse_date( string $date ): ?DateTimeImmutable {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', trim( $date ), wp_timezone() );
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== trim( $date ) ) {
			return null;
		}
		return $parsed;
	}

	/**
	 * Bestellstatus, die nicht zählen – gespiegelt von WooCommerce Analytics
	 * (Option «woocommerce_excluded_report_order_statuses» plus Entwürfe/Papierkorb).
	 *
	 * @return string[] Status-Slugs wie in wc_order_stats (mit «wc-»-Präfix).
	 */
	public static function excluded_statuses(): array {
		$excluded = get_option( 'woocommerce_excluded_report_order_statuses', array( 'pending', 'failed', 'cancelled' ) );
		if ( ! is_array( $excluded ) ) {
			$excluded = array( 'pending', 'failed', 'cancelled' );
		}
		$excluded = array_merge( array( 'auto-draft', 'trash' ), $excluded );
		/** Derselbe Filter, den WooCommerce Analytics verwendet. */
		$excluded = (array) apply_filters( 'woocommerce_analytics_excluded_order_statuses', $excluded );
		$excluded = array_merge( $excluded, array( 'checkout-draft' ) );

		$slugs = array();
		foreach ( $excluded as $status ) {
			$status = trim( (string) $status );
			if ( '' === $status ) {
				continue;
			}
			$status  = preg_replace( '/^wc-/', '', $status );
			$slugs[] = 'wc-' . $status;
			$slugs[] = $status;
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Ergänzt Kategorie-IDs um alle Unterkategorien.
	 *
	 * @param int[] $term_ids Kategorie-IDs.
	 * @return int[]
	 */
	public static function expand_categories( array $term_ids ): array {
		$all = array();
		foreach ( $term_ids as $term_id ) {
			$term_id = absint( $term_id );
			if ( ! $term_id ) {
				continue;
			}
			$all[]    = $term_id;
			$children = get_term_children( $term_id, 'product_cat' );
			if ( is_array( $children ) ) {
				$all = array_merge( $all, array_map( 'intval', $children ) );
			}
		}
		return array_values( array_unique( $all ) );
	}

	/**
	 * Wandelt eine Komma-Liste bzw. ein Array in sortierte, eindeutige IDs um.
	 *
	 * @param mixed $value Eingabe.
	 * @return int[]
	 */
	public static function parse_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			$value = explode( ',', (string) $value );
		}
		$ids = array_filter( array_map( 'absint', $value ) );
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Wertet einen Checkbox-Wert aus dem UX Builder aus.
	 *
	 * @param mixed $value Wert.
	 * @return bool
	 */
	public static function to_bool( $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), array( 'true', '1', 'yes', 'on' ), true );
	}

	/**
	 * Normalisiert die gemeinsamen Einstellungen (Zeitraum, Filter, Auffüllen).
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return array
	 */
	private static function normalize_common( array $atts ): array {
		$period = isset( $atts['period'] ) ? (string) $atts['period'] : 'last_30_days';
		if ( ! array_key_exists( $period, self::period_labels() ) ) {
			$period = 'last_30_days';
		}

		return array(
			'period'    => $period,
			'date_from' => 'custom' === $period ? sanitize_text_field( (string) ( $atts['date_from'] ?? '' ) ) : '',
			'date_to'   => 'custom' === $period ? sanitize_text_field( (string) ( $atts['date_to'] ?? '' ) ) : '',
			'cat_mode'  => ( isset( $atts['cat_mode'] ) && 'include' === $atts['cat_mode'] ) ? 'include' : 'exclude',
			'cats'      => self::parse_ids( $atts['cats'] ?? '' ),
			'fallback'  => self::to_bool( $atts['fallback'] ?? 'true' ),
		);
	}

	/**
	 * Normalisierte Einstellungen für «HB Top Produkte».
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return array
	 */
	public static function normalize_product_settings( array $atts ): array {
		return array_merge(
			array(
				'type'          => 'products',
				'count_revenue' => max( 0, min( self::MAX_COUNT, (int) ( $atts['count_revenue'] ?? 4 ) ) ),
				'count_qty'     => max( 0, min( self::MAX_COUNT, (int) ( $atts['count_qty'] ?? 4 ) ) ),
				'in_stock'      => self::to_bool( $atts['in_stock'] ?? 'false' ),
			),
			self::normalize_common( $atts )
		);
	}

	/**
	 * Normalisierte Einstellungen für «HB Top Kategorien».
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return array
	 */
	public static function normalize_category_settings( array $atts ): array {
		return array_merge(
			array(
				'type'        => 'categories',
				'count'       => max( 0, min( self::MAX_COUNT, (int) ( $atts['count'] ?? 8 ) ) ),
				'criterion'   => ( isset( $atts['criterion'] ) && 'qty' === $atts['criterion'] ) ? 'qty' : 'revenue',
				'exclude_top' => self::to_bool( $atts['exclude_top'] ?? 'true' ),
			),
			self::normalize_common( $atts )
		);
	}

	/**
	 * Gemeinsame WHERE-Bedingungen für Status und Datum.
	 *
	 * @param array $args   Abfrage-Argumente (from/to).
	 * @param array $params Platzhalter-Werte (per Referenz ergänzt).
	 * @return string[]
	 */
	private static function base_where( array $args, array &$params ): array {
		$statuses = self::excluded_statuses();
		$where    = array( 's.status NOT IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')' );
		$params   = array_merge( $params, $statuses );

		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'l.date_created >= %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'l.date_created <= %s';
			$params[] = $args['to'];
		}
		return $where;
	}

	/**
	 * ORDER BY mit Gleichstand-Regel: anderes Kriterium, dann Name.
	 *
	 * @param string $order «revenue» oder «qty».
	 * @param string $id    ID-Spalte als letzter, stabiler Tie-Breaker.
	 * @return string
	 */
	private static function order_by( string $order, string $id ): string {
		return 'qty' === $order
			? "ORDER BY qty DESC, revenue DESC, name ASC, {$id} ASC"
			: "ORDER BY revenue DESC, qty DESC, name ASC, {$id} ASC";
	}

	/**
	 * Umsatz und Stückzahl je Produkt (Varianten zusammengefasst).
	 *
	 * @param array $args {
	 *     @type string|null $from          Lokales Startdatum «Y-m-d H:i:s», null = seit jeher.
	 *     @type string|null $to            Lokales Enddatum, null = offen.
	 *     @type string      $order         «revenue» oder «qty».
	 *     @type string      $cat_mode      «exclude» oder «include».
	 *     @type int[]       $cat_ids       Kategorie-IDs (bereits inkl. Unterkategorien).
	 *     @type bool        $visible_only  Nur veröffentlichte, im Katalog sichtbare Produkte.
	 *     @type bool        $in_stock      Nur lagernde Produkte.
	 *     @type bool        $positive_only Produkte ohne Netto-Verkauf weglassen.
	 *     @type int         $limit         Maximale Anzahl, 0 = alle.
	 * }
	 * @return array[] Zeilen mit product_id, name, status, revenue, qty.
	 */
	public static function query_products( array $args ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'from'          => null,
				'to'            => null,
				'order'         => 'revenue',
				'cat_mode'      => 'exclude',
				'cat_ids'       => array(),
				'visible_only'  => false,
				'in_stock'      => false,
				'positive_only' => false,
				'limit'         => 0,
			)
		);

		$params = array();
		$where  = self::base_where( $args, $params );
		$where[] = 'l.product_id > 0';

		if ( $args['visible_only'] ) {
			$where[] = "p.post_type = 'product' AND p.post_status = 'publish'";
		}

		// Katalog-Sichtbarkeit und Lagerstatus pflegt WooCommerce als Terme der Taxonomie product_visibility.
		$hidden_slugs = array();
		if ( $args['visible_only'] ) {
			$hidden_slugs[] = 'exclude-from-catalog';
		}
		if ( $args['in_stock'] ) {
			$hidden_slugs[] = 'outofstock';
		}
		if ( $hidden_slugs && function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			$term_ids = array_filter( array_intersect_key( wc_get_product_visibility_term_ids(), array_flip( $hidden_slugs ) ) );
			if ( $term_ids ) {
				$where[] = "l.product_id NOT IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (" . implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) . ') )';
				$params  = array_merge( $params, array_values( array_map( 'intval', $term_ids ) ) );
			}
		}

		$cat_ids = array_filter( array_map( 'intval', (array) $args['cat_ids'] ) );
		if ( $cat_ids ) {
			$operator = 'include' === $args['cat_mode'] ? 'IN' : 'NOT IN';
			$where[]  = "l.product_id {$operator} ( SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN (" . implode( ',', array_fill( 0, count( $cat_ids ), '%d' ) ) . ') )';
			$params   = array_merge( $params, array_values( $cat_ids ) );
		}

		$having = $args['positive_only'] ? 'HAVING revenue > 0 OR qty > 0' : '';
		$limit  = '';
		if ( (int) $args['limit'] > 0 ) {
			$limit    = 'LIMIT %d';
			$params[] = (int) $args['limit'];
		}

		$sql = "SELECT l.product_id,
				MAX(p.post_title) AS name,
				MAX(p.post_status) AS status,
				ROUND(SUM(l.product_net_revenue), 2) AS revenue,
				SUM(l.product_qty) AS qty
			FROM {$wpdb->prefix}wc_order_product_lookup l
			INNER JOIN {$wpdb->prefix}wc_order_stats s ON s.order_id = l.order_id
			LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id
			WHERE " . implode( ' AND ', $where ) . "
			GROUP BY l.product_id
			{$having} " . self::order_by( $args['order'], 'l.product_id' ) . " {$limit}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Platzhalter werden oben dynamisch erzeugt.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map(
			static function ( $row ) {
				return array(
					'product_id' => (int) $row['product_id'],
					'name'       => (string) $row['name'],
					'status'     => (string) $row['status'],
					'revenue'    => (float) $row['revenue'],
					'qty'        => (int) $row['qty'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Umsatz und Stückzahl je direkt zugeordneter Produktkategorie.
	 *
	 * Ein Produkt in mehreren Kategorien zählt voll für jede davon; keine
	 * Aufsummierung in übergeordnete Kategorien. «Unkategorisiert» fällt immer weg.
	 *
	 * @param array $args {
	 *     @type string|null $from          Lokales Startdatum, null = seit jeher.
	 *     @type string|null $to            Lokales Enddatum, null = offen.
	 *     @type string      $order         «revenue» oder «qty».
	 *     @type string      $cat_mode      «exclude» oder «include».
	 *     @type int[]       $cat_ids       Kategorie-IDs (bereits inkl. Unterkategorien).
	 *     @type bool        $exclude_top   Kategorien der obersten Ebene weglassen.
	 *     @type bool        $skip_empty    Leere Kategorien (ohne veröffentlichte Produkte) weglassen.
	 *     @type bool        $positive_only Kategorien ohne Netto-Verkauf weglassen.
	 *     @type int         $limit         Maximale Anzahl, 0 = alle.
	 * }
	 * @return array[] Zeilen mit term_id, name, parent, product_count, revenue, qty.
	 */
	public static function query_categories( array $args ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'from'          => null,
				'to'            => null,
				'order'         => 'revenue',
				'cat_mode'      => 'exclude',
				'cat_ids'       => array(),
				'exclude_top'   => false,
				'skip_empty'    => false,
				'positive_only' => false,
				'limit'         => 0,
			)
		);

		$params = array();
		$where  = self::base_where( $args, $params );

		$uncategorized = (int) get_option( 'default_product_cat', 0 );
		if ( $uncategorized ) {
			$where[]  = 'tt.term_id <> %d';
			$params[] = $uncategorized;
		}
		if ( $args['exclude_top'] ) {
			$where[] = 'tt.parent <> 0';
		}
		if ( $args['skip_empty'] ) {
			$where[] = 'tt.count > 0';
		}

		$cat_ids = array_filter( array_map( 'intval', (array) $args['cat_ids'] ) );
		if ( $cat_ids ) {
			$operator = 'include' === $args['cat_mode'] ? 'IN' : 'NOT IN';
			$where[]  = "tt.term_id {$operator} (" . implode( ',', array_fill( 0, count( $cat_ids ), '%d' ) ) . ')';
			$params   = array_merge( $params, array_values( $cat_ids ) );
		}

		$having = $args['positive_only'] ? 'HAVING revenue > 0 OR qty > 0' : '';
		$limit  = '';
		if ( (int) $args['limit'] > 0 ) {
			$limit    = 'LIMIT %d';
			$params[] = (int) $args['limit'];
		}

		$sql = "SELECT tt.term_id,
				MAX(t.name) AS name,
				MAX(tt.parent) AS parent,
				MAX(tt.count) AS product_count,
				ROUND(SUM(l.product_net_revenue), 2) AS revenue,
				SUM(l.product_qty) AS qty
			FROM {$wpdb->prefix}wc_order_product_lookup l
			INNER JOIN {$wpdb->prefix}wc_order_stats s ON s.order_id = l.order_id
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = l.product_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE " . implode( ' AND ', $where ) . "
			GROUP BY tt.term_id
			{$having} " . self::order_by( $args['order'], 'tt.term_id' ) . " {$limit}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Platzhalter werden oben dynamisch erzeugt.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map(
			static function ( $row ) {
				return array(
					'term_id'       => (int) $row['term_id'],
					'name'          => (string) $row['name'],
					'parent'        => (int) $row['parent'],
					'product_count' => (int) $row['product_count'],
					'revenue'       => (float) $row['revenue'],
					'qty'           => (int) $row['qty'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Kandidaten-Listen für «HB Top Produkte» (teuer, wird gecacht).
	 *
	 * Je Kriterium: erst die Produkte des Zeitraums, danach (falls aktiviert)
	 * die Bestseller seit jeher. Duplikate zwischen den Listen werden erst beim
	 * Rendern entfernt, siehe pick_products().
	 *
	 * @param array $settings Normalisierte Produkt-Einstellungen.
	 * @return array{revenue:int[],qty:int[]}
	 */
	public static function product_candidates( array $settings ): array {
		$result = array(
			'revenue' => array(),
			'qty'     => array(),
		);
		$range  = self::resolve_period( $settings['period'], $settings['date_from'], $settings['date_to'] );
		if ( null === $range ) {
			return $result;
		}

		$cap  = $settings['count_revenue'] + $settings['count_qty'] + self::CANDIDATE_BUFFER;
		$base = array(
			'cat_mode'      => $settings['cat_mode'],
			'cat_ids'       => self::expand_categories( $settings['cats'] ),
			'visible_only'  => true,
			'in_stock'      => self::in_stock_required( $settings ),
			'positive_only' => true,
			'limit'         => $cap,
		);

		foreach ( array( 'revenue' => $settings['count_revenue'], 'qty' => $settings['count_qty'] ) as $order => $count ) {
			if ( $count < 1 ) {
				continue;
			}
			$ids = wp_list_pluck( self::query_products( array_merge( $base, $range, array( 'order' => $order ) ) ), 'product_id' );

			if ( $settings['fallback'] && count( $ids ) < $cap ) {
				$all_time = wp_list_pluck( self::query_products( array_merge( $base, array( 'order' => $order ) ) ), 'product_id' );
				$ids      = array_slice( array_values( array_unique( array_merge( $ids, $all_time ) ) ), 0, $cap );
			}
			$result[ $order ] = array_map( 'intval', $ids );
		}

		return $result;
	}

	/**
	 * Wählt die anzuzeigenden Produkte: erst N nach Umsatz, dann M nach Stückzahl.
	 *
	 * Produkte, die bereits gewählt, nicht mehr sichtbar oder (falls verlangt)
	 * nicht lagernd sind, werden übersprungen – das nächste rückt nach.
	 *
	 * @param array $candidates Ergebnis von product_candidates().
	 * @param array $settings   Normalisierte Produkt-Einstellungen.
	 * @return array<int,string> Produkt-ID => Quelle («revenue» oder «qty»), in Anzeige-Reihenfolge.
	 */
	public static function pick_products( array $candidates, array $settings ): array {
		$all_ids = array_unique( array_merge( $candidates['revenue'] ?? array(), $candidates['qty'] ?? array() ) );
		if ( ! $all_ids ) {
			return array();
		}
		_prime_post_caches( $all_ids, false, true );

		$in_stock = self::in_stock_required( $settings );
		$picked   = array();
		foreach ( array( 'revenue' => $settings['count_revenue'], 'qty' => $settings['count_qty'] ) as $list => $count ) {
			$taken = 0;
			foreach ( $candidates[ $list ] ?? array() as $product_id ) {
				if ( $taken >= $count ) {
					break;
				}
				if ( isset( $picked[ $product_id ] ) || ! self::is_displayable( (int) $product_id, $in_stock ) ) {
					continue;
				}
				$picked[ $product_id ] = $list;
				++$taken;
			}
		}
		return $picked;
	}

	/**
	 * Kategorie-IDs für «HB Top Kategorien» in Ranglisten-Reihenfolge (wird gecacht).
	 *
	 * @param array $settings Normalisierte Kategorie-Einstellungen.
	 * @return int[]
	 */
	public static function category_ids( array $settings ): array {
		$range = self::resolve_period( $settings['period'], $settings['date_from'], $settings['date_to'] );
		if ( null === $range || $settings['count'] < 1 ) {
			return array();
		}

		$base = array(
			'order'         => $settings['criterion'],
			'cat_mode'      => $settings['cat_mode'],
			'cat_ids'       => self::expand_categories( $settings['cats'] ),
			'exclude_top'   => $settings['exclude_top'],
			'skip_empty'    => true,
			'positive_only' => true,
			'limit'         => $settings['count'],
		);

		$ids = wp_list_pluck( self::query_categories( array_merge( $base, $range ) ), 'term_id' );
		if ( $settings['fallback'] && count( $ids ) < $settings['count'] ) {
			$all_time = wp_list_pluck( self::query_categories( $base ), 'term_id' );
			$ids      = array_slice( array_values( array_unique( array_merge( $ids, $all_time ) ) ), 0, $settings['count'] );
		}
		return array_map( 'intval', $ids );
	}

	/**
	 * Muss ein Produkt lagernd sein? (Element-Option oder WooCommerce-Einstellung
	 * «Nicht vorrätige Artikel im Katalog ausblenden».)
	 *
	 * @param array $settings Normalisierte Produkt-Einstellungen.
	 * @return bool
	 */
	private static function in_stock_required( array $settings ): bool {
		return ! empty( $settings['in_stock'] ) || 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' );
	}

	/**
	 * Aktuelle Prüfung beim Rendern: existiert, veröffentlicht, im Katalog sichtbar, ggf. lagernd.
	 *
	 * @param int  $product_id Produkt-ID.
	 * @param bool $in_stock   Lagernd verlangt.
	 * @return bool
	 */
	private static function is_displayable( int $product_id, bool $in_stock ): bool {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_visible() ) {
			return false;
		}
		return ! $in_stock || $product->is_in_stock();
	}
}
