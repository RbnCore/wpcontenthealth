<?php
/**
 * Bulgu yazıcı/okuyucu: upsert, düzelenleri kapatma, süzgeçli liste.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Issues {

	/**
	 * Bulguyu yazar. Aynı (tür, kimlik, kural) varsa last_seen güncellenir; "düzeltildi" ise yeniden açılır,
	 * "yoksayıldı" ise yoksayılmış kalır.
	 *
	 * @param array<string,mixed> $details Bulgu ayrıntısı.
	 */
	public static function upsert( int $run_id, string $type, int $id, Rule $rule, array $details ): void {
		global $wpdb;
		$t   = Db::table( 'issues' );
		$now = current_time( 'mysql', true );
		\AdminKit\Helpers\Db::query( "INSERT INTO {$t} (object_type, object_id, rule_id, group_id, severity, details, status, first_seen, last_seen, run_id)
				VALUES (%s, %d, %s, %s, %s, %s, 'open', %s, %s, %d)
				ON DUPLICATE KEY UPDATE details = VALUES(details), severity = VALUES(severity), group_id = VALUES(group_id),
				last_seen = VALUES(last_seen), run_id = VALUES(run_id), status = IF(status = 'fixed', 'open', status)", array( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type, $id, $rule->id(), $rule->group(), $rule->severity(), wp_json_encode( $details, JSON_UNESCAPED_UNICODE ), $now, $now, $run_id ) );
	}

	/**
	 * Bu koşuda görülmeyen açık bulguları "düzeltildi" yapar.
	 *
	 * @param array<int,string> $rule_ids Değerlendirilen kurallar.
	 */
	public static function sweep( int $run_id, string $type, array $rule_ids ): int {
		global $wpdb;
		if ( ! $rule_ids ) {
			return 0;
		}
		$t  = Db::table( 'issues' );
		$ph = implode( ',', array_fill( 0, count( $rule_ids ), '%s' ) );
		\AdminKit\Helpers\Db::query( "UPDATE {$t} SET status = 'fixed' WHERE status = 'open' AND object_type = %s AND run_id < %d AND rule_id IN ({$ph})", array_merge( array( $type, $run_id ), $rule_ids ) );
		Score::forget();
		return (int) $wpdb->rows_affected;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$t   = Db::table( 'issues' );
		$row = \AdminKit\Helpers\Db::get_row( "SELECT * FROM {$t} WHERE id = %d", array( $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( object $row ): object {
		$row->id          = (int) $row->id;
		$row->object_id   = (int) $row->object_id;
		$row->details_arr = json_decode( (string) $row->details, true ) ?: array();
		return $row;
	}

	/**
	 * @param array<string,mixed> $f status, rule, group, severity, object_type, search, orderby, order, per_page, page.
	 * @return array{rows:array<int,object>,total:int}
	 */
	public static function query( array $f ): array {
		global $wpdb;
		$t     = Db::table( 'issues' );
		$where = array( '1=1' );
		$args  = array();
		foreach ( array( 'status', 'rule_id' => 'rule', 'group_id' => 'group', 'severity', 'object_type' ) as $col => $key ) {
			$col = is_int( $col ) ? $key : $col;
			if ( ! empty( $f[ $key ] ) ) {
				$where[] = "{$col} = %s";
				$args[]  = (string) $f[ $key ];
			}
		}
		if ( ! empty( $f['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $f['search'] ) . '%';
			$where[] = '(details LIKE %s OR rule_id LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
		$whr   = implode( ' AND ', $where );
		$cols  = array( 'id', 'rule_id', 'severity', 'status', 'object_id', 'last_seen', 'first_seen' );
		$ob    = in_array( $f['orderby'] ?? '', $cols, true ) ? $f['orderby'] : 'id';
		$ord   = 'asc' === strtolower( (string) ( $f['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$per   = max( 1, min( 500, (int) ( $f['per_page'] ?? 20 ) ) );
		$off   = max( 0, ( (int) ( $f['page'] ?? 1 ) - 1 ) * $per );
		$count = "SELECT COUNT(*) FROM {$t} WHERE {$whr}";
		$list  = "SELECT * FROM {$t} WHERE {$whr} ORDER BY {$ob} {$ord} LIMIT %d OFFSET %d";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) ( $args ? \AdminKit\Helpers\Db::get_var( $count, $args ) : \AdminKit\Helpers\Db::get_var( $count ) );
		$rows  = (array) \AdminKit\Helpers\Db::get_results( $list, array_merge( $args, array( $per, $off ) ) );
		// phpcs:enable
		return array(
			'rows'  => array_map( array( self::class, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/** @return array<string,array<string,int>> rule_id → [object_type => açık sayısı] */
	public static function open_counts(): array {
		global $wpdb;
		$t   = Db::table( 'issues' );
		$out = array();
		foreach ( (array) \AdminKit\Helpers\Db::get_results( "SELECT rule_id, object_type, COUNT(*) c FROM {$t} WHERE status = 'open' GROUP BY rule_id, object_type" ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ $r->rule_id ][ $r->object_type ] = (int) $r->c;
		}
		return $out;
	}

	/** @param array<int,int> $ids */
	public static function set_status( array $ids, string $status ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! in_array( $status, array( 'open', 'ignored', 'fixed' ), true ) ) {
			return 0;
		}
		$t  = Db::table( 'issues' );
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		\AdminKit\Helpers\Db::query( "UPDATE {$t} SET status = %s WHERE id IN ({$ph})", array_merge( array( $status ), $ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = (int) $wpdb->rows_affected; // Score::forget() kendi sorgusunu çalıştırıp bu sayacı sıfırlayabilir.
		Score::forget();
		return $affected;
	}

	/** Bir kuralın açık bulgularının kimlikleri. @return array<int,object> */
	public static function open_for_rule( string $rule_id, array $only_ids = array(), int $limit = 5000 ): array {
		global $wpdb;
		$t    = Db::table( 'issues' );
		$sql  = "SELECT * FROM {$t} WHERE rule_id = %s AND status = 'open'";
		$args = array( $rule_id );
		if ( $only_ids ) {
			$only_ids = array_map( 'intval', $only_ids );
			$sql     .= ' AND object_id IN (' . implode( ',', array_fill( 0, count( $only_ids ), '%d' ) ) . ')';
			$args     = array_merge( $args, $only_ids );
		}
		$sql .= ' ORDER BY id ASC LIMIT %d';
		$args[] = $limit;
		return array_map( array( self::class, 'hydrate' ), (array) \AdminKit\Helpers\Db::get_results( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
