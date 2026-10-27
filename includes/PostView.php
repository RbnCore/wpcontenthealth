<?php
/**
 * Bir yazının tembel hesaplanan görünümü (metin, bloklar, bağlantılar, görseller, başlıklar).
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class PostView {

	public int $id;
	public string $type = 'post';
	public string $post_type;
	public string $title;
	public string $html;
	public string $excerpt;
	public string $slug;
	public int $parent;

	/** @var array<string,mixed> */
	private array $memo = array();

	/** @param object $row posts satırı (ID, post_title, post_content, post_excerpt, post_name, post_type, post_parent). */
	public function __construct( object $row ) {
		$this->id        = (int) $row->ID;
		$this->post_type = (string) $row->post_type;
		$this->title     = (string) $row->post_title;
		$this->html      = (string) $row->post_content;
		$this->excerpt   = (string) ( $row->post_excerpt ?? '' );
		$this->slug      = (string) ( $row->post_name ?? '' );
		$this->parent    = (int) ( $row->post_parent ?? 0 );
	}

	public function plain(): string {
		return $this->memo['plain'] ??= Text::plain( $this->html );
	}

	public function words(): int {
		return $this->memo['words'] ??= Text::words( $this->plain() );
	}

	/** @return array<int,array{0:int,1:int,2:string}> */
	public function segments(): array {
		return $this->memo['segments'] ??= Text::segments( $this->html );
	}

	/** @return array<int,string> */
	public function hrefs(): array {
		return $this->memo['hrefs'] ??= Url::hrefs( $this->html );
	}

	/** @return array<int,array{tag:string,src:string,srcset:string,alt:?string}> */
	public function images(): array {
		return $this->memo['images'] ??= Url::images( $this->html );
	}

	/** @return array<int,array{0:int,1:string}> [seviye, metin] */
	public function headings(): array {
		if ( ! isset( $this->memo['headings'] ) ) {
			$out  = array();
			$code = Text::strip_code( $this->html );
			if ( preg_match_all( '~<h([1-6])\b[^>]*>(.*?)</h\1>~is', $code, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $h ) {
					$out[] = array( (int) $h[1], trim( html_entity_decode( strip_tags( $h[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				}
			}
			$this->memo['headings'] = $out;
		}
		return $this->memo['headings'];
	}
}
