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
				'label'  => __( 'Etiket Doktoru', 'contenthealth' ),
				'render' => array( $this, 'render' ),
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

	public function render(): void {
		echo '<p class="ch-lead">' . esc_html__( 'Etiket ve kategori sorunlarını sınıfa göre görün; her eylem önce kuru koşu gösterir, onayınızla uygular ve Geri alma günlüğüne yazar.', 'contenthealth' ) . '</p>';
		Admin::fix_panel( 'term_empty', __( 'Boş terimleri sil', 'contenthealth' ) );
		Admin::fix_panel( 'term_duplicate', __( 'Yinelenen terimleri birleştir', 'contenthealth' ) );
		Admin::fix_panel( 'term_ai_residue', __( 'Yapay zekâ kalıntısını adlardan ayıkla', 'contenthealth' ) );
		Admin::fix_panel( 'term_single_use', __( 'Tek kullanımlıkları noindex yap', 'contenthealth' ) );
		Admin::fix_panel( 'term_too_long', __( 'Çok uzun terimler (elle yeniden adlandırın)', 'contenthealth' ), false );
	}

	/**
	 * wp_robots süzgeci: yalnız işaretli terimlerin arşivinde noindex.
	 *
	 * @param array<string,mixed> $robots
	 * @return array<string,mixed>
	 */
	public static function robots( array $robots ): array {
		if ( ! is_tax() && ! is_tag() && ! is_category() ) {
			return $robots;
		}
		$q = get_queried_object();
		if ( $q && isset( $q->term_id ) && in_array( (int) $q->term_id, array_map( 'intval', (array) get_option( 'contenthealth_noindex_terms', array() ) ), true ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}
}
