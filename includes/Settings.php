<?php
/**
 * Ayarlar: tek seçenek `contenthealth_settings`. Kural ayarları `rules.<kural>` altında.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'contenthealth_settings';

	/** @var array<string,mixed>|null */
	private static ?array $cache = null;

	/** @return array<string,mixed> */
	public static function defaults(): array {
		$d = array(
			'frequency'          => 'off', // off|hourly|daily|weekly.
			'time_budget'        => 5,
			'batch_size'         => 100,
			'statuses'           => array( 'publish' ),
			'exclude_post_types' => array( 'attachment' ),
			'keep_modified'      => true,
			'audit_on_save'      => true,
			'delete_on_uninstall' => false,
			'live_host'          => '',
		);
		foreach ( Plugin::modules() as $module ) {
			$d = array_merge( $d, $module->settings() );
		}
		$d['rules'] = array();
		foreach ( Registry::rules() as $rule ) {
			$d['rules'][ $rule->id() ] = $rule->defaults();
		}
		return $d;
	}

	/** @return array<string,mixed> */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$d     = self::defaults();
		$out   = array_merge( $d, array_diff_key( $saved, array( 'rules' => 1 ) ) );
		foreach ( $d['rules'] as $id => $def ) {
			$out['rules'][ $id ] = array_merge( $def, is_array( $saved['rules'][ $id ] ?? null ) ? $saved['rules'][ $id ] : array() );
		}
		self::$cache = $out;
		return $out;
	}

	/** @return mixed */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** @return array<string,mixed> */
	public static function rule( string $id ): array {
		$all = self::all();
		return $all['rules'][ $id ] ?? array( 'enabled' => false );
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * Kaydet: girdiyi temizler, bilinmeyen anahtarı atar, desenleri doğrular.
	 *
	 * @param array<string,mixed> $in Ham girdi.
	 * @return array{settings:array<string,mixed>,errors:array<int,string>}
	 */
	public static function save( array $in ): array {
		$cur    = self::all();
		$errors = array();
		$freq   = isset( $in['frequency'] ) ? sanitize_key( (string) $in['frequency'] ) : $cur['frequency'];
		$cur['frequency'] = in_array( $freq, array( 'off', 'hourly', 'daily', 'weekly' ), true ) ? $freq : 'off';
		if ( isset( $in['time_budget'] ) ) {
			$cur['time_budget'] = max( 1, min( 20, (int) $in['time_budget'] ) );
		}
		if ( isset( $in['batch_size'] ) ) {
			$cur['batch_size'] = max( 10, min( 500, (int) $in['batch_size'] ) );
		}
		foreach ( array( 'statuses', 'exclude_post_types', 'term_taxonomies' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$cur[ $k ] = array_values( array_filter( array_map( 'sanitize_key', self::lines( $in[ $k ] ) ) ) );
			}
		}
		if ( array_key_exists( 'keep_modified', $in ) ) {
			$cur['keep_modified'] = (bool) $in['keep_modified'];
		}
		if ( array_key_exists( 'audit_on_save', $in ) ) {
			$cur['audit_on_save'] = (bool) $in['audit_on_save'];
		}
		if ( array_key_exists( 'delete_on_uninstall', $in ) ) {
			$cur['delete_on_uninstall'] = (bool) $in['delete_on_uninstall'];
		}
		if ( isset( $in['live_host'] ) ) {
			$cur['live_host'] = strtolower( preg_replace( '/[^A-Za-z0-9.\-:]/', '', (string) $in['live_host'] ) ?? '' );
		}
		if ( isset( $in['alt_template'] ) ) {
			$cur['alt_template'] = mb_substr( sanitize_text_field( (string) $in['alt_template'] ), 0, 200 );
		}
		if ( isset( $in['rules'] ) && is_array( $in['rules'] ) ) {
			foreach ( Registry::rules() as $rule ) {
				$id = $rule->id();
				if ( ! isset( $in['rules'][ $id ] ) || ! is_array( $in['rules'][ $id ] ) ) {
					continue;
				}
				$res = $rule->sanitize( $in['rules'][ $id ], $cur['rules'][ $id ] );
				foreach ( $res['errors'] as $e ) {
					$errors[] = $id . ': ' . $e;
				}
				$cur['rules'][ $id ] = $res['config'];
			}
		}
		update_option( self::OPTION, $cur, false );
		self::reset_cache();
		Cron::schedule();
		return array(
			'settings' => $cur,
			'errors'   => $errors,
		);
	}

	/**
	 * Satır satır ya da dizi girdisini temiz dizi yapar (kural AdminKit ayar motorundadır: tek kopya).
	 *
	 * @param mixed $v      Girdi.
	 * @param bool  $commas Virgül de ayraç sayılsın mı (desenlerde HAYIR: {0,220} bozulur).
	 * @return array<int,string>
	 */
	public static function lines( $v, bool $commas = true ): array {
		return \AdminKit\Core\Settings::lines( $v, $commas );
	}
}
