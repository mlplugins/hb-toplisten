<?php
/**
 * Shortcodes und UX-Builder-Elemente «HB Top Produkte» und «HB Top Kategorien».
 *
 * Die Ausgabe läuft über die Flatsome-Shortcodes ux_products bzw.
 * ux_product_categories mit berechneten ids – das Aussehen bleibt damit
 * identisch zu den Flatsome-Elementen. Alle Layout-Attribute werden durchgereicht.
 *
 * @package HB_Top_Listen
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elemente für Frontend und UX Builder.
 */
class HB_Top_Listen_Elements {

	const PRODUCTS_TAG   = 'hb_top_products';
	const CATEGORIES_TAG = 'hb_top_categories';

	/**
	 * Eigene Einstellungen bzw. Attribute, die nicht an Flatsome gehen
	 * (die Auswahl steuert ausschliesslich das Plugin).
	 */
	const PRODUCT_OWN_ATTS  = array( 'count_revenue', 'count_qty', 'period', 'date_from', 'date_to', 'in_stock', 'cat_mode', 'cats', 'fallback', 'ids', 'cat', 'products', 'offset', 'orderby', 'order', 'show', 'tags', 'out_of_stock', 'relay', 'page_number' );
	const CATEGORY_OWN_ATTS = array( 'count', 'criterion', 'period', 'date_from', 'date_to', 'exclude_top', 'cat_mode', 'cats', 'fallback', 'ids', 'cat', 'number', 'offset', 'orderby', 'order', 'parent', 'hide_empty' );

	/**
	 * Hooks registrieren.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
		// Nach Flatsome (Priorität 10), das dort erst seine Builder-Helfer lädt.
		add_action( 'ux_builder_setup', array( __CLASS__, 'register_builder_elements' ), 20 );
	}

	/**
	 * Shortcodes immer registrieren – ohne Flatsome geben sie einfach nichts aus.
	 */
	public static function register_shortcodes(): void {
		add_shortcode( self::PRODUCTS_TAG, array( __CLASS__, 'render_products' ) );
		add_shortcode( self::CATEGORIES_TAG, array( __CLASS__, 'render_categories' ) );
	}

	/**
	 * Shortcode [hb_top_products].
	 *
	 * @param array|string $atts Attribute.
	 * @return string
	 */
	public static function render_products( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();
		if ( ! self::can_render( 'ux_products' ) ) {
			return '';
		}

		try {
			$settings = HB_Top_Listen_Ranking::normalize_product_settings( $atts );
			if ( $settings['count_revenue'] + $settings['count_qty'] < 1 ) {
				return '';
			}

			$picked = HB_Top_Listen_Ranking::pick_products( HB_Top_Listen_Cache::get( $settings ), $settings );
			if ( ! $picked ) {
				return '';
			}

			$layout        = self::layout_atts( $atts, self::PRODUCT_OWN_ATTS );
			$layout['ids'] = implode( ',', array_keys( $picked ) );
			return do_shortcode( self::build_shortcode( 'ux_products', $layout ) );
		} catch ( Throwable $e ) {
			HB_Top_Listen_Cache::log( $e );
			return '';
		}
	}

	/**
	 * Shortcode [hb_top_categories].
	 *
	 * @param array|string $atts Attribute.
	 * @return string
	 */
	public static function render_categories( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();
		if ( ! self::can_render( 'ux_product_categories' ) ) {
			return '';
		}

		try {
			$settings = HB_Top_Listen_Ranking::normalize_category_settings( $atts );
			$ids      = HB_Top_Listen_Cache::get( $settings );
			if ( ! $ids ) {
				return '';
			}

			$layout        = self::layout_atts( $atts, self::CATEGORY_OWN_ATTS );
			$layout['ids'] = implode( ',', array_map( 'intval', $ids ) );
			return do_shortcode( self::build_shortcode( 'ux_product_categories', $layout ) );
		} catch ( Throwable $e ) {
			HB_Top_Listen_Cache::log( $e );
			return '';
		}
	}

	/**
	 * Nur ausgeben, wenn WooCommerce läuft und Flatsome den Ziel-Shortcode bereitstellt.
	 *
	 * @param string $tag Flatsome-Shortcode.
	 * @return bool
	 */
	private static function can_render( string $tag ): bool {
		return class_exists( 'WooCommerce' ) && shortcode_exists( $tag );
	}

