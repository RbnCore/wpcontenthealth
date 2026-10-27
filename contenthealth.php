<?php
/**
 * Plugin Name:       ContentHealth — İçerik Sağlığı
 * Plugin URI:        https://github.com/RbnCore
 * Description:       WordPress "Site Sağlığı" gibi İÇERİK sağlığı paneli: yapay zekâ/arayüz kalıntısı, bozuk adres, meta, alt metin ve etiket denetimi; kuru koşu ve geri alma ile düzeltme. Dış servis çağırmaz.
 * Version:           0.1.3
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            RBN Bilişim
 * Author URI:        https://github.com/RbnCore
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contenthealth
 * Domain Path:       /languages
 *
 * @package ContentHealth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'CONTENTHEALTH_VERSION', '0.1.3' );
define( 'CONTENTHEALTH_FILE', __FILE__ );
define( 'CONTENTHEALTH_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONTENTHEALTH_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'ContentHealth\\' ) ) {
			return;
		}
		$path = CONTENTHEALTH_DIR . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( 'ContentHealth\\' ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( \ContentHealth\Db::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \ContentHealth\Cron::class, 'unschedule' ) );

/*
 * Ön yüz yükü SIFIR: aşağıdakiler yalnız kanca kaydıdır; sınıf yüklemez, sorgu yapmaz.
 * Gerçek iş yalnız yönetim, cron, REST ve WP-CLI bağlamında başlar.
 */
add_filter(
	'cron_schedules',
	static function ( array $schedules ): array {
		$schedules['contenthealth_weekly'] = array(
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => 'ContentHealth haftalık',
		);
		return $schedules;
	}
);

add_action( 'contenthealth_cron_scan', array( \ContentHealth\Cron::class, 'run' ) );
add_action( 'contenthealth_cron_continue', array( \ContentHealth\Cron::class, 'run' ) );

add_action(
	'rest_api_init',
	static function (): void {
		\ContentHealth\Plugin::boot();
		\ContentHealth\Rest::register();
	}
);

if ( is_admin() ) {
	add_action(
		'plugins_loaded',
		static function (): void {
			\ContentHealth\Plugin::boot();
			\ContentHealth\Admin::init();
		}
	);
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action(
		'plugins_loaded',
		static function (): void {
			\ContentHealth\Plugin::boot();
			\WP_CLI::add_command( 'contenthealth', \ContentHealth\Cli::class );
		}
	);
}

/* İnce etiket arşivi noindex: yalnız kullanıcı en az bir etiketi işaretlediyse kanca eklenir. */
add_action(
	'init',
	static function (): void {
		if ( is_admin() || ! get_option( 'contenthealth_noindex_terms' ) ) {
			return;
		}
		add_filter( 'wp_robots', array( \ContentHealth\Modules\Taxonomy::class, 'robots' ) );
	}
);
