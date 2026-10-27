<?php
/**
 * WP-Cron: saatlik / günlük / haftalık otomatik tarama; uzun koşular 10 sn arayla sürdürülür.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Cron {

	public const MAP = array(
		'hourly' => 'hourly',
		'daily'  => 'daily',
		'weekly' => 'contenthealth_weekly',
	);

	/** Ayara göre yinelenen olayı kurar/kaldırır (etkisiz çağrı güvenli). */
	public static function schedule(): void {
		$freq = (string) Settings::get( 'frequency' );
		$next = wp_next_scheduled( 'contenthealth_cron_scan' );
		$want = self::MAP[ $freq ] ?? '';
		if ( $next && $want !== (string) wp_get_schedule( 'contenthealth_cron_scan' ) ) {
			wp_clear_scheduled_hook( 'contenthealth_cron_scan' );
			$next = false;
		}
		if ( $want && ! $next ) {
			wp_schedule_event( time() + 300, $want, 'contenthealth_cron_scan' );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( 'contenthealth_cron_scan' );
		wp_clear_scheduled_hook( 'contenthealth_cron_continue' );
	}

	/** Cron kancası: koşu yoksa başlat, varsa sürdür; bitmediyse 10 sn sonrası için tekrar zamanla. */
	public static function run(): void {
		Plugin::boot();
		$run = Runs::active();
		if ( ! $run ) {
			$run = Runs::start( array(), 'cron' );
			if ( is_wp_error( $run ) ) {
				return;
			}
		}
		if ( 'running' !== $run->status ) {
			return;
		}
		$run = Scanner::step( $run->id );
		if ( ! is_wp_error( $run ) && 'running' === $run->status && ! wp_next_scheduled( 'contenthealth_cron_continue' ) ) {
			wp_schedule_single_event( time() + 10, 'contenthealth_cron_continue' );
		}
	}
}
