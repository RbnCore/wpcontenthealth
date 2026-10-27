<?php
/**
 * Hata günlüğü: yalnız WP_DEBUG_LOG açıkken, kişisel veri içermeyen kısa satır.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Log {

	public static function error( string $where, \Throwable $e ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( '[contenthealth] %s: %s (%s:%d)', $where, $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
