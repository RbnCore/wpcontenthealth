<?php
/**
 * Medya modülü: görsel kuralları ve Alt Metin Doldurucu sekmesi.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Modules;

use ContentHealth\Admin;
use ContentHealth\Module;
use ContentHealth\Rules;

defined( 'ABSPATH' ) || exit;

require_once CONTENTHEALTH_DIR . 'includes/Rules/Media.php';

final class Media implements Module {

	public function register(): void {}

	public function settings(): array {
		return array( 'alt_template' => '{başlık}' );
	}

	public function admin_pages(): array {
		return array(
			'alt' => array(
				'label'   => __( 'Alt Metin', 'contenthealth' ),
				'render'  => array( \ContentHealth\Screens\Alt::class, 'render' ),
				'count'   => array( \ContentHealth\Screens\Alt::class, 'count' ),
				'dialogs' => array( \ContentHealth\Screens\Alt::class, 'dialogs' ),
			),
		);
	}

	public function cli(): array {
		return array();
	}

	public function rules(): array {
		return array( new Rules\ImagesMissingAlt(), new Rules\BrokenImageHost() );
	}
}
