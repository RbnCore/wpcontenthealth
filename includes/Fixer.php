<?php
/**
 * Düzeltme yürütücüsü: kuru koşu (varsayılan) → uygula → geri alma günlüğü.
 *
 * Yazı değişiklikleri doğrudan `$wpdb->update` ile yapılır: post_modified DEĞİŞMEZ (ayar kapalıysa), revizyon üretilmez.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Fixer {

	/** Tek çağrı için "son değiştirilme tarihi korunsun" geçersiz kılması (null: ayar geçerli). */
	private static ?bool $keep_override = null;

	public const ACTIONS = array(
		'fix_ai_chat_residue',
		'fix_ai_ui_artifacts',
		'fix_markdown_links',
		'fix_latex_score',
		'fix_bad_host_url',
		'fix_alt_from_title',
		'fix_term_delete_empty',
		'fix_term_merge',
		'fix_term_noindex',
		'fix_term_rename',
	);

	/**
	 * Bir kuralın açık bulgularına eylemi uygular (tek yığın kimliğiyle).
	 *
	 * @param array<int,int>      $object_ids Boşsa tüm açık bulgular.
	 * @param array<string,mixed> $opts       alt_template, new_name vb.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run_rule( string $rule_id, array $object_ids, bool $dry_run, array $opts = array() ) {
		$rule = Registry::get( $rule_id );
		if ( ! $rule || ! $rule->fix_action() ) {
			return new \WP_Error( 'no_fix', __( 'There is no automatic fix for this rule.', 'contenthealth' ), array( 'status' => 400 ) );
		}
		$opts['batch'] = $opts['batch'] ?? ( $dry_run ? '' : Undo::new_batch() );
		$res           = array(
			'rule'     => $rule_id,
			'action'   => $rule->fix_action(),
			'dry_run'  => $dry_run,
			'batch'    => $opts['batch'],
			'items'    => array(),
			'changed'  => 0,
			'skipped'  => 0,
			'errors'   => 0,
		);
		foreach ( Issues::open_for_rule( $rule_id, $object_ids ) as $issue ) {
			$r = $rule->fix( $issue, $dry_run, $opts );
			if ( is_wp_error( $r ) ) {
				++$res['errors'];
				$res['items'][] = array(
					'object_id' => $issue->object_id,
					'error'     => $r->get_error_message(),
				);
				continue;
			}
			if ( empty( $r['changes'] ) ) {
				++$res['skipped'];
				continue;
			}
			++$res['changed'];
			if ( ! $dry_run ) {
				Issues::set_status( array( $issue->id ), 'fixed' ); // Sonraki tarama yeniden doğrular; kalıntı varsa yeniden açılır.
			}
			if ( count( $res['items'] ) < 200 ) {
				$res['items'][] = array_merge( array( 'object_id' => $issue->object_id ), $r );
			}
		}
		return $res;
	}

	/**
	 * Tek bulguya eylem uygular.
	 *
	 * @param array<string,mixed> $opts
	 * @return array{changes:array<int,array<string,mixed>>}|\WP_Error
	 */
	public static function run( string $action, object $issue, bool $dry, array $opts ) {
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error( 'bad_action', __( 'Unknown fix action.', 'contenthealth' ) );
		}
		$batch = (string) ( $opts['batch'] ?? '' );
		if ( ! $dry && '' === $batch ) {
			$batch = Undo::new_batch();
		}
		$ctx = new Ctx();
		self::$keep_override = isset( $opts['keep_modified'] ) ? (bool) $opts['keep_modified'] : null;
		try {
			return self::dispatch( $action, $issue, $dry, $batch, $opts, $ctx );
		} finally {
			self::$keep_override = null;
		}
	}

	/** @param array<string,mixed> $opts */
	private static function dispatch( string $action, object $issue, bool $dry, string $batch, array $opts, Ctx $ctx ) {
		switch ( $action ) {
			case 'fix_ai_chat_residue':
				$cfg = Settings::rule( 'ai_chat_residue' );
				return self::post_fields( $issue, $dry, $batch, $action, static fn( $h ) => Transform::chat_residue( $h, $cfg ), array( 'post_content', 'post_excerpt' ) );
			case 'fix_ai_ui_artifacts':
				return self::post_fields( $issue, $dry, $batch, $action, array( Transform::class, 'ui_artifacts' ), array( 'post_content', 'post_excerpt' ) );
			case 'fix_markdown_links':
				return self::post_fields( $issue, $dry, $batch, $action, array( Transform::class, 'markdown' ), array( 'post_content', 'post_excerpt' ) );
			case 'fix_latex_score':
				return self::post_fields( $issue, $dry, $batch, $action, array( Transform::class, 'latex_score' ), array( 'post_content', 'post_excerpt' ) );
			case 'fix_bad_host_url':
				return self::bad_host( $issue, $dry, $batch, $ctx );
			case 'fix_alt_from_title':
				$tpl  = (string) ( $opts['alt_template'] ?? Settings::get( 'alt_template' ) ?: __( '{title}', 'contenthealth' ) );
				$site = (string) get_bloginfo( 'name' );
				$post = get_post( $issue->object_id );
				$t    = $post ? $post->post_title : '';
				return self::post_fields( $issue, $dry, $batch, $action, static fn( $h ) => Transform::fill_alt( $h, $tpl, $t, $site ), array( 'post_content' ) );
			case 'fix_term_delete_empty':
				return self::term_delete( $issue, $dry, $batch );
			case 'fix_term_merge':
				return self::term_merge( $issue, $dry, $batch, $opts );
			case 'fix_term_noindex':
				return self::term_noindex( $issue, $dry, $batch );
			case 'fix_term_rename':
				return self::term_rename( $issue, $dry, $batch, $opts );
		}
		return array( 'changes' => array() );
	}

	/** Yazı alanlarına dönüştürücü uygular. */
	private static function post_fields( object $issue, bool $dry, string $batch, string $action, callable $fn, array $fields ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_content, post_excerpt, post_title FROM {$wpdb->posts} WHERE ID = %d", $issue->object_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			return new \WP_Error( 'gone', __( 'Post not found.', 'contenthealth' ) );
		}
		$changes = array();
		$update  = array();
		foreach ( $fields as $f ) {
			$before = (string) $row[ $f ];
			if ( '' === $before ) {
				continue;
			}
			$after = (string) $fn( $before );
			if ( $after === $before ) {
				continue;
			}
			$changes[]   = array_merge(
				array(
					'field'  => $f,
					'chars'  => array( strlen( $before ), strlen( $after ) ),
				),
				Transform::diff( $before, $after )
			);
			$update[ $f ] = array( $before, $after );
		}
		if ( $dry || ! $update ) {
			return array( 'changes' => $changes );
		}
		$data = array();
		foreach ( $update as $f => $pair ) {
			$data[ $f ] = $pair[1];
		}
		if ( ! ( self::$keep_override ?? (bool) Settings::get( 'keep_modified' ) ) ) {
			$data['post_modified']     = current_time( 'mysql' );
			$data['post_modified_gmt'] = current_time( 'mysql', true );
		}
		$ok = $wpdb->update( $wpdb->posts, $data, array( 'ID' => $issue->object_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $ok ) {
			return new \WP_Error( 'db', __( 'Could not update the post.', 'contenthealth' ) );
		}
		clean_post_cache( $issue->object_id );
		foreach ( $update as $f => $pair ) {
			Undo::record( $batch, $action, 'post', $issue->object_id, $f, $pair[0], $pair[1], $issue->id );
		}
		return array( 'changes' => $changes );
	}

	/** İçerik, özet ve yazı metasındaki hatalı sunucu adreslerini düzeltir. */
	private static function bad_host( object $issue, bool $dry, string $batch, Ctx $ctx ) {
		global $wpdb;
		$live = strtolower( trim( (string) Settings::get( 'live_host' ) ) );
		$dev  = array_map( 'strtolower', (array) Settings::rule( 'bad_host_url' )['dev_hosts'] );
		$res  = self::post_fields(
			$issue,
			$dry,
			$batch,
			'fix_bad_host_url',
			static function ( $h ) use ( $ctx, $live, $dev ) {
				return Transform::bad_hosts( $h, $ctx->home_host, $live, $dev )[0];
			},
			array( 'post_content', 'post_excerpt' )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$metas = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ('_edit_lock','_edit_last') AND LENGTH(meta_value) < 200000", $issue->object_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $metas as $m ) {
			$raw = (string) $m->meta_value;
			if ( ! preg_match( '~https?[:.]~i', $raw ) ) {
				continue;
			}
			$val = maybe_unserialize( $raw );
			$new = self::walk_strings( $val, static fn( $s ) => Transform::bad_hosts( $s, $ctx->home_host, $live, $dev )[0] );
			$out = is_string( $val ) && $val === $raw ? (string) $new : maybe_serialize( $new );
			if ( $out === $raw ) {
				continue;
			}
			$res['changes'][] = array_merge(
				array(
					'field' => 'postmeta:' . $m->meta_key,
					'chars' => array( strlen( $raw ), strlen( $out ) ),
				),
				Transform::diff( $raw, $out )
			);
			if ( ! $dry ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $out ), array( 'meta_id' => (int) $m->meta_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $issue->object_id );
				Undo::record( $batch, 'fix_bad_host_url', 'post', $issue->object_id, 'postmeta:' . (int) $m->meta_id, $raw, $out, $issue->id );
			}
		}
		return $res;
	}

	/**
	 * Serileştirme güvenli dolaşma: dizi/nesne içindeki dizgilere uygular, yapıyı korur.
	 *
	 * @param mixed $v
	 * @return mixed
	 */
	public static function walk_strings( $v, callable $fn ) {
		if ( is_string( $v ) ) {
			return $fn( $v );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::walk_strings( $x, $fn );
			}
			return $v;
		}
		if ( is_object( $v ) && ! ( $v instanceof \__PHP_Incomplete_Class ) ) {
			foreach ( get_object_vars( $v ) as $k => $x ) {
				$v->$k = self::walk_strings( $x, $fn );
			}
		}
		return $v;
	}

	private static function term_delete( object $issue, bool $dry, string $batch ) {
		$r = TermOps::delete_empty( $issue->object_id, $dry );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $dry ) {
			Undo::record( $batch, 'fix_term_delete_empty', 'term', $issue->object_id, 'term_delete', $r['before'], $r['after'], $issue->id );
		}
		return array(
			'changes' => array(
				array(
					'field'   => 'term_delete',
					'removed' => array( (string) ( $issue->details_arr['name'] ?? $issue->object_id ) ),
					'added'   => array(),
				),
			),
		);
	}

	private static function term_merge( object $issue, bool $dry, string $batch, array $opts = array() ) {
		$canon = (int) ( $issue->details_arr['canonical'] ?? 0 );
		$pick  = (int) ( $opts['canonical'] ?? 0 );
		if ( $pick && in_array( $pick, array_map( 'intval', (array) ( $issue->details_arr['group'] ?? array() ) ), true ) ) {
			$canon = $pick; // Kullanıcının kümede seçtiği kanonik etiket.
		}
		if ( ! $canon || $canon === $issue->object_id ) {
			return array( 'changes' => array() ); // Kanonik terim kendisi: dokunma.
		}
		$r = TermOps::merge( $issue->object_id, $canon, $dry );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $dry ) {
			Undo::record( $batch, 'fix_term_merge', 'term', $issue->object_id, 'term_merge', $r['before'], $r['after'], $issue->id );
		}
		$to = get_term( $canon );
		return array(
			'changes' => array(
				array(
					'field'   => 'term_merge',
					'removed' => array( (string) ( $issue->details_arr['name'] ?? $issue->object_id ) ),
					'added'   => array( sprintf( __( '→ %s (%d relations will be moved)', 'contenthealth' ), $to && ! is_wp_error( $to ) ? $to->name : $canon, $r['moved'] ) ),
				),
			),
		);
	}

	private static function term_noindex( object $issue, bool $dry, string $batch ) {
		$seo = Seo::active_plugin();
		if ( '' !== $seo ) {
			return new \WP_Error(
				'seo_plugin',
				sprintf(
					/* translators: %s: SEO plugin id */
					__( 'An active SEO plugin (%s) is present; set noindex there. Content Health left it alone to avoid a conflict.', 'contenthealth' ),
					$seo
				)
			);
		}
		$cur = array_values( array_map( 'intval', (array) get_option( 'contenthealth_noindex_terms', array() ) ) );
		if ( in_array( $issue->object_id, $cur, true ) ) {
			return array( 'changes' => array() );
		}
		$new = array_values( array_unique( array_merge( $cur, array( $issue->object_id ) ) ) );
		if ( ! $dry ) {
			update_option( 'contenthealth_noindex_terms', $new, false );
			Undo::record( $batch, 'fix_term_noindex', 'term', $issue->object_id, 'noindex_terms', (string) wp_json_encode( $cur ), (string) wp_json_encode( $new ), $issue->id );
		}
		return array(
			'changes' => array(
				array(
					'field'   => 'noindex',
					'removed' => array(),
					'added'   => array( 'robots: noindex, follow — ' . (string) ( $issue->details_arr['name'] ?? $issue->object_id ) ),
				),
			),
		);
	}

	private static function term_rename( object $issue, bool $dry, string $batch, array $opts ) {
		global $wpdb;
		$cur = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->terms} WHERE term_id = %d", $issue->object_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null === $cur ) {
			return new \WP_Error( 'gone', __( 'Term not found.', 'contenthealth' ) );
		}
		$new = isset( $opts['new_name'] ) && '' !== trim( (string) $opts['new_name'] )
			? sanitize_text_field( (string) $opts['new_name'] )
			: ( 'term_ai_residue' === $issue->rule_id ? Transform::clean_term_name( (string) $cur ) : null );
		if ( null === $new || '' === $new ) {
			return new \WP_Error( 'need_name', __( 'Could not generate a name for this term automatically; enter a new name by hand.', 'contenthealth' ) );
		}
		if ( $new === $cur ) {
			return array( 'changes' => array() );
		}
		if ( ! $dry ) {
			$wpdb->update( $wpdb->terms, array( 'name' => $new ), array( 'term_id' => $issue->object_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_term_cache( $issue->object_id );
			Undo::record( $batch, 'fix_term_rename', 'term', $issue->object_id, 'term_name', (string) $cur, $new, $issue->id );
		}
		return array(
			'changes' => array(
				array(
					'field'   => 'term_name',
					'removed' => array( Text::snippet( (string) $cur, 200 ) ),
					'added'   => array( $new ),
				),
			),
		);
	}
}
