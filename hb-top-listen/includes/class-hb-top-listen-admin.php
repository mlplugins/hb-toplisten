<?php
/**
 * Admin-Seite unter WooCommerce: Kontrolltabelle und «Cache neu berechnen».
 *
 * @package HB_Top_Listen
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kontrolltabelle zum Abgleich mit WooCommerce Analytics.
 */
class HB_Top_Listen_Admin {

	const PAGE_SLUG    = 'hb-top-listen';
	const FLUSH_ACTION = 'hb_top_listen_flush';

	/**
	 * Hooks registrieren.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 );
		add_action( 'admin_post_' . self::FLUSH_ACTION, array( __CLASS__, 'handle_flush' ) );
	}

	/**
	 * Menüpunkt unter WooCommerce.
	 */
	public static function add_menu(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__( 'HB Top-Listen', 'hb-top-listen' ),
			__( 'HB Top-Listen', 'hb-top-listen' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Button «Cache neu berechnen».
	 */
	public static function handle_flush(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'hb-top-listen' ) );
		}
		check_admin_referer( self::FLUSH_ACTION );

		HB_Top_Listen_Cache::flush();

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		wp_safe_redirect( add_query_arg( 'hb_flushed', HB_Top_Listen_Cache::registry_count(), $redirect ) );
		exit;
	}

	/**
	 * Filter-Eingaben aus der URL lesen.
	 *
	 * @return array
	 */
	private static function read_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nur lesende Filter.
		$get = wp_unslash( $_GET );
		// phpcs:enable

		$view = ( isset( $get['view'] ) && 'categories' === $get['view'] ) ? 'categories' : 'products';
		$atts = array(
			'period'        => $get['period'] ?? 'last_30_days',
			'date_from'     => $get['date_from'] ?? '',
			'date_to'       => $get['date_to'] ?? '',
			'cat_mode'      => $get['cat_mode'] ?? 'exclude',
			'cats'          => isset( $get['cats'] ) ? (array) $get['cats'] : array(),
			'fallback'      => isset( $get['submitted'] ) ? ( isset( $get['fallback'] ) ? 'true' : 'false' ) : 'true',
			'in_stock'      => isset( $get['in_stock'] ) ? 'true' : 'false',
			'exclude_top'   => isset( $get['submitted'] ) ? ( isset( $get['exclude_top'] ) ? 'true' : 'false' ) : 'true',
			'count_revenue' => $get['count_revenue'] ?? 4,
			'count_qty'     => $get['count_qty'] ?? 4,
			'count'         => $get['count'] ?? 8,
			'criterion'     => $get['order'] ?? 'revenue',
		);

		return array(
			'view'         => $view,
			'order'        => ( isset( $get['order'] ) && 'qty' === $get['order'] ) ? 'qty' : 'revenue',
			'visible_only' => isset( $get['visible_only'] ),
			'limit'        => max( 1, min( 1000, (int) ( $get['limit'] ?? 50 ) ) ),
			'settings'     => 'categories' === $view
				? HB_Top_Listen_Ranking::normalize_category_settings( $atts )
				: HB_Top_Listen_Ranking::normalize_product_settings( $atts ),
		);
	}

	/**
	 * Seite ausgeben.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$request  = self::read_request();
		$settings = $request['settings'];
		$range    = HB_Top_Listen_Ranking::resolve_period( $settings['period'], $settings['date_from'], $settings['date_to'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HB Top-Listen', 'hb-top-listen' ); ?></h1>

			<?php self::render_cache_box(); ?>

			<h2><?php esc_html_e( 'Kontrolltabelle', 'hb-top-listen' ); ?></h2>
			<p><?php esc_html_e( 'Rangliste direkt aus den Analytics-Tabellen, ohne Cache. Zum Abgleich mit WooCommerce › Analytics › Produkte bzw. Kategorien (gleicher Zeitraum).', 'hb-top-listen' ); ?></p>
			<p><?php esc_html_e( 'Zwei bekannte Abweichungen gegenüber Analytics: (1) Die oben genannten unbezahlten Status zählt Analytics mit, dieses Plugin nicht. (2) Die Kategorie «Unkategorisiert» lässt dieses Plugin immer weg. Sonst stimmen die Zahlen Zeile für Zeile überein – auch Analytics rechnet jede Kategorie einzeln und summiert Unterkategorien nicht in die übergeordnete Kategorie auf.', 'hb-top-listen' ); ?></p>

			<?php self::render_filter_form( $request ); ?>

			<?php
			if ( null === $range ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Ungültiger Zeitraum. Bitte Von/Bis im Format JJJJ-MM-TT angeben (Von ≤ Bis).', 'hb-top-listen' ) . '</p></div></div>';
				return;
			}

			printf(
				'<p><strong>%1$s</strong> %2$s – %3$s (%4$s) · <strong>%5$s</strong> %6$s</p>',
				esc_html__( 'Zeitraum:', 'hb-top-listen' ),
				esc_html( $range['from'] ),
				esc_html( $range['to'] ),
				esc_html( wp_timezone_string() ),
				esc_html__( 'Nicht gezählte Status:', 'hb-top-listen' ),
				esc_html( implode( ', ', array_filter( HB_Top_Listen_Ranking::excluded_statuses(), static fn( $s ) => str_starts_with( $s, 'wc-' ) ) ) )
			);

			if ( 'categories' === $request['view'] ) {
				self::render_category_results( $request, $range );
			} else {
				self::render_product_results( $request, $range );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Box mit Cache-Status und Button.
	 */
	private static function render_cache_box(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['hb_flushed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: Anzahl Einstellungs-Kombinationen. */
					__( 'Cache geleert und %d genutzte Element-Einstellungen neu berechnet.', 'hb-top-listen' ),
					absint( $_GET['hb_flushed'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				)
			) . '</p></div>';
		}

		$next = wp_next_scheduled( HB_Top_Listen_Cache::CRON_HOOK );
		?>
		<div class="card" style="max-width:none">
			<h2 class="title"><?php esc_html_e( 'Cache', 'hb-top-listen' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: Anzahl, 2: Datum/Zeit. */
					esc_html__( 'Gemerkte Element-Einstellungen: %1$d · Nächste automatische Neuberechnung: %2$s (zusätzlich täglich kurz nach Mitternacht).', 'hb-top-listen' ),
					(int) HB_Top_Listen_Cache::registry_count(),
					$next ? esc_html( wp_date( 'd.m.Y H:i', $next ) ) : esc_html__( 'nicht geplant', 'hb-top-listen' )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::FLUSH_ACTION ); ?>">
				<?php wp_nonce_field( self::FLUSH_ACTION ); ?>
				<?php submit_button( __( 'Cache neu berechnen', 'hb-top-listen' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Filter-Formular.
	 *
	 * @param array $request Gelesene Filter.
	 */
	private static function render_filter_form( array $request ): void {
		$s          = $request['settings'];
		$is_product = 'products' === $request['view'];
		?>
		<form method="get" style="margin:1em 0">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="submitted" value="1">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="hb-view"><?php esc_html_e( 'Ansicht', 'hb-top-listen' ); ?></label></th>
					<td>
						<select id="hb-view" name="view">
							<option value="products" <?php selected( $request['view'], 'products' ); ?>><?php esc_html_e( 'Produkte', 'hb-top-listen' ); ?></option>
							<option value="categories" <?php selected( $request['view'], 'categories' ); ?>><?php esc_html_e( 'Kategorien', 'hb-top-listen' ); ?></option>
						</select>
						<select name="order">
							<option value="revenue" <?php selected( $request['order'], 'revenue' ); ?>><?php esc_html_e( 'sortiert nach Umsatz', 'hb-top-listen' ); ?></option>
							<option value="qty" <?php selected( $request['order'], 'qty' ); ?>><?php esc_html_e( 'sortiert nach Stückzahl', 'hb-top-listen' ); ?></option>
						</select>
						<label><?php esc_html_e( 'Zeilen:', 'hb-top-listen' ); ?> <input type="number" name="limit" min="1" max="1000" value="<?php echo esc_attr( $request['limit'] ); ?>" style="width:6em"></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hb-period"><?php esc_html_e( 'Zeitraum', 'hb-top-listen' ); ?></label></th>
					<td>
						<select id="hb-period" name="period">
							<?php foreach ( HB_Top_Listen_Ranking::period_labels() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['period'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<label><?php esc_html_e( 'Von', 'hb-top-listen' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $s['date_from'] ); ?>"></label>
						<label><?php esc_html_e( 'Bis', 'hb-top-listen' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $s['date_to'] ); ?>"></label>
						<p class="description"><?php esc_html_e( 'Von/Bis gelten nur bei «Manuell».', 'hb-top-listen' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hb-cats"><?php esc_html_e( 'Kategorien-Filter', 'hb-top-listen' ); ?></label></th>
					<td>
						<select name="cat_mode">
							<option value="exclude" <?php selected( $s['cat_mode'], 'exclude' ); ?>><?php esc_html_e( 'Diese Kategorien ausschliessen', 'hb-top-listen' ); ?></option>
							<option value="include" <?php selected( $s['cat_mode'], 'include' ); ?>><?php esc_html_e( 'Nur diese Kategorien', 'hb-top-listen' ); ?></option>
						</select><br>
						<select id="hb-cats" name="cats[]" multiple size="8" style="min-width:320px;margin-top:4px">
							<?php self::render_category_options( $s['cats'] ); ?>
						</select>
						<p class="description"><?php esc_html_e( 'Mehrfachauswahl mit Strg/Cmd. Unterkategorien gelten mit.', 'hb-top-listen' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Optionen', 'hb-top-listen' ); ?></th>
					<td>
						<label><input type="checkbox" name="visible_only" value="1" <?php checked( $request['visible_only'] ); ?>> <?php echo esc_html( $is_product ? __( 'Tabelle: nur sichtbare Produkte', 'hb-top-listen' ) : __( 'Tabelle: leere Kategorien weglassen', 'hb-top-listen' ) ); ?></label><br>
						<?php if ( $is_product ) : ?>
							<label><input type="checkbox" name="in_stock" value="1" <?php checked( $s['in_stock'] ); ?>> <?php esc_html_e( 'Nur lagernde Produkte', 'hb-top-listen' ); ?></label><br>
						<?php else : ?>
							<label><input type="checkbox" name="exclude_top" value="1" <?php checked( $s['exclude_top'] ); ?>> <?php esc_html_e( 'Hauptkategorien (oberste Ebene) ausschliessen', 'hb-top-listen' ); ?></label><br>
						<?php endif; ?>
						<label><input type="checkbox" name="fallback" value="1" <?php checked( $s['fallback'] ); ?>> <?php esc_html_e( 'Element-Ergebnis: mit Bestsellern seit jeher auffüllen', 'hb-top-listen' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Element-Ergebnis', 'hb-top-listen' ); ?></th>
					<td>
						<?php if ( $is_product ) : ?>
							<label><?php esc_html_e( 'Anzahl nach Umsatz', 'hb-top-listen' ); ?> <input type="number" name="count_revenue" min="0" max="<?php echo esc_attr( HB_Top_Listen_Ranking::MAX_COUNT ); ?>" value="<?php echo esc_attr( $s['count_revenue'] ); ?>" style="width:5em"></label>
							<label><?php esc_html_e( 'Anzahl nach Stückzahl', 'hb-top-listen' ); ?> <input type="number" name="count_qty" min="0" max="<?php echo esc_attr( HB_Top_Listen_Ranking::MAX_COUNT ); ?>" value="<?php echo esc_attr( $s['count_qty'] ); ?>" style="width:5em"></label>
						<?php else : ?>
							<label><?php esc_html_e( 'Anzahl Kategorien', 'hb-top-listen' ); ?> <input type="number" name="count" min="1" max="<?php echo esc_attr( HB_Top_Listen_Ranking::MAX_COUNT ); ?>" value="<?php echo esc_attr( $s['count'] ); ?>" style="width:5em"></label>
							<span class="description"><?php esc_html_e( '(Kriterium = Sortierung oben)', 'hb-top-listen' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Anzeigen', 'hb-top-listen' ), 'primary', '', false ); ?>
		</form>
		<?php
	}

	/**
	 * Hierarchische <option>-Liste der Produktkategorien.
	 *
	 * @param int[] $selected Gewählte IDs.
	 * @param int   $parent   Eltern-ID.
	 * @param int   $depth    Tiefe.
	 */
	private static function render_category_options( array $selected, int $parent = 0, int $depth = 0 ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'parent'     => $parent,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			printf(
				'<option value="%1$d" %2$s>%3$s%4$s</option>',
				(int) $term->term_id,
				selected( in_array( (int) $term->term_id, $selected, true ), true, false ),
				esc_html( str_repeat( '— ', $depth ) ),
				esc_html( wp_specialchars_decode( $term->name, ENT_QUOTES ) )
			);
			self::render_category_options( $selected, (int) $term->term_id, $depth + 1 );
		}
	}

	/**
	 * Tabelle + Element-Ergebnis für Produkte.
	 *
	 * @param array $request Gelesene Filter.
	 * @param array $range   Datumsgrenzen.
	 */
	private static function render_product_results( array $request, array $range ): void {
		$s    = $request['settings'];
		$rows = HB_Top_Listen_Ranking::query_products(
			array_merge(
				$range,
				array(
					'order'        => $request['order'],
					'cat_mode'     => $s['cat_mode'],
					'cat_ids'      => HB_Top_Listen_Ranking::expand_categories( $s['cats'] ),
					'visible_only' => $request['visible_only'],
					'in_stock'     => $s['in_stock'],
				)
			)
		);

		self::render_totals( $rows );
		?>
		<table class="widefat striped" style="max-width:1100px">
			<thead>
				<tr>
					<th style="width:4em"><?php esc_html_e( 'Rang', 'hb-top-listen' ); ?></th>
					<th style="width:6em"><?php esc_html_e( 'ID', 'hb-top-listen' ); ?></th>
					<th><?php esc_html_e( 'Produkt', 'hb-top-listen' ); ?></th>
					<th style="text-align:right"><?php esc_html_e( 'Umsatz netto', 'hb-top-listen' ); ?></th>
					<th style="text-align:right"><?php esc_html_e( 'Stückzahl', 'hb-top-listen' ); ?></th>
					<th><?php esc_html_e( 'Hinweis', 'hb-top-listen' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Keine Verkäufe in diesem Zeitraum.', 'hb-top-listen' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( array_slice( $rows, 0, $request['limit'] ) as $i => $row ) : ?>
					<tr>
						<td><?php echo (int) $i + 1; ?></td>
						<td><?php echo (int) $row['product_id']; ?></td>
						<td>
							<?php if ( '' !== $row['name'] ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $row['product_id'] ) ); ?>"><?php echo esc_html( wp_specialchars_decode( $row['name'], ENT_QUOTES ) ); ?></a>
							<?php else : ?>
								<em><?php esc_html_e( '(gelöscht)', 'hb-top-listen' ); ?></em>
							<?php endif; ?>
						</td>
						<td style="text-align:right"><?php echo esc_html( self::money( $row['revenue'] ) ); ?></td>
						<td style="text-align:right"><?php echo esc_html( number_format_i18n( $row['qty'] ) ); ?></td>
						<td><?php echo esc_html( self::product_note( $row ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Element-Ergebnis «HB Top Produkte»', 'hb-top-listen' ); ?></h2>
		<?php
		if ( $s['count_revenue'] + $s['count_qty'] < 1 ) {
			echo '<p>' . esc_html__( 'Keine Anzahl gewählt – das Element gibt nichts aus.', 'hb-top-listen' ) . '</p>';
			return;
		}

		$candidates = HB_Top_Listen_Ranking::product_candidates( $s );
		$picked     = HB_Top_Listen_Ranking::pick_products( $candidates, $s );
		self::render_element_result(
			$picked,
			static function ( $id ) {
				$product = wc_get_product( $id );
				return $product ? $product->get_name() : '#' . $id;
			},
			wp_list_pluck( $rows, 'product_id' )
		);
	}

	/**
	 * Tabelle + Element-Ergebnis für Kategorien.
	 *
	 * @param array $request Gelesene Filter.
	 * @param array $range   Datumsgrenzen.
	 */
	private static function render_category_results( array $request, array $range ): void {
		$s    = $request['settings'];
		$rows = HB_Top_Listen_Ranking::query_categories(
			array_merge(
				$range,
				array(
					'order'       => $request['order'],
					'cat_mode'    => $s['cat_mode'],
					'cat_ids'     => HB_Top_Listen_Ranking::expand_categories( $s['cats'] ),
					'exclude_top' => $s['exclude_top'],
					'skip_empty'  => $request['visible_only'],
				)
			)
		);
		?>
		<table class="widefat striped" style="max-width:1100px">
			<thead>
				<tr>
					<th style="width:4em"><?php esc_html_e( 'Rang', 'hb-top-listen' ); ?></th>
					<th style="width:6em"><?php esc_html_e( 'ID', 'hb-top-listen' ); ?></th>
					<th><?php esc_html_e( 'Kategorie', 'hb-top-listen' ); ?></th>
					<th style="text-align:right"><?php esc_html_e( 'Umsatz netto', 'hb-top-listen' ); ?></th>
					<th style="text-align:right"><?php esc_html_e( 'Stückzahl', 'hb-top-listen' ); ?></th>
					<th><?php esc_html_e( 'Hinweis', 'hb-top-listen' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Keine Verkäufe in diesem Zeitraum.', 'hb-top-listen' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( array_slice( $rows, 0, $request['limit'] ) as $i => $row ) : ?>
					<tr>
						<td><?php echo (int) $i + 1; ?></td>
						<td><?php echo (int) $row['term_id']; ?></td>
						<td><a href="<?php echo esc_url( get_edit_term_link( $row['term_id'], 'product_cat', 'product' ) ); ?>"><?php echo esc_html( wp_specialchars_decode( $row['name'], ENT_QUOTES ) ); ?></a></td>
						<td style="text-align:right"><?php echo esc_html( self::money( $row['revenue'] ) ); ?></td>
						<td style="text-align:right"><?php echo esc_html( number_format_i18n( $row['qty'] ) ); ?></td>
						<td>
							<?php
							$notes = array();
							if ( 0 === $row['parent'] ) {
								$notes[] = __( 'Hauptkategorie', 'hb-top-listen' );
							}
							if ( $row['product_count'] < 1 ) {
								$notes[] = __( 'leer', 'hb-top-listen' );
							}
							echo esc_html( implode( ', ', $notes ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Element-Ergebnis «HB Top Kategorien»', 'hb-top-listen' ); ?></h2>
		<?php
		$ids = HB_Top_Listen_Ranking::category_ids( array_merge( $s, array( 'criterion' => $request['order'] ) ) );
		self::render_element_result(
			array_fill_keys( $ids, $request['order'] ),
			static function ( $id ) {
				$term = get_term( $id, 'product_cat' );
				return $term && ! is_wp_error( $term ) ? $term->name : '#' . $id;
			},
			wp_list_pluck( $rows, 'term_id' )
		);
	}

	/**
	 * Ergebnisliste eines Elements (so wie es auf der Seite erscheint).
	 *
	 * @param array<int,string> $picked     ID => Quelle.
	 * @param callable          $name_of    Liefert den Namen zu einer ID.
	 * @param int[]             $period_ids IDs mit Verkäufen im Zeitraum (sonst = aufgefüllt).
	 */
	private static function render_element_result( array $picked, callable $name_of, array $period_ids ): void {
		if ( ! $picked ) {
			echo '<p>' . esc_html__( 'Keine Treffer – das Element gibt nichts aus.', 'hb-top-listen' ) . '</p>';
			return;
		}

		$sources = array(
			'revenue' => __( 'nach Umsatz', 'hb-top-listen' ),
			'qty'     => __( 'nach Stückzahl', 'hb-top-listen' ),
		);
		echo '<ol>';
		foreach ( $picked as $id => $source ) {
			printf(
				'<li>%1$s <code>#%2$d</code> – %3$s%4$s</li>',
				esc_html( wp_specialchars_decode( (string) $name_of( $id ), ENT_QUOTES ) ),
				(int) $id,
				esc_html( $sources[ $source ] ?? $source ),
				in_array( (int) $id, array_map( 'intval', $period_ids ), true ) ? '' : ' · <em>' . esc_html__( 'aufgefüllt (Bestseller seit jeher)', 'hb-top-listen' ) . '</em>'
			);
		}
		echo '</ol>';
		printf( '<p><code>ids="%s"</code></p>', esc_html( implode( ',', array_keys( $picked ) ) ) );
	}

	/**
	 * Summenzeile über alle Zeilen der Abfrage.
	 *
	 * @param array[] $rows Zeilen.
	 */
	private static function render_totals( array $rows ): void {
		printf(
			'<p>%1$s <strong>%2$s</strong> · %3$s <strong>%4$s</strong> · %5$s <strong>%6$s</strong></p>',
			esc_html__( 'Produkte:', 'hb-top-listen' ),
			esc_html( number_format_i18n( count( $rows ) ) ),
			esc_html__( 'Umsatz netto total:', 'hb-top-listen' ),
			esc_html( self::money( array_sum( wp_list_pluck( $rows, 'revenue' ) ) ) ),
			esc_html__( 'Stückzahl total:', 'hb-top-listen' ),
			esc_html( number_format_i18n( array_sum( wp_list_pluck( $rows, 'qty' ) ) ) )
		);
	}

	/**
	 * Hinweis-Spalte für Produkte.
	 *
	 * @param array $row Zeile.
	 * @return string
	 */
	private static function product_note( array $row ): string {
		if ( '' === $row['status'] ) {
			return __( 'gelöscht – wird übersprungen', 'hb-top-listen' );
		}
		$product = wc_get_product( $row['product_id'] );
		if ( ! $product ) {
			return __( 'kein Produkt', 'hb-top-listen' );
		}
		$notes = array();
		if ( 'publish' !== $product->get_status() ) {
			$notes[] = __( 'nicht veröffentlicht', 'hb-top-listen' );
		} elseif ( ! $product->is_visible() ) {
			$notes[] = __( 'nicht sichtbar', 'hb-top-listen' );
		}
		if ( ! $product->is_in_stock() ) {
			$notes[] = __( 'nicht lagernd', 'hb-top-listen' );
		}
		if ( $row['revenue'] <= 0 && $row['qty'] <= 0 ) {
			$notes[] = __( 'netto kein Verkauf', 'hb-top-listen' );
		}
		return implode( ', ', $notes );
	}

	/**
	 * Betrag formatieren (ohne HTML).
	 *
	 * @param float $amount Betrag.
	 * @return string
	 */
	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
}
