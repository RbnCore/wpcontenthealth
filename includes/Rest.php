<?php
/**
 * REST arayüzü (contenthealth/v1). Hepsi manage_options ister; tarayıcıda çerez + X-WP-Nonce, nonce'suz istek 401/403 alır.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public const NS = 'contenthealth/v1';

	public static function register(): void {
		$perm = array( self::class, 'can' );
		$post = \WP_REST_Server::CREATABLE;
		$get  = \WP_REST_Server::READABLE;
		register_rest_route( self::NS, '/status', array( 'methods' => $get, 'callback' => array( self::class, 'status' ), 'permission_callback' => $perm ) );
		register_rest_route(
			self::NS,
			'/scan',
			array(
				'methods'             => $post,
				'callback'            => array( self::class, 'scan' ),
				'permission_callback' => $perm,
				'args'                => array(
					'action' => array( 'type' => 'string', 'enum' => array( 'start', 'step', 'pause', 'resume', 'cancel' ), 'default' => 'step' ),
				),
			)
		);
		register_rest_route( self::NS, '/issues', array( 'methods' => $get, 'callback' => array( self::class, 'issues' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/issues/status', array( 'methods' => $post, 'callback' => array( self::class, 'issue_status' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/fix', array( 'methods' => $post, 'callback' => array( self::class, 'fix' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/issues/(?P<id>\d+)/detail', array( 'methods' => $get, 'callback' => array( self::class, 'issue_detail' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/pattern-test', array( 'methods' => $post, 'callback' => array( self::class, 'pattern_test' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/onboarding', array( 'methods' => $post, 'callback' => array( self::class, 'onboarding' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/undo', array( 'methods' => $post, 'callback' => array( self::class, 'undo' ), 'permission_callback' => $perm ) );
	}

	public static function can(): bool {
		return current_user_can( 'manage_options' );
	}

	/** @return array<string,mixed> */
	public static function summary(): array {
		$run  = Runs::active() ?: Runs::latest();
		$done = Runs::latest_finished();
		$open = Issues::open_counts();
		$sev  = array(
			Rule::CRITICAL => 0,
			Rule::WARNING  => 0,
			Rule::INFO     => 0,
		);
		$rules = array();
		foreach ( $open as $rid => $types ) {
			$rule = Registry::get( (string) $rid );
			$n    = array_sum( $types );
			if ( $rule ) {
				$sev[ $rule->severity() ] += $n;
				$rules[]                   = array(
					'id'       => $rid,
					'label'    => $rule->label(),
					'group'    => $rule->group(),
					'severity' => $rule->severity(),
					'open'     => $n,
					'fix'      => $rule->fix_action(),
				);
			}
		}
		usort( $rules, static fn( $a, $b ) => $b['open'] <=> $a['open'] );
		$live = $done ? Score::live() : null;
		return array(
			'run'      => $run ? Runs::progress( $run ) : null,
			'score'    => $live ? $live['error'] : null,
			'suggest'  => $live ? $live['suggest'] : null,
			'finished' => $done ? $done->finished_at : null,
			'severity' => $sev,
			'rules'    => $rules,
			'stats'    => $done ? $done->stats : null,
		);
	}

	public static function status(): \WP_REST_Response {
		return rest_ensure_response( self::summary() );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function scan( \WP_REST_Request $req ) {
		$action = (string) $req->get_param( 'action' );
		$run    = Runs::active();
		if ( 'start' === $action ) {
			$run = Runs::start(
				array(
					'rules'      => (array) $req->get_param( 'rules' ),
					'post_types' => (array) $req->get_param( 'post_types' ),
					'limit'      => (int) $req->get_param( 'limit' ),
				)
			);
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			$run = Scanner::step( $run->id );
		} elseif ( ! $run ) {
			$run = Runs::latest();
			return rest_ensure_response( array( 'run' => $run ? Runs::progress( $run ) : null ) );
		} elseif ( 'pause' === $action ) {
			Runs::pause( $run->id );
		} elseif ( 'resume' === $action ) {
			Runs::resume( $run->id );
			$run = Scanner::step( $run->id );
		} elseif ( 'cancel' === $action ) {
			Runs::cancel( $run->id );
		} else {
			$run = Scanner::step( $run->id );
		}
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$run = Runs::get( $run->id );
		return rest_ensure_response( array( 'run' => Runs::progress( $run ) ) + ( 'finished' === $run->status ? array( 'summary' => self::summary() ) : array() ) );
	}

	public static function issues( \WP_REST_Request $req ): \WP_REST_Response {
		$res = Issues::query( (array) $req->get_params() );
		$out = array();
		foreach ( $res['rows'] as $r ) {
			$out[] = array(
				'id'       => $r->id,
				'rule'     => $r->rule_id,
				'severity' => $r->severity,
				'status'   => $r->status,
				'type'     => $r->object_type,
				'object'   => $r->object_id,
				'details'  => $r->details_arr,
				'last'     => $r->last_seen,
			);
		}
		return rest_ensure_response(
			array(
				'total' => $res['total'],
				'rows'  => $out,
			)
		);
	}

	public static function issue_status( \WP_REST_Request $req ): \WP_REST_Response {
		$n = Issues::set_status( array_map( 'intval', (array) $req->get_param( 'ids' ) ), sanitize_key( (string) $req->get_param( 'status' ) ) );
		return rest_ensure_response( array( 'updated' => $n ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function fix( \WP_REST_Request $req ) {
		$opts = array();
		if ( null !== $req->get_param( 'alt_template' ) ) {
			$opts['alt_template'] = sanitize_text_field( (string) $req->get_param( 'alt_template' ) );
		}
		if ( null !== $req->get_param( 'canonical' ) ) {
			$opts['canonical'] = (int) $req->get_param( 'canonical' );
		}
		if ( null !== $req->get_param( 'keep_modified' ) ) {
			$opts['keep_modified'] = rest_sanitize_boolean( $req->get_param( 'keep_modified' ) );
		}
		$batch = (string) $req->get_param( 'batch' );
		if ( preg_match( '/^[a-f0-9]{16}$/', $batch ) ) {
			$opts['batch'] = $batch; // Sihirbaz birden çok kuralı tek geri alma yığınında toplar.
		}
		$res = Fixer::run_rule(
			sanitize_key( (string) $req->get_param( 'rule' ) ),
			array_map( 'intval', (array) $req->get_param( 'ids' ) ),
			self::is_dry( $req ),
			$opts
		);
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function issue_detail( \WP_REST_Request $req ) {
		$d = Screens\Issues::detail( (int) $req['id'] );
		return $d ? rest_ensure_response( $d ) : new \WP_Error( 'not_found', __( 'Bulgu bulunamadı.', 'contenthealth' ), array( 'status' => 404 ) );
	}

	/** Ayarlar ekranındaki desen sınaması: derleme hatası ya da eşleşme (ReDoS korumalı Patterns üzerinden). */
	public static function pattern_test( \WP_REST_Request $req ): \WP_REST_Response {
		$pattern = mb_substr( (string) $req->get_param( 'pattern' ), 0, 300 );
		$sample  = mb_substr( (string) $req->get_param( 'sample' ), 0, 2000 );
		$check   = Patterns::validate_list( array( $pattern ) );
		if ( $check['errors'] ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => $check['errors'][0] ) );
		}
		$m = array();
		return rest_ensure_response( array( 'ok' => true, 'match' => Patterns::match( $pattern, $sample, $m ) ? (string) $m[0] : null ) );
	}

	/** İlk açılış sihirbazı: kısmi ayar kaydı ve tamamlandı bayrağı. */
	public static function onboarding( \WP_REST_Request $req ): \WP_REST_Response {
		$in  = (array) $req->get_param( 'settings' );
		$res = $in ? Settings::save( $in ) : array( 'settings' => Settings::all(), 'errors' => array() );
		if ( rest_sanitize_boolean( $req->get_param( 'done' ) ) ) {
			update_option( 'contenthealth_onboarded', 1, false );
		}
		return rest_ensure_response( array( 'errors' => $res['errors'] ) );
	}

	public static function undo( \WP_REST_Request $req ): \WP_REST_Response {
		return rest_ensure_response( Undo::revert( sanitize_text_field( (string) $req->get_param( 'key' ) ), self::is_dry( $req ) ) );
	}

	/** Güvenli varsayılan: dry_run verilmediyse kuru koşu. */
	private static function is_dry( \WP_REST_Request $req ): bool {
		$v = $req->get_param( 'dry_run' );
		return ! ( false === $v || 'false' === $v || '0' === $v || 0 === $v );
	}
}