	/**
	 * Layout-Attribute zum Durchreichen: alles ausser den eigenen Einstellungen.
	 *
	 * @param array    $atts Attribute.
	 * @param string[] $own  Nicht durchzureichende Schlüssel.
	 * @return array<string,string>
	 */
	private static function layout_atts( array $atts, array $own ): array {
		$layout = array();
		foreach ( $atts as $key => $value ) {
			if ( ! is_string( $key ) || in_array( $key, $own, true ) || ! preg_match( '/^[a-z0-9_-]+$/', $key ) ) {
				continue;
			}
			$layout[ $key ] = (string) $value;
		}
		return $layout;
	}

	/**
	 * Baut einen Shortcode-String. Zeichen, die den Shortcode-Parser brechen, fallen weg.
	 *
	 * @param string $tag  Shortcode.
	 * @param array  $atts Attribute.
	 * @return string
	 */
	private static function build_shortcode( string $tag, array $atts ): string {
		$parts = array( $tag );
		foreach ( $atts as $key => $value ) {
			$parts[] = $key . '="' . str_replace( array( '"', '[', ']' ), '', $value ) . '"';
		}
		return '[' . implode( ' ', $parts ) . ']';
	}

	/**
	 * UX-Builder-Elemente registrieren.
	 */
	public static function register_builder_elements(): void {
		if ( ! function_exists( 'add_ux_builder_shortcode' ) ) {
			return;
		}

		try {
			self::add_builder_elements();
		} catch ( Throwable $e ) {
			// Nie den UX Builder blockieren – im Fehlerfall fehlen nur die zwei Elemente.
			HB_Top_Listen_Cache::log( $e );
		}
	}

	/**
	 * Die beiden Elemente beim UX Builder anmelden.
	 */
	private static function add_builder_elements(): void {
		$thumbnail = static function ( string $name ): string {
			return function_exists( 'flatsome_ux_builder_thumbnail' ) ? flatsome_ux_builder_thumbnail( $name ) : '';
		};

		add_ux_builder_shortcode(
			self::PRODUCTS_TAG,
			array(
				'name'      => __( 'HB Top Produkte', 'hb-top-listen' ),
				'category'  => __( 'Shop' ),
				'priority'  => 1,
				'thumbnail' => $thumbnail( 'products' ),
				'info'      => '{{ count_revenue }} + {{ count_qty }}',
				'options'   => array_merge( self::product_settings_options(), self::flatsome_product_layout_options() ),
			)
		);

		add_ux_builder_shortcode(
			self::CATEGORIES_TAG,
			array(
				'name'      => __( 'HB Top Kategorien', 'hb-top-listen' ),
				'category'  => __( 'Shop' ),
				'priority'  => 3,
				'thumbnail' => $thumbnail( 'categories' ),
				'info'      => '{{ count }}',
				'options'   => array_merge( self::category_settings_options(), self::flatsome_category_layout_options() ),
			)
		);
	}

	/**
	 * Gemeinsame Builder-Optionen: Zeitraum.
	 *
	 * @return array
	 */
	private static function period_options(): array {
		return array(
			'period'    => array(
				'type'    => 'select',
				'heading' => __( 'Zeitraum', 'hb-top-listen' ),
				'default' => 'last_30_days',
				'options' => HB_Top_Listen_Ranking::period_labels(),
			),
			'date_from' => array(
				'type'        => 'textfield',
				'heading'     => __( 'Von (JJJJ-MM-TT)', 'hb-top-listen' ),
				'conditions'  => 'period === "custom"',
				'default'     => '',
				'placeholder' => '2026-01-01',
			),
			'date_to'   => array(
				'type'        => 'textfield',
				'heading'     => __( 'Bis (JJJJ-MM-TT)', 'hb-top-listen' ),
				'conditions'  => 'period === "custom"',
				'default'     => '',
				'placeholder' => __( 'leer = heute', 'hb-top-listen' ),
			),
		);
	}

