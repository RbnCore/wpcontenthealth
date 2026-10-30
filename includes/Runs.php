<?php
/**
 * Koşu yöneticisi: başlat, duraklat, devam, iptal; çakışma kilidi.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Runs {

	/** Bu süredir güncellenmeyen "çalışıyor" koşu terk edilmiş sayılır. */
	private const STALE = 900;

	public static function get( int $id ): ?object {
		global $wpdb;
		$t   = Db::table( 'runs' );
		$row = \AdminKit\Helpers\Db::get_row( "SELECT * FROM {$t} WHERE id = %d", array( $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( object $r ): object {
		$r->id     = (int) $r->id;
		$r->total  = (int) $r->total;
		$r->done   = (int) $r->done;
		$r->scope  = json_decode( (string) $r->scope_json, true ) ?: array();
		$r->cursor = json_decode( (string) $r->cursor_json, true ) ?: array();
		$r->state  = json_decode( (string) $r->state_json, true ) ?: array();
		$r->stats  = json_decode( (string) $r->stats_json, true ) ?: array();
		return $r;
	}

	/** Çalışan ya da duraklatılmış koşu (terk edilmişler iptal edilir). */
	public static function active(): ?object {
		global $wpdb;
		$t   = Db::table( 'runs' );
		$row = \AdminKit\Helpers\Db::get_row( "SELECT * FROM {$t} WHERE status IN ('running','paused') ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$run = self::hydrate( $row );
		if ( 'running' === $run->status && time() - (int) strtotime( $run->updated_at . ' UTC' ) > self::STALE ) {
			self::set_status( $run->id, 'cancelled', true );
			return null;
		}
		return $run;
	}

	public static function latest(): ?object {
		global $wpdb;
		$t   = Db::table( 'runs' );
		$row = \AdminKit\Helpers\Db::get_row( "SELECT * FROM {$t} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function latest_finished(): ?object {
		global $wpdb;
		$t   = Db::table( 'runs' );
		$row = \AdminKit\Helpers\Db::get_row( "SELECT * FROM {$t} WHERE status = 'finished' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<int,object> */
	public static function history( int $n = 10 ): array {
		global $wpdb;
		$t = Db::table( 'runs' );
		return array_map( array( self::class, 'hydrate' ), (array) \AdminKit\Helpers\Db::get_results( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", array( $n ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Yeni koşu başlatır.
	 *
	 * @param array<string,mixed> $scope rules (kimlik listesi), post_types, limit.
	 * @return object|\WP_Error
	 */
	public static function start( array $scope, string $trigger = 'manual' ) {
		global $wpdb;
		if ( self::active() ) {
			return new \WP_Error( 'run_active', __( 'A scan is already running.', 'contenthealth' ), array( 'status' => 409 ) );
		}
		$scope = array(
			'rules'      => array_values( array_filter( array_map( 'sanitize_key', (array) ( $scope['rules'] ?? array() ) ) ) ),
			'post_types' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $scope['post_types'] ?? array() ) ) ) ),
			'limit'      => max( 0, (int) ( $scope['limit'] ?? 0 ) ),
		);
		$now   = current_time( 'mysql', true );
		\AdminKit\Helpers\Db::insert( Db::table( 'runs' ),
			array(
				'status'       => 'running',
				'trigger_type' => $trigger,
				'scope_json'   => wp_json_encode( $scope ),
				'cursor_json'  => '{}',
				'state_json'   => '{}',
				'user_id'      => get_current_user_id(),
				'started_at'   => $now,
				'updated_at'   => $now,
			) );
		$id = (int) $wpdb->insert_id;
		return $id ? Scanner::init( (object) array( 'id' => $id ) ) : new \WP_Error( 'db', __( 'Could not write the run record.', 'contenthealth' ) );
	}

	/** @param array<string,mixed> $fields */
	public static function update( int $id, array $fields ): void {
		global $wpdb;
		foreach ( array( 'cursor', 'state', 'stats', 'scope' ) as $j ) {
			if ( isset( $fields[ $j ] ) ) {
				$fields[ $j . '_json' ] = wp_json_encode( $fields[ $j ] );
				unset( $fields[ $j ] );
			}
		}
		$fields['updated_at'] = current_time( 'mysql', true );
		\AdminKit\Helpers\Db::update( Db::table( 'runs' ), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function set_status( int $id, string $status, bool $finish = false ): void {
		$f = array( 'status' => $status );
		if ( $finish ) {
			$f['finished_at'] = current_time( 'mysql', true );
		}
		self::update( $id, $f );
	}

	public static function pause( int $id ): bool {
		$r = self::get( $id );
		if ( $r && 'running' === $r->status ) {
			self::set_status( $id, 'paused' );
			return true;
		}
		return false;
	}

	public static function resume( int $id ): bool {
		$r = self::get( $id );
		if ( $r && 'paused' === $r->status ) {
			self::set_status( $id, 'running' );
			return true;
		}
		return false;
	}

	public static function cancel( int $id ): bool {
		$r = self::get( $id );
		if ( $r && in_array( $r->status, array( 'running', 'paused' ), true ) ) {
			self::set_status( $id, 'cancelled', true );
			return true;
		}
		return false;
	}

	/** @return array<string,mixed> arayüz/CLI için ilerleme özeti */
	public static function progress( object $run ): array {
		return array(
			'id'      => $run->id,
			'status'  => $run->status,
			'total'   => $run->total,
			'done'    => $run->done,
			'percent' => $run->total > 0 ? min( 100, (int) floor( 100 * $run->done / $run->total ) ) : ( 'finished' === $run->status ? 100 : 0 ),
			'score'   => null === $run->score ? null : (int) $run->score,
			'stage'   => $run->state['label'] ?? '',
		);
	}
}
