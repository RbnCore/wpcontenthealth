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
				'label'   => __( 'Etiket Doktoru', 'contenthealth' ),
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
}
