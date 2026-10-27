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
				'label'  => __( 'Alt Metin', 'contenthealth' ),
				'render' => array( $this, 'render' ),
			),
		);
	}

	public function cli(): array {
		return array();
	}

	public function rules(): array {
		return array( new Rules\ImagesMissingAlt(), new Rules\BrokenImageHost() );
	}

	public function render(): void {
		echo '<p class="ch-lead">' . esc_html__( 'Alt metni olmayan görsellere şablondan alt metin yazar. Dolu alt metne dokunulmaz. Şablon etiketleri: {başlık}, {dosya_adı}, {site_adı}.', 'contenthealth' ) . '</p>';
		Admin::fix_panel( 'images_missing_alt', __( 'Alt metni şablondan doldur', 'contenthealth' ), true, array( 'alt_template' => (string) \ContentHealth\Settings::get( 'alt_template' ) ) );
	}
}
