<?php
/**
 * Sağlık puanı (0–100): ciddiyete göre ağırlıklı, kural başına tavanlı.
 *
 * Ceza(kural) = min(tavan, ağırlık × etkilenen nesnenin yüzdesi). Puan = 100 − Σ ceza (alt sınır 0).
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Score {

	public const CAP    = array(
		Rule::CRITICAL => 15.0,
		Rule::WARNING  => 8.0,
		Rule::INFO     => 3.0,
	);
	public const WEIGHT = array(
		Rule::CRITICAL => 1.5,
		Rule::WARNING  => 0.8,
		Rule::INFO     => 0.3,
	);

	/**
	 * @param array<string,array<string,int>> $open   rule_id → [tür → açık bulgu sayısı].
	 * @param array<string,int>               $totals tür → taranan nesne sayısı (post, term).
	 * @return array{score:int,penalties:array<string,float>}
	 */
	public static function compute( array $open, array $totals ): array {
		$pen = array();
		foreach ( $open as $rule_id => $by_type ) {
			$rule = Registry::get( (string) $rule_id );
			if ( ! $rule ) {
				continue;
			}
			$sev = $rule->severity();
			$p   = 0.0;
			foreach ( $by_type as $type => $n ) {
				$den = max( 1, (int) ( $totals[ $type ] ?? 0 ) );
				$p  += self::WEIGHT[ $sev ] * 100.0 * min( 1.0, $n / $den );
			}
			$pen[ $rule_id ] = round( min( self::CAP[ $sev ], $p ), 2 );
		}
		return array(
			'score'     => (int) max( 0, round( 100 - array_sum( $pen ) ) ),
			'penalties' => $pen,
		);
	}
}
