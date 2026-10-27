<?php
/**
 * Geri alma günlüğü. Her düzeltme önce/sonra değeri ve SHA-256 özetiyle yazılır;
 * geri alırken mevcut değer "sonra" özetine eşitse geri yazılır (araya giren elle düzenleme ezilmez).
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Undo {

	public static function new_batch(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	public static function record( string $batch, string $action, string $type, int $id, string $field, string $before, string $after, int $issue_id = 0 ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'undo' ),
			array(
				'batch_id'     => $batch,
				'action'       => $action,
				'object_type'  => $type,
				'object_id'    => $id,
				'field'        => $field,
				'before_value' => $before,
				'after_value'  => $after,
				'before_hash'  => Text::hash( $before ),
				'after_hash'   => Text::hash( $after ),
				'issue_id'     => $issue_id,
				'user_id'      => get_current_user_id(),
				'created_at'   => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<int,object> son yığınlar (geri alınmamış satır sayısıyla) */
	public static function batches( int $n = 20 ): array {
		global $wpdb;
		$t = Db::table( 'undo' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT batch_id, MIN(action) action, COUNT(*) items, SUM(undone_at IS NULL) pending, MIN(created_at) created_at, MIN(user_id) user_id FROM {$t} GROUP BY batch_id ORDER BY MIN(id) DESC LIMIT %d", $n ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<int,object> */
	public static function rows( string $key ): array {
		global $wpdb;
		$t = Db::table( 'undo' );
		if ( ctype_digit( $key ) ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE batch_id = %s ORDER BY id DESC", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Yığını (ya da tek satırı) geri alır.
	 *
	 * @return array{reverted:int,skipped:int,messages:array<int,string>}
	 */
	public static function revert( string $key, bool $dry_run = false ): array {
		global $wpdb;
		$out = array(
			'reverted' => 0,
			'skipped'  => 0,
			'messages' => array(),
		);
		foreach ( self::rows( $key ) as $row ) {
			if ( null !== $row->undone_at ) {
				continue;
			}
			$ok = self::revert_row( $row, $dry_run );
			if ( true === $ok ) {
				++$out['reverted'];
				if ( ! $dry_run ) {
					$wpdb->update( Db::table( 'undo' ), array( 'undone_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			} else {
				++$out['skipped'];
				$out['messages'][] = sprintf( '#%d %s: %s', (int) $row->id, $row->field, (string) $ok );
			}
		}
		return $out;
	}

	/** @return true|string true: geri alınabilir/alındı, metin: atlama nedeni */
	private static function revert_row( object $row, bool $dry ) {
		global $wpdb;
		$id    = (int) $row->object_id;
		$field = (string) $row->field;
		if ( in_array( $field, array( 'post_content', 'post_excerpt', 'post_title' ), true ) ) {
			$cur = $wpdb->get_var( $wpdb->prepare( "SELECT {$field} FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- alan adı beyaz listeden.
			if ( null === $cur ) {
				return __( 'yazı yok', 'contenthealth' );
			}
			if ( Text::hash( (string) $cur ) !== $row->after_hash ) {
				return __( 'içerik sonradan değişmiş', 'contenthealth' );
			}
			if ( ! $dry ) {
				$wpdb->update( $wpdb->posts, array( $field => $row->before_value ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $id );
			}
			return true;
		}
		if ( 0 === strpos( $field, 'postmeta:' ) ) {
			$mid = (int) substr( $field, 9 );
			$cur = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $mid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( null === $cur ) {
				return __( 'meta satırı yok', 'contenthealth' );
			}
			if ( Text::hash( (string) $cur ) !== $row->after_hash ) {
				return __( 'meta sonradan değişmiş', 'contenthealth' );
			}
			if ( ! $dry ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $row->before_value ), array( 'meta_id' => $mid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				wp_cache_delete( $id, 'post_meta' );
			}
			return true;
		}
		if ( 'attachment_alt_new' === $field ) {
			$cur = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( Text::hash( $cur ) !== $row->after_hash ) {
				return __( 'alt metin sonradan değişmiş', 'contenthealth' );
			}
			if ( ! $dry ) {
				delete_post_meta( $id, '_wp_attachment_image_alt' );
			}
			return true;
		}
		if ( 'term_name' === $field ) {
			$cur = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->terms} WHERE term_id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( null === $cur ) {
				return __( 'terim yok', 'contenthealth' );
			}
			if ( Text::hash( (string) $cur ) !== $row->after_hash ) {
				return __( 'terim sonradan değişmiş', 'contenthealth' );
			}
			if ( ! $dry ) {
				$wpdb->update( $wpdb->terms, array( 'name' => $row->before_value ), array( 'term_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_term_cache( $id );
			}
			return true;
		}
		if ( 'term_delete' === $field || 'term_merge' === $field ) {
			return TermOps::revert( $row, $dry );
		}
		if ( 'noindex_terms' === $field ) {
			$cur = (string) wp_json_encode( array_values( (array) get_option( 'contenthealth_noindex_terms', array() ) ) );
			if ( Text::hash( $cur ) !== $row->after_hash ) {
				return __( 'liste sonradan değişmiş', 'contenthealth' );
			}
			if ( ! $dry ) {
				update_option( 'contenthealth_noindex_terms', json_decode( (string) $row->before_value, true ) ?: array(), false );
			}
			return true;
		}
		return __( 'bilinmeyen alan', 'contenthealth' );
	}
}
