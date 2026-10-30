<?php
/**
 * Ayarlar: TEK şema bildirimi (motor: AdminKit `Core\Settings`). Okuma, temizleme, doğrulama ve kaydetme motordadır;
 * burada yalnız alan yolları, türleri, varsayılanları, sınırları ve kural alanlarının türetilmesi yazılır.
 *
 * Seçenek adı ve saklanan şekil DEĞİŞMEZ: `contenthealth_settings` düz bir dizi, kural ayarları `rules.<kural>`
 * altında. Köprü/migrasyon kodu yoktur; motor mevcut veriyi bu şemayla okur.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'contenthealth_settings';

	/** Motor kayıt kimliği (eklenti metin alanı). */
	public const SLUG = 'contenthealth';

	/** Ayar ekranının form kimliği (tek form: alt sekmelerin hepsi bu formu gönderir). */
	public const FORM = 'ayar';

	/** Metin modül ayarı sınırı (bugünkü `alt_template` 200 karakterdi). */
	private const MODULE_TEXT_MAX = 200;

	/**
	 * Şema: alan yolu => tür, varsayılan, sınır. Varsayılanlar BURADAN ve kural/modül bildirimlerinden türetilir.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		self::textdomain();
		$schema = array(
			'frequency'           => array(
				'type'    => 'enum',
				'default' => 'off',
				'values'  => array( 'off', 'hourly', 'daily', 'weekly' ),
			),
			'time_budget'         => array(
				'type'    => 'int',
				'default' => 5,
				'min'     => 1,
				'max'     => 20,
			),
			'batch_size'          => array(
				'type'    => 'int',
				'default' => 100,
				'min'     => 10,
				'max'     => 500,
			),
			'statuses'            => array(
				'type'    => 'list',
				'default' => array( 'publish' ),
			),
			'exclude_post_types'  => array(
				'type'    => 'list',
				'default' => array( 'attachment' ),
			),
			'keep_modified'       => array(
				'type'    => 'bool',
				'default' => true,
			),
			'audit_on_save'       => array(
				'type'    => 'bool',
				'default' => true,
			),
			'delete_on_uninstall' => array(
				'type'    => 'bool',
				'default' => false,
			),
			'live_host'           => array(
				'type'    => 'host_port',
				'default' => '',
			),
		);
		// Modüller kendi ayar varsayılanlarını bildirir (alt_template, term_taxonomies); tür varsayılandan türetilir.
		foreach ( Plugin::modules() as $module ) {
			foreach ( $module->settings() as $key => $default ) {
				$schema[ (string) $key ] = self::module_field( $default );
			}
		}
		// Kural ayarları: her kural kendi satırlarını `defaults()` sözleşmesinden türetir (tek yer).
		$schema['rules'] = array();
		foreach ( Registry::rules() as $rule ) {
			$schema['rules'][ $rule->id() ] = $rule->settings_schema();
		}
		return $schema;
	}

	/**
	 * Motor kaydı: şema + form kümesi. Kayıt ilk kazanır; ikinci çağrı etkisizdir.
	 */
	public static function register(): void {
		\AdminKit\Core\Settings::register(
			self::SLUG,
			array(
				'option'     => self::OPTION,
				'schema'     => array( self::class, 'schema' ),
				'forms'      => array( self::FORM ),
				// Mevcut satırın autoload bayrağına dokunulmaz; yeni kurulum `off` olur (bugünkü `update_option(…, false)`).
				'autoload'   => false,
				'capability' => 'manage_options',
			)
		);
	}

	/**
	 * Şema kaydı yükleme sırası ne olursa olsun bir kez yapılır.
	 */
	private static function ensure(): void {
		if ( ! \AdminKit\Core\Settings::registered( self::SLUG ) ) {
			self::register();
		}
	}

	/**
	 * Motor varsayılanları (kural ve modül satırları dâhil).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		self::ensure();
		return \AdminKit\Core\Settings::defaults( self::SLUG );
	}

	/**
	 * Kaydedilmiş ayarlar varsayılanların üzerinde.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		self::ensure();
		return \AdminKit\Core\Settings::all( self::SLUG );
	}

	/**
	 * Tek değer (iç içe yol `a.b.c`).
	 *
	 * @param mixed $default Bulunamazsa dönecek değer.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		self::ensure();
		return \AdminKit\Core\Settings::get( self::SLUG, $key, $default );
	}

	/**
	 * Bir kuralın ayarı (kural yoksa yalnız `enabled`).
	 *
	 * @return array<string,mixed>
	 */
	public static function rule( string $id ): array {
		$config = self::get( 'rules.' . $id, array( 'enabled' => false ) );
		return is_array( $config ) ? $config : array( 'enabled' => false );
	}

	public static function reset_cache(): void {
		\AdminKit\Core\Settings::reset_cache( self::SLUG );
	}

	/**
	 * Kaydet: motor temizler, doğrular, yazar (FORM modunda gönderilmeyen işaret kutusu = kapalı).
	 *
	 * @param array<string,mixed> $in   Ham girdi.
	 * @param string              $form Form kimliği; boşsa PATCH (gönderilmeyen alan eski değerinde kalır).
	 * @return array{settings:array<string,mixed>,errors:array<int,string>}
	 */
	public static function save( array $in, string $form = '' ): array {
		self::ensure();
		$res    = \AdminKit\Core\Settings::update( self::SLUG, $in, '' === $form ? array() : array( 'form' => $form ) );
		$errors = array();
		foreach ( $res['errors'] as $id => $message ) {
			$errors[] = self::error_label( (string) $id ) . ': ' . (string) $message;
		}
		// Tarama sıklığı değişmiş olabilir: yinelenen olayı ayara göre kur/kaldır (okuma motorun taze önbelleğinden).
		Cron::schedule();
		return array(
			'settings' => (array) $res['settings'],
			'errors'   => $errors,
		);
	}

	/**
	 * Satır satır ya da dizi girdisini temiz dizi yapar (kural AdminKit ayar motorundadır: tek kopya).
	 *
	 * @param mixed $v      Girdi.
	 * @param bool  $commas Virgül de ayraç sayılsın mı (desenlerde HAYIR: {0,220} bozulur).
	 * @return array<int,string>
	 */
	public static function lines( $v, bool $commas = true ): array {
		return \AdminKit\Core\Settings::lines( $v, $commas );
	}

	/**
	 * Hata kimliğini kısa ve okunur yapar (`rules.ai_chat_residue.patterns_head` → `ai_chat_residue.patterns_head`).
	 */
	private static function error_label( string $id ): string {
		return 0 === strpos( $id, 'rules.' ) ? substr( $id, 6 ) : $id;
	}

	/**
	 * Modül ayarı satırı: tür varsayılanın şeklinden türetilir (dizi → list, bool → bool, int → int, metin → text).
	 *
	 * @param mixed $default Modülün bildirdiği varsayılan.
	 * @return array<string,mixed>
	 */
	private static function module_field( $default ): array {
		if ( is_array( $default ) ) {
			return array(
				'type'    => 'list',
				'default' => array_map( 'strval', array_values( $default ) ),
			);
		}
		if ( is_bool( $default ) ) {
			return array(
				'type'    => 'bool',
				'default' => $default,
			);
		}
		if ( is_int( $default ) ) {
			return array(
				'type'    => 'int',
				'default' => $default,
			);
		}
		return array(
			'type'    => 'text',
			'default' => (string) $default,
			'max_len' => self::MODULE_TEXT_MAX,
		);
	}

	/** Çeviri, şema ilk çözüldüğünde yüklü olsun (modül etiketleri `__()` kullanır). */
	private static function textdomain(): void {
		if ( ! is_textdomain_loaded( 'contenthealth' ) && defined( 'CONTENTHEALTH_FILE' ) ) {
			load_plugin_textdomain( 'contenthealth', false, dirname( plugin_basename( CONTENTHEALTH_FILE ) ) . '/languages' );
		}
	}
}
