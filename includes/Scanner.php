<?php
/**
 * Tarama motoru: aşamalı, partili, kalıcı imleçli. Her adım zaman bütçesine (varsayılan 5 sn) uyar.
 *
 * Aşamalar: post (yazı kuralları) → term (terim kuralları) → global:<kural> (küme kuralları).
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Scanner {

	private const LOCK = 'contenthealth_step';

	/** Koşuyu kurar: aşamalar, toplam iş ve imleç. */
	public static function init( object $run ): object {
		global $wpdb;
		$run    = Runs::get( (int) $run->id );
		$ctx    = new Ctx();
		$scope  = $run->scope;
		$only   = (array) $scope['rules'];
		$rules  = Registry::enabled( $only );
		$stages = array();
		$has    = static function ( string $s ) use ( $rules ): bool {
			foreach ( $rules as $r ) {
				if ( in_array( $s, $r->scopes(), true ) ) {
					return true;
				}
			}
			return false;
		};
		$types    = $scope['post_types'] ?: $ctx->post_types();
		$statuses = $ctx->statuses();
		$posts    = 0;
		$terms    = 0;
		if ( $has( 'post' ) && $types && $statuses ) {
			$posts = self::count_posts( $types, $statuses );
			if ( $scope['limit'] > 0 ) {
				$posts = min( $posts, (int) $scope['limit'] );
			}
			$stages[] = array( 't' => 'post' );
		}
		$tax = self::taxonomies();
		if ( $has( 'term' ) && $tax ) {
			$terms    = self::count_terms( $tax );
			$stages[] = array( 't' => 'term' );
		}
		foreach ( $rules as $id => $r ) {
			if ( in_array( 'global', $r->scopes(), true ) ) {
				$stages[] = array(
					't'    => 'global',
					'rule' => $id,
				);
			}
		}
		// Küme kuralları için bölen: yazı ve terim sayısı bağımsız hesaplanır (puan paydası).
		$all_posts = $posts;
		if ( ! $all_posts && $types && $statuses ) {
			$all_posts = self::count_posts( $types, $statuses );
		}
		Runs::update(
			$run->id,
			array(
				'total'  => $posts + $terms + count( array_filter( $stages, static fn( $s ) => 'global' === $s['t'] ) ),
				'state'  => array(
					'stages'   => $stages,
					'i'        => 0,
					'label'    => $stages ? self::label( $stages[0] ) : '',
					'types'    => array_values( $types ),
					'tax'      => $tax,
					'partial'  => ( $scope['limit'] > 0 || $scope['post_types'] ),
					'rules'    => array_keys( $rules ),
					'started'  => microtime( true ),
					'elapsed'  => 0.0,
				),
				'cursor' => array( 'last' => 0 ),
				'stats'  => array(
					'posts' => $all_posts ?: $posts,
					'terms' => $terms ?: self::count_terms( $tax ),
				),
			)
		);
		if ( ! $stages ) {
			self::finish( Runs::get( $run->id ) );
		}
		return Runs::get( $run->id );
	}

	/** @return array<int,string> */
	public static function taxonomies(): array {
		$list = (array) Settings::get( 'term_taxonomies' );
		return array_values( array_filter( $list, 'taxonomy_exists' ) );
	}

	private static function label( array $stage ): string {
		if ( 'post' === $stage['t'] ) {
			return __( 'Posts', 'contenthealth' );
		}
		if ( 'term' === $stage['t'] ) {
			return __( 'Tags and categories', 'contenthealth' );
		}
		$rule = Registry::get( (string) $stage['rule'] );
		return $rule ? $rule->label() : (string) $stage['rule'];
	}

	/** @param array<int,string> $types @param array<int,string> $statuses */
	private static function count_posts( array $types, array $statuses ): int {
		global $wpdb;
		$pt = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ps = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$pt}) AND post_status IN ({$ps})", array_merge( $types, $statuses ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param array<int,string> $tax */
	private static function count_terms( array $tax ): int {
		global $wpdb;
		if ( ! $tax ) {
			return 0;
		}
		$ph = implode( ',', array_fill( 0, count( $tax ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ({$ph})", $tax ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Zaman bütçesi dolana ya da koşu bitene dek çalışır. Eşzamanlı adımlar MySQL kilidiyle sıraya girer.
	 *
	 * @return object|\WP_Error Güncel koşu.
	 */
	public static function step( int $run_id, float $budget = 0.0 ) {
		global $wpdb;
		$run = Runs::get( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'no_run', __( 'Run not found.', 'contenthealth' ), array( 'status' => 404 ) );
		}
		if ( 'running' !== $run->status ) {
			return $run;
		}
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK . '_' . $wpdb->prefix ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $run; // Başka bir adım çalışıyor; durumu olduğu gibi döndür.
		}
		try {
			$budget = $budget > 0 ? $budget : (float) max( 1, min( 25, (int) Settings::get( 'time_budget' ) ) );
			$t0     = microtime( true );
			$ctx    = new Ctx();
			$run    = Runs::get( $run_id );
			$state  = $run->state;
			$cursor = $run->cursor;
			$done   = $run->done;
			$limit  = max( 10, min( 1000, (int) Settings::get( 'batch_size' ) ) );
			while ( 'running' === $run->status && $state['i'] < count( $state['stages'] ) && microtime( true ) - $t0 < $budget ) {
				$stage = $state['stages'][ $state['i'] ];
				if ( 'post' === $stage['t'] ) {
					$res = self::post_batch( $run, $ctx, $cursor, $limit, $t0 + $budget );
				} elseif ( 'term' === $stage['t'] ) {
					$res = self::term_batch( $run, $ctx, $cursor, $limit, $t0 + $budget );
				} else {
					$res = self::global_batch( $run, $ctx, $stage, $cursor, $limit );
				}
				$cursor = $res['cursor'];
				$done  += $res['units'];
				if ( $res['done'] ) {
					self::close_stage( $run, $stage );
					++$state['i'];
					$cursor = array( 'last' => 0 );
					if ( $state['i'] < count( $state['stages'] ) ) {
						$state['label'] = self::label( $state['stages'][ $state['i'] ] );
					}
				}
				$state['elapsed'] = round( (float) $state['elapsed'] + 0, 3 );
				Runs::update(
					$run_id,
					array(
						'done'   => $done,
						'cursor' => $cursor,
						'state'  => $state,
					)
				);
				$run = Runs::get( $run_id );
				if ( 'running' !== $run->status ) {
					break;
				}
			}
			if ( 'running' === $run->status && $state['i'] >= count( $state['stages'] ) ) {
				self::finish( $run );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK . '_' . $wpdb->prefix ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return Runs::get( $run_id );
	}

	/** @return array{cursor:array<string,mixed>,units:int,done:bool} */
	private static function post_batch( object $run, Ctx $ctx, array $cursor, int $limit, float $deadline ): array {
		global $wpdb;
		$types    = (array) $run->state['types'];
		$statuses = $ctx->statuses();
		$rules    = array();
		foreach ( Registry::enabled( (array) $run->state['rules'] ) as $id => $r ) {
			if ( in_array( 'post', $r->scopes(), true ) ) {
				$rules[ $id ] = $r;
			}
		}
		$cap = (int) $run->scope['limit'];
		$max = $cap > 0 ? max( 0, $cap - (int) ( $cursor['n'] ?? 0 ) ) : $limit;
		$lim = min( $limit, $max );
		if ( $lim <= 0 ) {
			return array( 'cursor' => $cursor, 'units' => 0, 'done' => true );
		}
		$pt   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ps   = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_title, post_content, post_excerpt, post_name, post_parent FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$pt}) AND post_status IN ({$ps}) ORDER BY ID ASC LIMIT %d", array_merge( array( (int) $cursor['last'] ), $types, $statuses, array( $lim ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$units = 0;
		foreach ( $rows as $row ) {
			$view = new PostView( $row );
			foreach ( $rules as $id => $rule ) {
				try {
					$d = $rule->check( $view, $ctx );
				} catch ( \Throwable $e ) {
					$d = null; // Tek kuralın hatası taramayı durdurmaz.
					\AdminKit\Core\Log::log( 'contenthealth.errors', 'error', (string) $id, $e->getMessage(), array( 'file' => basename( $e->getFile() ), 'line' => $e->getLine() ) );
				}
				if ( null !== $d ) {
					Issues::upsert( $run->id, 'post', $view->id, $rule, $d );
				}
			}
			$cursor['last'] = (int) $row->ID;
			$cursor['n']    = (int) ( $cursor['n'] ?? 0 ) + 1;
			++$units;
			if ( microtime( true ) > $deadline ) {
				return array( 'cursor' => $cursor, 'units' => $units, 'done' => false );
			}
		}
		$finished = count( $rows ) < $lim || ( $cap > 0 && (int) $cursor['n'] >= $cap );
		return array( 'cursor' => $cursor, 'units' => $units, 'done' => $finished );
	}

	/** @return array{cursor:array<string,mixed>,units:int,done:bool} */
	private static function term_batch( object $run, Ctx $ctx, array $cursor, int $limit, float $deadline ): array {
		global $wpdb;
		$tax   = (array) $run->state['tax'];
		$rules = array();
		foreach ( Registry::enabled( (array) $run->state['rules'] ) as $id => $r ) {
			if ( in_array( 'term', $r->scopes(), true ) ) {
				$rules[ $id ] = $r;
			}
		}
		$ph    = implode( ',', array_fill( 0, count( $tax ), '%s' ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.count, tt.parent FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id > %d AND tt.taxonomy IN ({$ph}) ORDER BY t.term_id ASC LIMIT %d", array_merge( array( (int) $cursor['last'] ), $tax, array( $limit ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$units = 0;
		foreach ( $rows as $row ) {
			$obj = (object) array(
				'id'       => (int) $row->term_id,
				'term_id'  => (int) $row->term_id,
				'name'     => html_entity_decode( (string) $row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'slug'     => (string) $row->slug,
				'taxonomy' => (string) $row->taxonomy,
				'count'    => (int) $row->count,
				'type'     => 'term',
			);
			foreach ( $rules as $id => $rule ) {
				try {
					$d = $rule->check( $obj, $ctx );
				} catch ( \Throwable $e ) {
					$d = null;
					\AdminKit\Core\Log::log( 'contenthealth.errors', 'error', (string) $id, $e->getMessage(), array( 'file' => basename( $e->getFile() ), 'line' => $e->getLine() ) );
				}
				if ( null !== $d ) {
					$d['name']     = $d['name'] ?? Text::snippet( $obj->name, 100 );
					$d['taxonomy'] = $obj->taxonomy;
					Issues::upsert( $run->id, 'term', $obj->id, $rule, $d );
				}
			}
			$cursor['last'] = (int) $row->term_id;
			++$units;
			if ( microtime( true ) > $deadline ) {
				return array( 'cursor' => $cursor, 'units' => $units, 'done' => false );
			}
		}
		return array( 'cursor' => $cursor, 'units' => $units, 'done' => count( $rows ) < $limit );
	}

	/** @return array{cursor:array<string,mixed>,units:int,done:bool} */
	private static function global_batch( object $run, Ctx $ctx, array $stage, array $cursor, int $limit ): array {
		$rule = Registry::get( (string) $stage['rule'] );
		if ( ! $rule ) {
			return array( 'cursor' => $cursor, 'units' => 1, 'done' => true );
		}
		try {
			$res = $rule->scan_batch( $cursor, $limit, $ctx );
		} catch ( \Throwable $e ) {
			\AdminKit\Core\Log::log( 'contenthealth.errors', 'error', $rule->id(), $e->getMessage(), array( 'file' => basename( $e->getFile() ), 'line' => $e->getLine() ) );
			return array( 'cursor' => $cursor, 'units' => 1, 'done' => true );
		}
		foreach ( $res['findings'] as $f ) {
			Issues::upsert( $run->id, $f[0], (int) $f[1], $rule, $f[2] );
		}
		$done = (bool) $res['done'];
		return array(
			'cursor' => (array) $res['cursor'],
			'units'  => $done ? 1 : (int) $res['units'],
			'done'   => $done,
		);
	}

	/** Aşama bitince görülmeyen açık bulguları "düzeltildi" yap (kısmi taramada yalnız küme kuralları). */
	private static function close_stage( object $run, array $stage ): void {
		$partial = ! empty( $run->state['partial'] );
		if ( 'global' === $stage['t'] ) {
			foreach ( array( 'post', 'term' ) as $type ) {
				Issues::sweep( $run->id, $type, array( (string) $stage['rule'] ) );
			}
			return;
		}
		if ( $partial && 'post' === $stage['t'] ) {
			return;
		}
		$ids = array();
		foreach ( Registry::enabled( (array) $run->state['rules'] ) as $id => $r ) {
			if ( in_array( $stage['t'], $r->scopes(), true ) ) {
				$ids[] = $id;
			}
		}
		Issues::sweep( $run->id, $stage['t'], $ids );
	}

	private static function finish( object $run ): void {
		$open   = Issues::open_counts();
		$totals = array(
			'post' => (int) ( $run->stats['posts'] ?? 0 ),
			'term' => (int) ( $run->stats['terms'] ?? 0 ),
		);
		$sc     = Score::compute( $open, $totals );
		$by_sev = array(
			Rule::CRITICAL => 0,
			Rule::WARNING  => 0,
			Rule::INFO     => 0,
		);
		$by_rule = array();
		foreach ( $open as $rid => $types ) {
			$rule = Registry::get( (string) $rid );
			$n    = array_sum( $types );
			if ( $rule ) {
				$by_sev[ $rule->severity() ] += $n;
			}
			$by_rule[ $rid ] = $n;
		}
		$stats = array_merge(
			$run->stats,
			array(
				'severity'      => $by_sev,
				'rules'         => $by_rule,
				'penalties'     => $sc['penalties'],
				'suggest_score' => $sc['suggest'],
				'seconds'       => round( microtime( true ) - (float) ( $run->state['started'] ?? microtime( true ) ), 2 ),
				'peak_mem'      => memory_get_peak_usage( true ),
			)
		);
		Runs::update(
			$run->id,
			array(
				'status'      => 'finished',
				'score'       => $sc['error'],
				'stats'       => $stats,
				'finished_at' => current_time( 'mysql', true ),
			)
		);
		Score::forget();
	}
}
