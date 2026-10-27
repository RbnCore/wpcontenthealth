<?php
/**
 * İki puan (0–100): Hata puanı (kritik + uyarı) ve Öneri puanı (bilgi); ciddiyete göre ağırlıklı, kural başına tavanlı.
 *
 * Ceza(kural) = min(tavan, ağırlık × etkilenen nesnenin yüzdesi). Puan = 100 − Σ ceza (alt sınır 0).
 * Canlı puan açık bulgu sayılarından hesaplanır (tek SQL sayımı + 60 sn önbellek); bulgu durumu değişince önbellek silinir.
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

	public const TRANSIENT = 'contenthealth_live_score';
	public const TTL       = 60;

	/**
	 * @param array<string,array<string,int>> $open   rule_id → [tür → açık bulgu sayısı].
	 * @param array<string,int>               $totals tür → taranan nesne sayısı (post, term).
	 * @return array{error:int,suggest:int,all:int,penalties:array<string,float>}
	 *         error = yalnız kritik+uyarı, suggest = yalnız bilgi, all = üçü birden (grup kartları).
	 */
	public static function compute( array $open, array $totals ): array {
		$pen = array();
		$sum = array(
			'error'   => 0.0,
			'suggest' => 0.0,
		);
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
			$p               = round( min( self::CAP[ $sev ], $p ), 2 );
			$pen[ $rule_id ] = $p;
			$sum[ Rule::INFO === $sev ? 'suggest' : 'error' ] += $p;
		}
		return array(
			'error'     => self::points( $sum['error'] ),
			'suggest'   => self::points( $sum['suggest'] ),
			'all'       => self::points( $sum['error'] + $sum['suggest'] ),
			'penalties' => $pen,
		);
	}

	private static function points( float $penalty ): int {
		return (int) max( 0, round( 100 - $penalty ) );
	}

	/**
	 * Şu anki açık bulgulardan canlı puan. Payda: son bitmiş taramanın nesne sayıları.
	 *
	 * @return array{error:int,suggest:int,all:int,penalties:array<string,float>}
	 */
	public static function live(): array {
		$hit = get_transient( self::TRANSIENT );
		if ( is_array( $hit ) && isset( $hit['error'], $hit['suggest'] ) ) {
			return $hit;
		}
		$done = Runs::latest_finished();
		$sc   = self::compute(
			Issues::open_counts(),
			array(
				'post' => (int) ( $done->stats['posts'] ?? 0 ),
				'term' => (int) ( $done->stats['terms'] ?? 0 ),
			)
		);
		set_transient( self::TRANSIENT, $sc, self::TTL );
		return $sc;
	}

	/** Bulgu durumu değişince çağrılır: bir sonraki okuma yeniden hesaplar. */
	public static function forget(): void {
		delete_transient( self::TRANSIENT );
	}
}
