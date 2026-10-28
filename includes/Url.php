<?php
/**
 * Adres ayıklama ve sınıflama (geliştirme/staging sızıntısı, bozuk biçim, çift uzantı).
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Url {

	/** Aday adresler: tırnaklı öznitelikler ve çıplak `http(s)` / bozuk `https.` biçimleri. */
	public const CANDIDATE = '~\bhttps?(?::/{0,2}|\.(?=[a-z0-9]))[^\s"\'<>)\]]+~iu';

	/**
	 * @return array<int,string> Benzersiz aday adresler.
	 */
	public static function candidates( string $html ): array {
		if ( ! preg_match_all( self::CANDIDATE, $html, $m ) ) {
			return array();
		}
		return array_values( array_unique( array_map( static fn( $u ) => rtrim( $u, '.,;' ), $m[0] ) ) );
	}

	public static function host_key( string $host ): string {
		$h = strtolower( $host );
		return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
	}

	/**
	 * Sınıf: null = sağlam; yoksa [neden, açıklama].
	 * Nedenler: bozuk_biçim, cift_uzanti, gelistirme_adresi.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public static function classify( string $url, string $home_host, array $extra_dev_hosts = array() ): ?array {
		if ( preg_match( '~^https?\.~i', $url ) ) {
			return array( 'bozuk_biçim', 'starts with https. / http.' );
		}
		if ( ! preg_match( '~^https?:~i', $url ) ) {
			return null;
		}
		if ( ! preg_match( '~^https?://[^/]~i', $url ) ) {
			return array( 'bozuk_biçim', 'missing or extra slash' );
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return array( 'bozuk_biçim', 'no host name' );
		}
		$key = self::host_key( $host );
		if ( preg_match( '~\.([a-z]{2,12})\.\1$~', $host ) ) {
			return array( 'cift_uzanti', 'repeated domain extension' );
		}
		if ( self::host_key( $home_host ) === $key ) {
			return null;
		}
		if ( in_array( $key, $extra_dev_hosts, true ) ) {
			return array( 'gelistirme_adresi', 'configured development host' );
		}
		if ( preg_match( '~(^|\.)(test|local|localhost|invalid|internal|lan|localdomain)$~', $host )
			|| preg_match( '~^(127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.)\d+~', $host )
			|| in_array( $host, array( 'localhost', '[::1]', '0.0.0.0' ), true )
			|| preg_match( '~^(staging|stage|dev|develop|test|preview|uat|qa)\d*\.~', $host ) ) {
			return array( 'gelistirme_adresi', 'development/local/staging host' );
		}
		return null;
	}

	/** Site içi mi? Göreli yol ya da aynı sunucu adı. */
	public static function is_internal( string $url, string $home_host ): bool {
		$url = trim( $url );
		if ( '' === $url || '#' === $url[0] || 0 === strpos( $url, 'mailto:' ) || 0 === strpos( $url, 'tel:' ) || 0 === strpos( $url, 'javascript:' ) ) {
			return false;
		}
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			return true;
		}
		$host = (string) wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST );
		return '' !== $host && self::host_key( $host ) === self::host_key( $home_host );
	}

	/**
	 * `<a href>` değerleri (ham, çözülmüş).
	 *
	 * @return array<int,string>
	 */
	public static function hrefs( string $html ): array {
		if ( ! preg_match_all( '~<a\b[^>]*?\shref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $html, $m, PREG_SET_ORDER ) ) {
			return array();
		}
		$out = array();
		foreach ( $m as $row ) {
			$v = $row[1] ?? '';
			if ( '' === $v && isset( $row[2] ) && '' !== $row[2] ) {
				$v = $row[2];
			}
			if ( '' === $v && isset( $row[3] ) ) {
				$v = $row[3];
			}
			$out[] = html_entity_decode( trim( $v ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $out;
	}

	/**
	 * `<img>` etiketleri: ham etiket, src, alt (öznitelik yoksa null).
	 *
	 * @return array<int,array{tag:string,src:string,srcset:string,alt:?string}>
	 */
	public static function images( string $html ): array {
		if ( ! preg_match_all( '~<img\b[^>]*>~i', $html, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $tag ) {
			$out[] = array(
				'tag'    => $tag,
				'src'    => self::attr( $tag, 'src' ) ?? '',
				'srcset' => self::attr( $tag, 'srcset' ) ?? '',
				'alt'    => self::attr( $tag, 'alt' ),
			);
		}
		return $out;
	}

	public static function attr( string $tag, string $name ): ?string {
		if ( preg_match( '~\s' . preg_quote( $name, '~' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $tag, $m ) ) {
			foreach ( array( 1, 2, 3 ) as $i ) {
				if ( isset( $m[ $i ] ) && '' !== $m[ $i ] ) {
					return html_entity_decode( $m[ $i ], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
			return '';
		}
		if ( preg_match( '~\s' . preg_quote( $name, '~' ) . '(?=[\s/>])~i', $tag ) ) {
			return '';
		}
		return null;
	}
}
