<?php
/**
 * Kural taban sınıfı.
 *
 * Nesne bazlı kural: scopes() + check(). Küme bazlı (global) kural: scope 'global' + scan_batch().
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

abstract class Rule {

	public const CRITICAL = 'critical';
	public const WARNING  = 'warning';
	public const INFO     = 'info';

	abstract public function id(): string;

	/** content | seo | media | links | taxonomy */
	abstract public function group(): string;

	abstract public function severity(): string;

	abstract public function label(): string;

	abstract public function description(): string;

	/** @return array<int,string> post | attachment | term | global */
	public function scopes(): array {
		return array( 'post' );
	}

	/** Bu kural için sunulan düzeltme eylemi (yoksa null = yalnız elle). */
	public function fix_action(): ?string {
		return null;
	}

	/** @return array<string,mixed> */
	public function defaults(): array {
		return array( 'enabled' => true );
	}

	/** @return array<string,mixed> */
	public function config(): array {
		return Settings::rule( $this->id() );
	}

	public function enabled(): bool {
		return ! empty( $this->config()['enabled'] );
	}

	/**
	 * Nesne denetimi.
	 *
	 * @param object $obj PostView | stdClass (terim / ek).
	 * @return array<string,mixed>|null Bulgu ayrıntısı; sağlamsa null.
	 */
	public function check( object $obj, Ctx $ctx ): ?array {
		return null;
	}

	/**
	 * Küme bazlı kural partisi.
	 *
	 * @param array<string,mixed> $cursor Kalıcı imleç.
	 * @return array{findings:array<int,array{0:string,1:int,2:array<string,mixed>}>,cursor:array<string,mixed>,done:bool,units:int}
	 */
	public function scan_batch( array $cursor, int $limit, Ctx $ctx ): array {
		return array(
			'findings' => array(),
			'cursor'   => $cursor,
			'done'     => true,
			'units'    => 0,
		);
	}

	/**
	 * Düzeltme: Fixer'a devreder.
	 *
	 * @param array<string,mixed> $opts Eylem seçenekleri.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function fix( object $issue, bool $dry_run, array $opts = array() ) {
		$action = (string) ( $opts['fix'] ?? $this->fix_action() );
		if ( '' === $action ) {
			return new \WP_Error( 'no_fix', __( 'There is no automatic fix for this rule.', 'contenthealth' ) );
		}
		return Fixer::run( $action, $issue, $dry_run, $opts );
	}

	/**
	 * Kural ayarlarını temizler (kural sınıfı kendi alanlarını genişletir).
	 *
	 * @param array<string,mixed> $in  Ham girdi.
	 * @param array<string,mixed> $cur Mevcut ayar.
	 * @return array{config:array<string,mixed>,errors:array<int,string>}
	 */
	public function sanitize( array $in, array $cur ): array {
		$errors = array();
		$def    = $this->defaults();
		foreach ( $def as $k => $dv ) {
			if ( 'enabled' === $k ) {
				if ( array_key_exists( 'enabled', $in ) ) {
					$cur['enabled'] = ! empty( $in['enabled'] );
				}
				continue;
			}
			if ( ! array_key_exists( $k, $in ) ) {
				continue;
			}
			if ( is_array( $dv ) ) {
				$is_pat = 'patterns' === substr( $k, 0, 8 ) || 'pat_' === substr( $k, 0, 4 );
				$list   = Settings::lines( $in[ $k ], ! $is_pat );
				if ( $is_pat ) {
					$v      = Patterns::validate_list( $list );
					$list   = $v['valid'];
					$errors = array_merge( $errors, $v['errors'] );
				}
				$cur[ $k ] = array_slice( array_map( static fn( $x ) => mb_substr( (string) $x, 0, Patterns::MAX_LEN ), $list ), 0, 100 );
			} elseif ( is_bool( $dv ) ) {
				$cur[ $k ] = (bool) $in[ $k ];
			} elseif ( is_int( $dv ) ) {
				$cur[ $k ] = max( 0, min( 100000, (int) $in[ $k ] ) );
			} else {
				$cur[ $k ] = sanitize_text_field( (string) $in[ $k ] );
			}
		}
		return array(
			'config' => $cur,
			'errors' => $errors,
		);
	}

	/** Kısa yardımcı: bulgu ayrıntısı biçimi. */
	protected function found( array $items, array $extra = array() ): array {
		return array_merge(
			array(
				'count' => count( $items ),
				'items' => array_slice( $items, 0, 8 ),
			),
			$extra
		);
	}
}
