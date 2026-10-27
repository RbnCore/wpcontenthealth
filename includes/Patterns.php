<?php
/**
 * Kullanıcı düzenlenebilir düzenli ifade listeleri için güvenli derleme ve eşleme (ReDoS koruması).
 *
 * Korunma: uzunluk sınırı, iç içe niceleyici sezgisi, derleme denemesi, patolojik girdiyle süre testi,
 * eşleme sırasında geri izleme sınırı ve hata denetimi.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Patterns {

	public const MAX_LEN = 300;
	public const MAX_MS  = 40;

	/** @var array<string,string|null> */
	private static array $compiled = array();

	/**
	 * Desen sınırlayıcısız yazılır; `u` ve `i` bayrakları eklenir.
	 *
	 * @return string|\WP_Error Derlenmiş ifade ya da hata.
	 */
	public static function compile( string $pattern ) {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			return new \WP_Error( 'empty', __( 'Boş desen.', 'contenthealth' ) );
		}
		if ( mb_strlen( $pattern ) > self::MAX_LEN ) {
			return new \WP_Error( 'long', __( 'Desen çok uzun.', 'contenthealth' ) );
		}
		// İç içe niceleyici: (…+)+, (…*)*, (…+){n,} gibi felaket geri izleme kalıpları.
		if ( preg_match( '/\((?:[^()\\\\]|\\\\.)*[+*](?:[^()\\\\]|\\\\.)*\)\s*(?:[+*]|\{\d+,\d*\})/', $pattern ) ) {
			return new \WP_Error( 'nested', __( 'İç içe niceleyici (ReDoS riski) kabul edilmez.', 'contenthealth' ) );
		}
		$re = '~' . str_replace( '~', '\\~', $pattern ) . '~iu';
		if ( isset( self::$compiled[ $re ] ) ) {
			return self::$compiled[ $re ] ?? new \WP_Error( 'bad', __( 'Geçersiz desen.', 'contenthealth' ) );
		}
		$ok = self::probe( $re );
		self::$compiled[ $re ] = $ok ? $re : null;
		return $ok ? $re : new \WP_Error( 'bad', __( 'Geçersiz ya da çok yavaş desen.', 'contenthealth' ) );
	}

	private static function probe( string $re ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- geçersiz desen uyarısını yutmak amaçlı.
		if ( false === @preg_match( $re, '' ) ) {
			return false;
		}
		$old = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '100000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$bad = false;
		foreach ( array( str_repeat( 'a', 4000 ) . '!', str_repeat( 'ab ', 1500 ) . '#', str_repeat( '<a ', 1000 ) ) as $subject ) {
			$t = microtime( true );
			@preg_match( $re, $subject ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$ms = ( microtime( true ) - $t ) * 1000;
			if ( PREG_NO_ERROR !== preg_last_error() || $ms > self::MAX_MS ) {
				$bad = true;
				break;
			}
		}
		ini_set( 'pcre.backtrack_limit', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		return ! $bad;
	}

	/**
	 * Liste doğrular: geçerli olanları ve hata iletilerini döner.
	 *
	 * @param array<int,string> $list Ham desenler.
	 * @return array{valid:array<int,string>,errors:array<int,string>}
	 */
	public static function validate_list( array $list ): array {
		$valid  = array();
		$errors = array();
		foreach ( $list as $p ) {
			$c = self::compile( (string) $p );
			if ( is_wp_error( $c ) ) {
				$errors[] = mb_substr( (string) $p, 0, 60 ) . ' → ' . $c->get_error_message();
			} else {
				$valid[] = (string) $p;
			}
		}
		return array(
			'valid'  => $valid,
			'errors' => $errors,
		);
	}

	/**
	 * Güvenli eşleme: derleme başarısızsa ya da PCRE hatası olursa eşleşme sayılmaz.
	 *
	 * @param array<int,string> $matches Çıkış (isteğe bağlı).
	 */
	public static function match( string $pattern, string $subject, array &$matches = array() ): bool {
		$re = self::compile( $pattern );
		if ( is_wp_error( $re ) ) {
			return false;
		}
		$old = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '200000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$r = @preg_match( $re, $subject, $matches ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$e = preg_last_error();
		ini_set( 'pcre.backtrack_limit', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		return 1 === $r && PREG_NO_ERROR === $e;
	}

	/** İlk eşleşen desen ve parça: [desen, eşleşen metin] ya da null. */
	public static function first( array $patterns, string $subject ): ?array {
		foreach ( $patterns as $p ) {
			$m = array();
			if ( self::match( (string) $p, $subject, $m ) ) {
				return array( (string) $p, (string) $m[0] );
			}
		}
		return null;
	}
}
