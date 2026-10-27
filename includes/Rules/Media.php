<?php
/**
 * C grubu: medya kuralları.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Rules;

use ContentHealth\Ctx;
use ContentHealth\Rule;
use ContentHealth\Text;
use ContentHealth\Url;

defined( 'ABSPATH' ) || exit;

final class ImagesMissingAlt extends Rule {
	public function id(): string {
		return 'images_missing_alt';
	}
	public function group(): string {
		return 'media';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Görselde alt metin yok', 'contenthealth' );
	}
	public function description(): string {
		return __( 'İçerikteki <img> etiketlerinde alt özniteliği yok ya da boş. Süs görseli (role="presentation") sayılmaz.', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_alt_from_title';
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		$bad = array();
		foreach ( $obj->images() as $im ) {
			if ( null !== $im['alt'] && '' !== trim( $im['alt'] ) ) {
				continue;
			}
			if ( preg_match( '~\brole\s*=\s*["\']?(?:presentation|none)\b~i', $im['tag'] ) ) {
				continue;
			}
			$bad[] = array( 'src' => Text::snippet( $im['src'], 120 ) );
		}
		return $bad ? $this->found( $bad ) : null;
	}
}

final class BrokenImageHost extends Rule {
	public function id(): string {
		return 'broken_image_host';
	}
	public function group(): string {
		return 'media';
	}
	public function severity(): string {
		return Rule::CRITICAL;
	}
	public function label(): string {
		return __( 'Görsel hatalı sunucudan', 'contenthealth' );
	}
	public function description(): string {
		return __( 'Görsel adresi geliştirme/staging sunucusuna (.test, localhost), tekrarlı uzantıya ya da bozuk biçime işaret ediyor.', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_bad_host_url';
	}
	public function defaults(): array {
		return array(
			'enabled'   => true,
			'dev_hosts' => array(),
		);
	}
	public function check( object $obj, Ctx $ctx ): ?array {
		$bad = array();
		$dev = array_map( 'strtolower', (array) $this->config()['dev_hosts'] );
		foreach ( $obj->images() as $im ) {
			foreach ( array_filter( array( $im['src'], $im['srcset'] ) ) as $u ) {
				foreach ( Url::candidates( (string) $u ) as $cand ) {
					$r = Url::classify( $cand, $ctx->home_host, $dev );
					if ( $r ) {
						$bad[] = array(
							'src'    => Text::snippet( $cand, 120 ),
							'reason' => $r[0],
						);
					}
				}
			}
		}
		return $bad ? $this->found( $bad ) : null;
	}
}
