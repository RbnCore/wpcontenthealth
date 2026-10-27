<?php
/**
 * Tarama bağlamı: ayarlar, site adresi, önbellekler ve yazı görünümü.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Ctx {

	/** @var array<string,mixed> */
	public array $settings;
	public string $home_host;
	public bool $https;
	/** @var array<string,int> */
	private array $url_cache = array();

	public function __construct() {
		$this->settings  = Settings::all();
		$home            = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$this->home_host = $home;
		$this->https     = 0 === strpos( home_url(), 'https://' );
	}

	/** @return array<int,string> */
	public function post_types(): array {
		$types = array_keys( get_post_types( array( 'public' => true ) ) );
		return array_values( array_diff( $types, (array) $this->settings['exclude_post_types'] ) );
	}

	/** @return array<int,string> */
	public function statuses(): array {
		return (array) $this->settings['statuses'];
	}

	/** Bağlantıdan yazı kimliği (0: çözülemedi). */
	public function resolve_url( string $url ): int {
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		if ( '/' === ( $url[0] ?? '' ) ) {
			$url = home_url( $url );
		}
		$key = strtok( $url, '#' ) ?: $url;
		if ( ! isset( $this->url_cache[ $key ] ) ) {
			$norm                   = preg_replace( '~^https?://[^/]+~i', home_url(), $key ) ?? $key;
			$this->url_cache[ $key ] = (int) url_to_postid( $norm );
		}
		return $this->url_cache[ $key ];
	}
}