	/**
	 * Gemeinsame Builder-Optionen: Kategorien-Filter.
	 *
	 * @return array
	 */
	private static function category_filter_options(): array {
		return array(
			'cat_mode' => array(
				'type'    => 'select',
				'heading' => __( 'Modus', 'hb-top-listen' ),
				'default' => 'exclude',
				'options' => array(
					'exclude' => __( 'Diese Kategorien ausschliessen', 'hb-top-listen' ),
					'include' => __( 'Nur diese Kategorien', 'hb-top-listen' ),
				),
			),
			'cats'     => array(
				'type'        => 'select',
				'heading'     => __( 'Kategorien (inkl. Unterkategorien)', 'hb-top-listen' ),
				'full_width'  => true,
				'default'     => '',
				'config'      => array(
					'multiple'    => true,
					'placeholder' => __( 'Auswählen …', 'hb-top-listen' ),
					'termSelect'  => array(
						'post_type'  => 'product_cat',
						'taxonomies' => 'product_cat',
					),
				),
			),
		);
	}

	/**
	 * Eigene Einstellungen «HB Top Produkte».
	 *
	 * @return array
	 */
	private static function product_settings_options(): array {
		return array(
			'hb_top_settings' => array(
				'type'    => 'group',
				'heading' => __( 'Top-Liste', 'hb-top-listen' ),
				'options' => array_merge(
					array(
						'count_revenue' => array(
							'type'    => 'slider',
							'heading' => __( 'Anzahl nach Umsatz', 'hb-top-listen' ),
							'default' => 4,
							'min'     => 0,
							'max'     => HB_Top_Listen_Ranking::MAX_COUNT,
						),
						'count_qty'     => array(
							'type'    => 'slider',
							'heading' => __( 'Anzahl nach Stückzahl', 'hb-top-listen' ),
							'default' => 4,
							'min'     => 0,
							'max'     => HB_Top_Listen_Ranking::MAX_COUNT,
						),
					),
					self::period_options(),
					array(
						'in_stock' => array(
							'type'    => 'checkbox',
							'heading' => __( 'Nur lagernde Produkte', 'hb-top-listen' ),
							'default' => 'false',
						),
						'fallback' => array(
							'type'        => 'checkbox',
							'heading'     => __( 'Mit Bestsellern seit jeher auffüllen', 'hb-top-listen' ),
							'description' => __( 'Falls der Zeitraum zu wenige Treffer liefert.', 'hb-top-listen' ),
							'default'     => 'true',
						),
					)
				),
			),
			'hb_top_filter'   => array(
				'type'    => 'group',
				'heading' => __( 'Kategorien-Filter', 'hb-top-listen' ),
				'options' => self::category_filter_options(),
			),
		);
	}

	/**
	 * Eigene Einstellungen «HB Top Kategorien».
	 *
	 * @return array
	 */
	private static function category_settings_options(): array {
		return array(
			'hb_top_settings' => array(
				'type'    => 'group',
				'heading' => __( 'Top-Liste', 'hb-top-listen' ),
				'options' => array_merge(
					array(
						'count'     => array(
							'type'    => 'slider',
							'heading' => __( 'Anzahl Kategorien', 'hb-top-listen' ),
							'default' => 8,
							'min'     => 1,
							'max'     => HB_Top_Listen_Ranking::MAX_COUNT,
						),
						'criterion' => array(
							'type'    => 'select',
							'heading' => __( 'Kriterium', 'hb-top-listen' ),
							'default' => 'revenue',
							'options' => array(
								'revenue' => __( 'Umsatz', 'hb-top-listen' ),
								'qty'     => __( 'Stückzahl', 'hb-top-listen' ),
							),
						),
					),
					self::period_options(),
					array(
						'fallback' => array(
							'type'        => 'checkbox',
							'heading'     => __( 'Mit Bestsellern seit jeher auffüllen', 'hb-top-listen' ),
							'description' => __( 'Falls der Zeitraum zu wenige Treffer liefert.', 'hb-top-listen' ),
							'default'     => 'true',
						),
					)
				),
			),
			'hb_top_filter'   => array(
				'type'    => 'group',
				'heading' => __( 'Kategorien-Filter', 'hb-top-listen' ),
				'options' => array_merge(
					array(
						'exclude_top' => array(
							'type'    => 'checkbox',
							'heading' => __( 'Hauptkategorien (oberste Ebene) ausschliessen', 'hb-top-listen' ),
							'default' => 'true',
						),
					),
					self::category_filter_options()
				),
			),
		);
	}

