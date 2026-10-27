<?php
/**
 * Çekirdek önyükleme: dil dosyası, veri tabanı sürümü, modül kaydı.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static bool $booted = false;

	/** @var array<int,Module> */
	private static array $modules = array();

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		load_plugin_textdomain( 'contenthealth', false, dirname( plugin_basename( CONTENTHEALTH_FILE ) ) . '/languages' );
		Db::maybe_upgrade();
		self::$modules = array( new Modules\Core(), new Modules\Taxonomy(), new Modules\Media() );
		foreach ( self::$modules as $module ) {
			$module->register();
		}
	}

	/** @return array<int,Module> */
	public static function modules(): array {
		self::boot();
		return self::$modules;
	}
}
