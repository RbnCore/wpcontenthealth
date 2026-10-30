<?php
/**
 * Terim silme/birleştirme: doğrudan veri tabanı, tam anlık görüntüyle geri alınabilir.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class TermOps {

	/** @return array<string,mixed>|null terim satırı + sınıflandırma + meta */
	public static function snapshot( int $term_id ): ?array {
		global $wpdb;
		$term = \AdminKit\Helpers\Db::get_row( "SELECT * FROM {$wpdb->terms} WHERE term_id = %d", array( $term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $term ) {
			return null;
		}
		return array(
			'term' => $term,
			'tt'   => (array) \AdminKit\Helpers\Db::get_results( "SELECT * FROM {$wpdb->term_taxonomy} WHERE term_id = %d", array( $term_id ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'meta' => (array) \AdminKit\Helpers\Db::get_results( "SELECT * FROM {$wpdb->termmeta} WHERE term_id = %d", array( $term_id ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	/** Gerçek (sayaçtan bağımsız) ilişki sayısı. */
	public static function real_count( int $term_id ): int {
		global $wpdb;
		return (int) \AdminKit\Helpers\Db::get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d", array( $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function purge( int $term_id ): void {
		global $wpdb;
		\AdminKit\Helpers\Db::delete( $wpdb->termmeta, array( 'term_id' => $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		\AdminKit\Helpers\Db::delete( $wpdb->term_taxonomy, array( 'term_id' => $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		\AdminKit\Helpers\Db::delete( $wpdb->terms, array( 'term_id' => $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_term_cache( $term_id );
	}

	private static function restore( array $snap ): void {
		global $wpdb;
		\AdminKit\Helpers\Db::insert( $wpdb->terms, $snap['term'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $snap['tt'] as $r ) {
			\AdminKit\Helpers\Db::insert( $wpdb->term_taxonomy, $r ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		foreach ( $snap['meta'] as $r ) {
			\AdminKit\Helpers\Db::insert( $wpdb->termmeta, $r ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** @return array{before:string,after:string}|\WP_Error */
	public static function delete_empty( int $term_id, bool $dry ) {
		$snap = self::snapshot( $term_id );
		if ( ! $snap ) {
			return new \WP_Error( 'gone', __( 'The term no longer exists.', 'contenthealth' ) );
		}
		if ( self::real_count( $term_id ) > 0 ) {
			return new \WP_Error( 'not_empty', __( 'The term is no longer empty; it was not deleted.', 'contenthealth' ) );
		}
		if ( ! $dry ) {
			self::purge( $term_id );
		}
		return array(
			'before' => (string) wp_json_encode( $snap, JSON_UNESCAPED_UNICODE ),
			'after'  => 'deleted',
		);
	}

	/**
	 * `$from` terimini `$to` içine katar: ilişkiler taşınır, `$from` silinir, eski adres için yönlendirme önerisi yazılır.
	 *
	 * @return array{before:string,after:string,moved:int}|\WP_Error
	 */
	public static function merge( int $from, int $to, bool $dry ) {
		global $wpdb;
		$a = self::snapshot( $from );
		$b = self::snapshot( $to );
		if ( ! $a || ! $b || $from === $to ) {
			return new \WP_Error( 'gone', __( 'One of the terms to merge does not exist.', 'contenthealth' ) );
		}
		$tt_from = (int) ( $a['tt'][0]['term_taxonomy_id'] ?? 0 );
		$tax     = (string) ( $a['tt'][0]['taxonomy'] ?? '' );
		$tt_to   = 0;
		foreach ( $b['tt'] as $r ) {
			if ( $r['taxonomy'] === $tax ) {
				$tt_to = (int) $r['term_taxonomy_id'];
			}
		}
		if ( ! $tt_from || ! $tt_to ) {
			return new \WP_Error( 'tax', __( 'The terms are not in the same taxonomy.', 'contenthealth' ) );
		}
		$objs   = array_map( 'intval', (array) \AdminKit\Helpers\Db::get_col( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", array( $tt_from ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$have   = $objs ? array_map( 'intval', (array) \AdminKit\Helpers\Db::get_col( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d AND object_id IN (" . implode( ',', array_fill( 0, count( $objs ), '%d' ) ) . ')', array_merge( array( $tt_to ), $objs ) ) ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$moved  = array_values( array_diff( $objs, $have ) );
		$dups   = array_values( array_intersect( $objs, $have ) );
		$before = (string) wp_json_encode(
			array(
				'from'  => $a,
				'to'    => $to,
				'tt_to' => $tt_to,
				'moved' => $moved,
				'dups'  => $dups,
				'slug'  => $a['term']['slug'],
			),
			JSON_UNESCAPED_UNICODE
		);
		if ( ! $dry ) {
			if ( $moved ) {
				\AdminKit\Helpers\Db::query( "UPDATE {$wpdb->term_relationships} SET term_taxonomy_id = %d WHERE term_taxonomy_id = %d AND object_id IN (" . implode( ',', array_fill( 0, count( $moved ), '%d' ) ) . ')', array_merge( array( $tt_to, $tt_from ), $moved ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			\AdminKit\Helpers\Db::delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => $tt_from ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::purge( $from );
			wp_update_term_count_now( array( $tt_to ), $tax );
			$map                          = (array) get_option( 'contenthealth_term_redirects', array() );
			$map[ $tax . ':' . $a['term']['slug'] ] = $b['term']['slug'];
			update_option( 'contenthealth_term_redirects', $map, false );
		}
		return array(
			'before' => $before,
			'after'  => 'merged:' . $to,
			'moved'  => count( $moved ),
		);
	}

	/** @return true|string */
	public static function revert( object $row, bool $dry ) {
		global $wpdb;
		$id   = (int) $row->object_id;
		$data = json_decode( (string) $row->before_value, true );
		if ( ! is_array( $data ) ) {
			return __( 'broken record', 'contenthealth' );
		}
		if ( null !== \AdminKit\Helpers\Db::get_var( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d", array( $id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return __( 'term already exists', 'contenthealth' );
		}
		if ( $dry ) {
			return true;
		}
		if ( 'term_delete' === $row->field ) {
			self::restore( $data );
			$tt = (int) ( $data['tt'][0]['term_taxonomy_id'] ?? 0 );
			if ( $tt ) {
				wp_update_term_count_now( array( $tt ), (string) $data['tt'][0]['taxonomy'] );
			}
			return true;
		}
		$from = $data['from'];
		self::restore( $from );
		$tt_from = (int) ( $from['tt'][0]['term_taxonomy_id'] ?? 0 );
		$tt_to   = (int) $data['tt_to'];
		$moved   = array_map( 'intval', (array) $data['moved'] );
		$dups    = array_map( 'intval', (array) $data['dups'] );
		if ( $moved ) {
			\AdminKit\Helpers\Db::query( "UPDATE {$wpdb->term_relationships} SET term_taxonomy_id = %d WHERE term_taxonomy_id = %d AND object_id IN (" . implode( ',', array_fill( 0, count( $moved ), '%d' ) ) . ')', array_merge( array( $tt_from, $tt_to ), $moved ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( $dups as $obj ) {
			\AdminKit\Helpers\Db::insert( $wpdb->term_relationships, array( 'object_id' => $obj, 'term_taxonomy_id' => $tt_from, 'term_order' => 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$tax = (string) $from['tt'][0]['taxonomy'];
		wp_update_term_count_now( array( $tt_from, $tt_to ), $tax );
		$map = (array) get_option( 'contenthealth_term_redirects', array() );
		unset( $map[ $tax . ':' . $data['slug'] ] );
		update_option( 'contenthealth_term_redirects', $map, false );
		clean_term_cache( array( $id, (int) $data['to'] ) );
		return true;
	}
}