	/**
	 * Pfad zu den Builder-Definitionen von Flatsome, falls vorhanden.
	 *
	 * @return string|null
	 */
	private static function flatsome_builder_dir(): ?string {
		$dir = get_template_directory() . '/inc/builder/shortcodes';
		if ( ! function_exists( 'flatsome_ux_builder_image_sizes' ) && file_exists( get_template_directory() . '/inc/builder/helpers.php' ) ) {
			require_once get_template_directory() . '/inc/builder/helpers.php';
		}
		return function_exists( 'flatsome_ux_builder_image_sizes' )
			&& file_exists( $dir . '/commons/repeater-options.php' )
			&& file_exists( $dir . '/commons/box-styles.php' ) ? $dir : null;
	}

	/**
	 * Layout-Optionen wie im Flatsome-Element «Products» (ux_products),
	 * direkt aus den Flatsome-Definitionen geladen.
	 *
	 * @return array
	 */
	private static function flatsome_product_layout_options(): array {
		$dir = self::flatsome_builder_dir();
		if ( null === $dir ) {
			return array();
		}

		// Diese Variablen erwarten die Flatsome-Definitionen im lokalen Scope.
		$repeater_columns     = '4';
		$repeater_type        = 'slider';
		$repeater_col_spacing = 'small';
		$default_text_align   = 'left';

		$options = array(
			'style_options'         => array(
				'type'    => 'group',
				'heading' => __( 'Style' ),
				'options' => array(
					'style' => array(
						'type'    => 'select',
						'heading' => __( 'Style' ),
						'default' => 'default',
						'options' => require $dir . '/values/box-layouts.php',
					),
				),
			),
			'layout_options'        => require $dir . '/commons/repeater-options.php',
			'layout_options_slider' => require $dir . '/commons/repeater-slider.php',
			'box_options'           => array(
				'type'    => 'group',
				'heading' => __( 'Box' ),
				'options' => array(
					'show_cat'         => array(
						'type'    => 'checkbox',
						'heading' => __( 'Category' ),
						'default' => 'true',
					),
					'show_title'       => array(
						'type'    => 'checkbox',
						'heading' => __( 'Title' ),
						'default' => 'true',
					),
					'show_rating'      => array(
						'type'    => 'checkbox',
						'heading' => __( 'Rating' ),
						'default' => 'true',
					),
					'show_price'       => array(
						'type'    => 'checkbox',
						'heading' => __( 'Price' ),
						'default' => 'true',
					),
					'show_add_to_cart' => array(
						'type'    => 'checkbox',
						'heading' => __( 'Add To Cart' ),
						'default' => 'true',
					),
					'show_quick_view'  => array(
						'type'    => 'checkbox',
						'heading' => __( 'Quick View' ),
						'default' => 'true',
					),
					'equalize_box'     => array(
						'type'    => 'checkbox',
						'heading' => __( 'Equalize Items' ),
						'default' => 'false',
					),
				),
			),
		);

		$options = array_merge( $options, require $dir . '/commons/box-styles.php' );

		// Gleiche Bedingungen wie im Flatsome-Original.
		$options['image_options']['conditions']                            = 'style !== "default"';
		$options['text_options']['conditions']                             = 'style !== "default"';
		$options['layout_options']['options']['depth']['conditions']       = 'style !== "default"';
		$options['layout_options']['options']['depth_hover']['conditions'] = 'style !== "default"';

		return $options;
	}

	/**
	 * Layout-Optionen wie im Flatsome-Element «Product Categories».
	 *
	 * @return array
	 */
	private static function flatsome_category_layout_options(): array {
		$dir = self::flatsome_builder_dir();
		if ( null === $dir ) {
			return array();
		}

		$repeater_columns     = '4';
		$repeater_type        = 'slider';
		$repeater_col_spacing = 'normal';
		$default_text_align   = 'center';

		$options = array(
			'style_options'         => array(
				'type'    => 'group',
				'heading' => __( 'Style' ),
				'options' => array(
					'style' => array(
						'type'    => 'select',
						'heading' => __( 'Style' ),
						'default' => 'badge',
						'options' => require $dir . '/values/box-layouts.php',
					),
				),
			),
			'layout_options'        => require $dir . '/commons/repeater-options.php',
			'layout_options_slider' => require $dir . '/commons/repeater-slider.php',
			'cat_meta'              => array(
				'type'    => 'group',
				'heading' => __( 'Meta' ),
				'options' => array(
					'show_count' => array(
						'type'    => 'checkbox',
						'heading' => 'Show Count',
						'default' => 'true',
					),
				),
			),
		);

		return array_merge( $options, require $dir . '/commons/box-styles.php' );
	}
}
