<?php
/**
 * Plugin Name:       ContentHealth — Content Health
 * Plugin URI:        https://github.com/RbnCore/wpcontenthealth
 * Description:       A content-health panel in the spirit of WordPress Site Health: checks for AI and interface leftovers, broken URLs, meta, alt text and tags, and fixes them with dry run and undo. Calls no external service.
 * Version:           0.1.6
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  adminkit
 * Author:            RBN Bilişim
 * Author URI:        https://github.com/RbnCore
 * Update URI:        https://github.com/RbnCore/wpcontenthealth
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contenthealth
 * Domain Path:       /languages
 *
 * @package ContentHealth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'CONTENTHEALTH_VERSION', '0.1.6' );
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

/**
 * Çalışma zamanı kapısı (cekirdek.md §6):
 * AdminKit API >= 3 zorunludur (merkezi güncelleyici). Karşılanmazsa eklenti kancalarını eklemeden güvenle kapanır
 * ve yönetimde uyarı gösterir.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'ADMINKIT_CORE_API' ) || ADMINKIT_CORE_API < 3 || ! class_exists( 'AdminKit\Core\Core' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					$adminkit_file = 'adminkit/adminkit.php';
					$installed     = file_exists( WP_PLUGIN_DIR . '/' . $adminkit_file );
					if ( $installed ) {
						$url  = wp_nonce_url(
							admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $adminkit_file ) ),
							'activate-plugin_' . $adminkit_file
						);
						$link = sprintf(
							'<a href="%s">%s</a>',
							esc_url( $url ),
							esc_html__( 'activate AdminKit', 'contenthealth' )
						);
						$msg  = sprintf(
							/* translators: %s: link to activate AdminKit */
							__( 'ContentHealth requires the AdminKit plugin. Please %s to continue.', 'contenthealth' ),
							$link
						);
					} else {
						$url  = 'https://github.com/RbnCore/wpadminkit/releases/latest';
						$link = sprintf(
							'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
							esc_url( $url ),
							esc_html__( 'download and install AdminKit', 'contenthealth' )
						);
						$msg  = sprintf(
							/* translators: %s: link to download AdminKit */
							__( 'ContentHealth requires the AdminKit plugin. Please %s to continue.', 'contenthealth' ),
							$link
						);
					}
					printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $msg ) );
				}
			);
			return;
		}

		contenthealth_boot();
	},
	5
);

/**
 * Eklenti kancalarını ve çekirdek (Cache + Log) kayıtlarını kurar.
 * Yalnız AdminKit çalışma zamanı kapısından geçildiğinde çalışır.
 */
function contenthealth_boot(): void {
	$register_core = static function (): void {
		\AdminKit\Core\Cache::register_group( 'contenthealth', 'scores', \ContentHealth\Score::TTL );
		\AdminKit\Core\Log::register_channel( 'contenthealth', 'errors', 90, 500, array( 'error', 'warning' ) );
	};
	if ( did_action( 'rbnkit_core_ready' ) ) {
		$register_core();
	} else {
		add_action( 'rbnkit_core_ready', $register_core );
	}

	// Güncellemeyi AdminKit çekirdeği yapar (tek merkezi Updater); burada yalnız kayıt vardır.
	\AdminKit\Core\Updater::register( 'contenthealth', CONTENTHEALTH_FILE, CONTENTHEALTH_VERSION );

	add_filter(
		'cron_schedules',
		static function ( array $schedules ): array {
			$schedules['contenthealth_weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				// wp_get_schedules() init'ten önce çağrılabilir (çeviri o zaman çok erken yüklenir); etiket yalnız arayüzde okunur.
				'display'  => did_action( 'init' ) ? __( 'ContentHealth weekly', 'contenthealth' ) : 'ContentHealth weekly',
			);
			return $schedules;
		}
	);

	add_action( 'contenthealth_cron_scan', array( \ContentHealth\Cron::class, 'run' ) );
	add_action( 'contenthealth_cron_continue', array( \ContentHealth\Cron::class, 'run' ) );

	/*
	 * Kayıtta yeniden denetim: yazı kaydedilince yalnız o yazının bulguları denetlenir.
	 * Kanca ucuzdur; sınıf ilk kayıtta yüklenir, ön yüzde (kayıt olmadığından) hiçbir şey çalışmaz.
	 */
	add_action(
		'wp_after_insert_post',
		static function ( int $post_id, \WP_Post $post, bool $update ): void {
			\ContentHealth\SaveAudit::on_save( $post_id, $post, $update );
		},
		20,
		3
	);

	add_action(
		'rest_api_init',
		static function (): void {
			\ContentHealth\Plugin::boot();
			\ContentHealth\Rest::register();
		}
	);

	if ( is_admin() ) {
		\ContentHealth\Plugin::boot();
		\ContentHealth\Admin::init();
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\ContentHealth\Plugin::boot();
		\WP_CLI::add_command( 'contenthealth', \ContentHealth\Cli::class );
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
}
