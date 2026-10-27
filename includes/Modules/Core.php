<?php
/**
 * Çekirdek modül: içerik temizliği (A), meta/SEO (B) ve bağlantı (D) kuralları.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Modules;

use ContentHealth\Module;
use ContentHealth\Rules;

defined( 'ABSPATH' ) || exit;

require_once CONTENTHEALTH_DIR . 'includes/Rules/Content.php';
require_once CONTENTHEALTH_DIR . 'includes/Rules/Seo.php';
require_once CONTENTHEALTH_DIR . 'includes/Rules/Links.php';

final class Core implements Module {

	public function register(): void {}

	public function settings(): array {
		return array();
	}

	public function admin_pages(): array {
		return array();
	}

	public function cli(): array {
		return array();
	}

	public function rules(): array {
		return array(
			new Rules\AiChatResidue(),
			new Rules\AiUiArtifacts(),
			new Rules\MarkdownLeftovers(),
			new Rules\PlaceholderLeftovers(),
			new Rules\EncodingGarbage(),
			new Rules\LatexLeftovers(),
			new Rules\DuplicateParagraphs(),
			new Rules\EmptyHeadings(),
			new Rules\MissingMetaDescription(),
			new Rules\MetaDescriptionLength(),
			new Rules\DuplicateTitle(),
			new Rules\TitleTooLong(),
			new Rules\MissingFeaturedImage(),
			new Rules\ThinContent(),
			new Rules\MultipleH1(),
			new Rules\HeadingHierarchy(),
			new Rules\BadHostUrl(),
			new Rules\MixedContent(),
			new Rules\NoInternalLinks(),
			new Rules\OrphanContent(),
			new Rules\BrokenInternalLinks(),
		);
	}
}
