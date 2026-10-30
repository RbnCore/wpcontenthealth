<?php
/**
 * Kaldırma: yalnız "Kaldırırken sil" ayarı açıksa tablolar ve ayarlar silinir.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$contenthealth_settings = get_option( 'contenthealth_settings' );
if ( ! is_array( $contenthealth_settings ) || empty( $contenthealth_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'runs', 'issues', 'undo' ) as $contenthealth_t ) {
	\AdminKit\Helpers\Db::query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'contenthealth_' . $contenthealth_t ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
}
foreach ( array( 'contenthealth_settings', 'contenthealth_db_version', 'contenthealth_noindex_terms', 'contenthealth_term_redirects', 'contenthealth_onboarded' ) as $contenthealth_o ) {
	delete_option( $contenthealth_o );
}
if ( class_exists( '\AdminKit\Core\Cache' ) ) {
	\AdminKit\Core\Cache::purge_plugin( 'contenthealth' );
}
wp_clear_scheduled_hook( 'contenthealth_cron_scan' );
wp_clear_scheduled_hook( 'contenthealth_cron_continue' );
