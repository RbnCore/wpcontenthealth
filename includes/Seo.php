<?php
/**
 * SEO eklentisi uyumluluğu: meta açıklama okuma yardımcıları.
 * Yoast / Rank Math / AIOSEO / hiçbiri. WPML ve Polylang kapsam dışıdır.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Seo {

	public const META_KEYS = array(
		'yoast'    => '_yoast_wpseo_metadesc',
		'rankmath' => 'rank_math_description',
		'aioseo'   => '_aioseo_description',
	);

	/** Etkin SEO eklentisi: yoast | rankmath | aioseo | ''. */
	public static function active_plugin(): string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		return '';
	}

	/**
	 * Etkin değil olsa bile kayıtlı alanlara bakar (eklenti kapalıyken de veri kalır).
	 *
	 * @return array{0:string,1:string} [metin, kaynak: yoast|rankmath|aioseo|excerpt|'' ].
	 */
	public static function description( int $post_id, string $excerpt = '', bool $fallback_excerpt = true ): array {
		foreach ( self::META_KEYS as $name => $key ) {
			$v = trim( (string) get_post_meta( $post_id, $key, true ) );
			if ( '' !== $v && ! preg_match( '~^%%[a-z_]+%%$~i', $v ) ) {
				return array( $v, $name );
			}
		}
		$excerpt = trim( wp_strip_all_tags( $excerpt ) );
		if ( $fallback_excerpt && '' !== $excerpt ) {
			return array( $excerpt, 'excerpt' );
		}
		return array( '', '' );
	}
}
