<?php
/**
 * Caching und regelmässige Neuberechnung.
 *
 * Jede Einstellungs-Kombination wird als Transient gespeichert. Der Schlüssel
 * enthält den Hash der Einstellungen und die aufgelösten Datumsgrenzen – nach
 * einem Datumswechsel entsteht also automatisch ein neuer Eintrag. Genutzte
 * Kombinationen werden in einem Register gemerkt und per WP-Cron alle 6 Stunden
 * sowie kurz nach Mitternacht neu berechnet.
 *
 * @package HB_Top_Listen
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-Cache und Scheduler.
 */
class HB_Top_Listen_Cache {

	const CRON_HOOK         = 'hb_top_listen_refresh';
	const MIDNIGHT_HOOK     = 'hb_top_listen_midnight';
	const CRON_SCHEDULE     = 'hb_top_listen_six_hours';
	const OPTION_REGISTRY   = 'hb_top_listen_registry';
	const OPTION_GENERATION = 'hb_top_listen_cache_gen';
	const TRANSIENT_PREFIX  = 'hb_tl_';

	/**
	 * Lebensdauer eines Cache-Eintrags (etwas länger als das Cron-Intervall).
	 */
	const TTL = 7 * HOUR_IN_SECONDS;

	/**
	 * Register-Einträge, die so lange nicht mehr gebraucht wurden, fallen weg.
	 */
	const REGISTRY_MAX_AGE = 14 * DAY_IN_SECONDS;

	/**
	 * Maximale Anzahl gemerkter Einstellungs-Kombinationen.
	 */
	const REGISTRY_MAX = 50;

	/**
	 * Hooks registrieren.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_all' ) );
		add_action( self::MIDNIGHT_HOOK, array( __CLASS__, 'refresh_all' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule_events' ) );
	}

	/**
	 * Aktivierung: Events planen.
	 */
	public static function activate(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		self::schedule_events();
	}

	/**
	 * Deaktivierung: Events entfernen.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::MIDNIGHT_HOOK );
	}

	/**
	 * Cron-Intervall «alle 6 Stunden».
	 *
	 * @param array $schedules Vorhandene Intervalle.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Alle 6 Stunden (HB Top-Listen)', 'hb-top-listen' ),
		);
		return $schedules;
	}

	/**
	 * Plant die Events, falls sie fehlen.
	 */
	public static function schedule_events(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::MIDNIGHT_HOOK ) ) {
			$next_midnight = new DateTimeImmutable( 'tomorrow 00:05', wp_timezone() );
			wp_schedule_event( $next_midnight->getTimestamp(), 'daily', self::MIDNIGHT_HOOK );
		}
	}

	/**
	 * Liefert das (gecachte) Berechnungsergebnis für eine Einstellungs-Kombination.
	 *
	 * @param array $settings Normalisierte Einstellungen (products oder categories).
	 * @return array
	 */
	public static function get( array $settings ): array {
		$key  = self::key( $settings );
		$data = get_transient( $key );

		if ( ! is_array( $data ) ) {
			$data = self::compute( $settings );
			set_transient( $key, $data, self::TTL );
		}

		self::remember( $settings );
		return $data;
	}

	/**
	 * Berechnet ein Ergebnis ohne Cache.
	 *
	 * @param array $settings Normalisierte Einstellungen.
	 * @return array
	 */
	public static function compute( array $settings ): array {
		return 'categories' === $settings['type']
			? HB_Top_Listen_Ranking::category_ids( $settings )
			: HB_Top_Listen_Ranking::product_candidates( $settings );
	}

	/**
	 * Transient-Schlüssel: Einstellungen + aktuelle Datumsgrenzen + Cache-Generation.
	 *
	 * @param array $settings Normalisierte Einstellungen.
	 * @return string
	 */
	private static function key( array $settings ): string {
		$range = HB_Top_Listen_Ranking::resolve_period( $settings['period'], $settings['date_from'], $settings['date_to'] );
		$hash  = md5( wp_json_encode( array( $settings, $range, (int) get_option( self::OPTION_GENERATION, 1 ), HB_TOP_LISTEN_VERSION ) ) );
		return self::TRANSIENT_PREFIX . $hash;
	}

	/**
	 * Merkt sich eine genutzte Einstellungs-Kombination für die Neuberechnung.
	 * Schreibt höchstens einmal pro Tag je Kombination in die Datenbank.
	 *
	 * @param array $settings Normalisierte Einstellungen.
	 */
	private static function remember( array $settings ): void {
		// Probier-Einstellungen im UX-Builder-Editor nicht dauerhaft vormerken.
		if ( function_exists( 'ux_builder_is_active' ) && ux_builder_is_active() ) {
			return;
		}

		$registry = get_option( self::OPTION_REGISTRY, array() );
		$registry = is_array( $registry ) ? $registry : array();
		$id       = md5( wp_json_encode( $settings ) );

		if ( isset( $registry[ $id ] ) && $registry[ $id ]['seen'] > time() - DAY_IN_SECONDS ) {
			return;
		}

		$registry[ $id ] = array(
			'settings' => $settings,
			'seen'     => time(),
		);
		update_option( self::OPTION_REGISTRY, self::prune( $registry ), true );
	}

	/**
	 * Entfernt veraltete Register-Einträge und begrenzt die Grösse.
	 *
	 * @param array $registry Register.
	 * @return array
	 */
	private static function prune( array $registry ): array {
		$registry = array_filter(
			$registry,
			static function ( $entry ) {
				return is_array( $entry ) && isset( $entry['settings'], $entry['seen'] ) && $entry['seen'] > time() - self::REGISTRY_MAX_AGE;
			}
		);
		uasort(
			$registry,
			static function ( $a, $b ) {
				return $b['seen'] <=> $a['seen'];
			}
		);
		return array_slice( $registry, 0, self::REGISTRY_MAX, true );
	}

	/**
	 * Anzahl gemerkter Einstellungs-Kombinationen.
	 *
	 * @return int
	 */
	public static function registry_count(): int {
		$registry = get_option( self::OPTION_REGISTRY, array() );
		return is_array( $registry ) ? count( $registry ) : 0;
	}

	/**
	 * Berechnet alle gemerkten Kombinationen neu (Cron und Admin-Button).
	 */
	public static function refresh_all(): void {
		$registry = get_option( self::OPTION_REGISTRY, array() );
		$registry = self::prune( is_array( $registry ) ? $registry : array() );
		update_option( self::OPTION_REGISTRY, $registry, true );

		foreach ( $registry as $entry ) {
			try {
				set_transient( self::key( $entry['settings'] ), self::compute( $entry['settings'] ), self::TTL );
			} catch ( Throwable $e ) {
				self::log( $e );
			}
		}
	}

	/**
	 * Verwirft alle Cache-Einträge und berechnet die gemerkten Kombinationen neu.
	 */
	public static function flush(): void {
		global $wpdb;

		update_option( self::OPTION_GENERATION, (int) get_option( self::OPTION_GENERATION, 1 ) + 1, true );

		// Alte Einträge aufräumen (bei persistentem Object Cache greift die neue Generation).
		$like = $wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		$like = $wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		self::refresh_all();
	}

	/**
	 * Fehler protokollieren, ohne die Seite abstürzen zu lassen.
	 *
	 * @param Throwable $e Fehler.
	 */
	public static function log( Throwable $e ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), array( 'source' => 'hb-top-listen' ) );
		}
	}
}
