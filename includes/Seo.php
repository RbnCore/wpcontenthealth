<?php
/**
 * SEO eklentisi uyumluluğu: meta açıklama okuma ve etiket noindex yardımcıları.
 * WPSeoKit / Yoast / Rank Math / AIOSEO / hiçbiri. WPML ve Polylang kapsam dışıdır.
 *
 * Eklentiler arasında genel (public) API yoktur: belgelenmiş meta ve seçenek anahtarları SALT OKUNUR.
 * WPSeoKit anahtarları: wpseokit/docs/sozlesme.md §2 ve Services\MetaService / RobotsService.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Seo {

	/** Yazı meta anahtarları; sıra, birden çok eklenti etkinken öncelik sırasıdır. */
	public const META_KEYS = array(
		'wpseokit' => '_wpseokit_description',
		'yoast'    => '_yoast_wpseo_metadesc',
		'rankmath' => 'rank_math_description',
		'aioseo'   => '_aioseo_description',
	);

	/** Etkin eklenti algılama sabitleri; sıra = öncelik (ilk bulunan etkin sayılır). */
	private const CONSTANTS = array(
		'wpseokit' => 'WPSEOKIT_VERSION',
		'yoast'    => 'WPSEO_VERSION',
		'rankmath' => 'RANK_MATH_VERSION',
		'aioseo'   => 'AIOSEO_VERSION',
	);

	/** WPSeoKit `robots.noindex_taxonomies` varsayılanı (ayar henüz kaydedilmediyse). */
	private const WPSEOKIT_NOINDEX_TAXONOMIES = array( 'post_tag', 'post_format' );

	/** Etkin SEO eklentisi: wpseokit | yoast | rankmath | aioseo | ''. Birden çok etkinse CONSTANTS sırası. */
	public static function active_plugin(): string {
		foreach ( self::CONSTANTS as $id => $constant ) {
			if ( defined( $constant ) ) {
				return $id;
			}
		}
		return '';
	}

	/** Eklenti kimliğinin görünen adı; bilinmeyen kimlik için ''. */
	public static function label( string $id ): string {
		$names = array(
			'wpseokit' => 'WPSeoKit',
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'aioseo'   => 'All in One SEO',
		);
		return $names[ $id ] ?? '';
	}

	/** Etiket arşivlerinin etkin eklentide nereden yönetildiğini söyleyen cümle; bilinmeyen kimlik için ''. */
	public static function tag_hint( string $id ): string {
		switch ( $id ) {
			case 'wpseokit':
				return __( 'Tag archives are managed in WPSeoKit\'s “Titles & descriptions” › “Categories & tags” settings (Search results: noindex taxonomies); Content Health does not write to that setting.', 'contenthealth' );
			case 'yoast':
				return __( 'Tag archives are managed in Yoast\'s “Taxonomies” setting; Content Health does not write to that setting.', 'contenthealth' );
			case 'rankmath':
				return __( 'Tag archives are managed in Rank Math\'s “Titles & Meta” section.', 'contenthealth' );
			case 'aioseo':
				return __( 'Tag archives are managed in All in One SEO\'s search appearance settings.', 'contenthealth' );
		}
		return '';
	}

	/** WPSeoKit ayar sayfasının adresi (Admin::page_url + Shell::url: ?page=wpseokit&tool=templates). */
	public static function settings_url( string $id ): string {
		return 'wpseokit' === $id ? add_query_arg( array( 'page' => 'wpseokit', 'tool' => 'templates' ), admin_url( 'admin.php' ) ) : '';
	}

	/**
	 * Etkin eklenti, bu taksonomi arşivini kendi ayarıyla noindex yapıyor mu? Yalnız WPSeoKit için okunabilir;
	 * diğer eklentilerde (ayarları ayrı yapıda) null: ContentHealth karar vermez.
	 */
	public static function taxonomy_noindex( string $taxonomy ): ?bool {
		if ( 'wpseokit' !== self::active_plugin() ) {
			return null;
		}
		$settings = get_option( 'wpseokit_settings', array() );
		$list     = is_array( $settings ) && isset( $settings['robots']['noindex_taxonomies'] ) && is_array( $settings['robots']['noindex_taxonomies'] )
			? $settings['robots']['noindex_taxonomies']
			: self::WPSEOKIT_NOINDEX_TAXONOMIES;
		return in_array( $taxonomy, array_map( 'strval', $list ), true );
	}

	/**
	 * Etkin değil olsa bile kayıtlı alanlara bakar (eklenti kapalıyken de veri kalır). Etkin eklentinin alanına ÖNCE bakılır.
	 *
	 * @return array{0:string,1:string} [metin, kaynak: wpseokit|yoast|rankmath|aioseo|excerpt|'' ].
	 */
	public static function description( int $post_id, string $excerpt = '', bool $fallback_excerpt = true ): array {
		foreach ( self::ordered_keys() as $name => $key ) {
			$v = trim( (string) get_post_meta( $post_id, $key, true ) );
			if ( self::is_filled( $v ) ) {
				return array( $v, $name );
			}
		}
		$excerpt = trim( wp_strip_all_tags( $excerpt ) );
		if ( $fallback_excerpt && '' !== $excerpt ) {
			return array( $excerpt, 'excerpt' );
		}
		return array( '', '' );
	}

	/** Terim açıklaması (yalnız WPSeoKit terim meta'sı okunabilir; diğerleri ayrı yapıda). */
	public static function term_description( int $term_id ): string {
		$v = trim( (string) get_term_meta( $term_id, 'wpseokit_description', true ) );
		return self::is_filled( $v ) ? $v : '';
	}

	/** META_KEYS, etkin eklenti başa alınmış sırada. */
	private static function ordered_keys(): array {
		$keys   = self::META_KEYS;
		$active = self::active_plugin();
		if ( isset( $keys[ $active ] ) ) {
			$keys = array( $active => $keys[ $active ] ) + $keys;
		}
		return $keys;
	}

	/**
	 * Dolu mu: boş değil ve yalnız çözülmemiş şablon değişkenlerinden (Yoast `%%title%%`, WPSeoKit `%sitename%`) ve ayraçlardan oluşmuyor.
	 */
	private static function is_filled( string $v ): bool {
		if ( '' === $v ) {
			return false;
		}
		$rest = preg_replace( '~%%?[a-z_]+%%?~i', '', $v );
		return '' !== trim( (string) $rest, " \t\n\r\0\x0B-–—|·•:,/" );
	}
}
