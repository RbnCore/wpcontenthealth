<?php
/**
 * Etiket modülü: E grubu kurallar, Etiket Doktoru sekmesi, noindex çıktısı.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Modules;

use ContentHealth\Admin;
use ContentHealth\Module;
use ContentHealth\Rules;
use ContentHealth\Seo;

defined( 'ABSPATH' ) || exit;

require_once CONTENTHEALTH_DIR . 'includes/Rules/Terms.php';

final class Taxonomy implements Module {

	public function register(): void {}

	public function settings(): array {
		return array( 'term_taxonomies' => array( 'post_tag', 'category' ) );
	}

	public function admin_pages(): array {
		return array(
			'tags' => array(
				'label'   => __( 'Tag Doctor', 'contenthealth' ),
				'render'  => array( \ContentHealth\Screens\Tags::class, 'render' ),
				'count'   => array( \ContentHealth\Screens\Tags::class, 'count' ),
				'dialogs' => array( \ContentHealth\Screens\Tags::class, 'dialogs' ),
			),
		);
	}

	public function cli(): array {
		return array();
	}

	public function rules(): array {
		return array(
			new Rules\TermEmpty(),
			new Rules\TermDuplicate(),
			new Rules\TermTooLong(),
			new Rules\TermSingleUse(),
			new Rules\TermAiResidue(),
			new Rules\TermNumericOnly(),
			new Rules\TermsPerPostHigh(),
		);
	}
	/**
	 * `wp_robots` kancası: kullanıcının işaretlediği tek-yazılı etiket arşivlerine noindex.
	 * Bir SEO eklentisi etkinken basılmaz (robots etiketini o üretir; çift etiket olmaz).
	 *
	 * @param array<string, string|bool> $robots Şimdiye kadarki yönergeler.
	 * @return array<string, string|bool>
	 */
	public static function robots( $robots ) {
		if ( ! is_array( $robots ) || '' !== Seo::active_plugin() || ! is_tax() && ! is_tag() && ! is_category() ) {
			return $robots;
		}
		$term = get_queried_object();
		$ids  = array_map( 'intval', (array) get_option( 'contenthealth_noindex_terms', array() ) );
		if ( $term instanceof \WP_Term && in_array( (int) $term->term_id, $ids, true ) ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}
}
