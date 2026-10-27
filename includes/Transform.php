<?php
/**
 * Saf dönüştürücüler: girdi metin → çıktı metin. Veri tabanına dokunmaz; birim test edilebilir.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

use ContentHealth\Rules\AiChatResidue;
use ContentHealth\Rules\MarkdownLeftovers;

defined( 'ABSPATH' ) || exit;

final class Transform {

	/** Yazının baş/son sohbet bloklarını siler ("her yerde" eşleşmelere dokunmaz). */
	public static function chat_residue( string $html, array $cfg ): string {
		$found = array_filter( AiChatResidue::find( $html, $cfg ), static fn( $f ) => 'anywhere' !== $f['position'] );
		usort( $found, static fn( $a, $b ) => $b['start'] <=> $a['start'] );
		foreach ( $found as $f ) {
			$html = Text::remove_segment( $html, $f['start'], $f['end'] );
		}
		return $html;
	}

	private const UI_ATTR  = '~\sdata-(?:path-to-node|index-in-node|sourcepos|hveid|ved|spm-anchor-id|math)\s*=\s*(?:"[^"]*"|\'[^\']*\')~i';
	/** Sohbet arayüzü sınıfları; jeton kendisi ya da `<ad>-…` ile başlıyorsa (ör. source-inline-chip-container) eşleşir. */
	private const UI_CLASS = array( 'model-response-message-content', 'markdown-main-panel', 'source-inline-chip', 'math-inline', 'horizontal-scroll-wrapper', 'table-block-component' );
	/** Arayüz öğesiyle birlikte atılan eşlik sınıfları ve öznitelikleri (yalnız arayüz sınıfı/kimliği taşıyan öğede). */
	private const UI_COMPANION_CLASS = array( 'ng-star-inserted', 'markdown', 'stronger', 'enable-updated-hr-color' );
	private const UI_COMPANION_ATTR  = '~\s(?:dir\s*=\s*"ltr"|aria-live\s*=\s*"[^"]*"|aria-busy\s*=\s*"[^"]*")~i';
	/** Gemini arama sonucu / medya kabı; dedektörün `attachment-container\s+(?:search-images|youtube)` deseniyle aynı kap. */
	private const UI_CONTAINER = 'attachment-container';

	private static function is_ui_token( string $t ): bool {
		if ( preg_match( '~^citation(?:-end)?-\d+$~', $t ) ) {
			return true;
		}
		foreach ( self::UI_CLASS as $c ) {
			if ( $t === $c || 0 === strpos( $t, $c . '-' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Sohbet arayüzü öznitelikleri/sarmalayıcıları: öznitelik silinir, boşalan div/span sarmalayıcı açılır, içerik korunur. */
	public static function ui_artifacts( string $html ): string {
		// Açılan sarmalayıcının gövdesi aynı geçişte yeniden taranmaz; iç içe olanlar için değişmeyene dek yinele.
		for ( $i = 0; $i < 20; $i++ ) {
			$next = self::ui_pass( self::ui_containers( $html ) );
			if ( $next === $html ) {
				break;
			}
			$html = $next;
		}
		return $html;
	}

	/**
	 * `attachment-container` kabı: içinde metin ve medya yoksa kap kalkar; başlık metni varsa kaplar açılıp metin `<p>` içinde
	 * korunur; görsel/bağlantı/gömme varsa yalnız div sarmalayıcıları açılır, medya silinmez.
	 */
	private static function ui_containers( string $html ): string {
		$pos = 0;
		$out = '';
		while ( preg_match( '~<div\b[^>]*\bclass\s*=\s*"(?:[^"]*\s)?' . self::UI_CONTAINER . '(?:\s[^"]*)?"[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$start    = (int) $m[0][1];
			$end_open = $start + strlen( $m[0][0] );
			$close    = Text::element_end( $html, $end_open, 'div' );
			$out     .= substr( $html, $pos, $start - $pos );
			if ( ! $close ) {
				$out .= $m[0][0];
				$pos  = $end_open;
				continue;
			}
			$flat = trim( (string) preg_replace( '~</?div\b[^>]*>~i', '', substr( $html, $end_open, $close[0] - $end_open ) ) );
			if ( '' !== trim( strip_tags( $flat ) ) || preg_match( '~<(?:img|a|iframe|video|audio|picture|figure|svg|embed|object)\b~i', $flat ) ) {
				$out .= preg_match( '~^<(?:p|h[1-6]|ul|ol|table|figure|blockquote)\b~i', $flat ) ? $flat : '<p>' . $flat . '</p>';
			}
			$pos = $close[1];
		}
		return $out . substr( $html, $pos );
	}

	private static function ui_pass( string $html ): string {
		$html = (string) preg_replace( self::UI_ATTR, '', $html );
		$pos  = 0;
		$out  = '';
		while ( preg_match( '~<(div|span|section)\b([^>]*)>~i', $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$start  = (int) $m[0][1];
			$tag    = strtolower( $m[1][0] );
			$attrs  = $m[2][0];
			$out   .= substr( $html, $pos, $start - $pos );
			$class  = preg_match( '~\bclass\s*=\s*"([^"]*)"~i', $attrs, $cm ) ? $cm[1] : null;
			$id     = preg_match( '~\sid\s*=\s*"([^"]*)"~i', $attrs, $im ) ? $im[1] : '';
			$id_hit = '' !== $id && 1 === preg_match( '~^(?:' . implode( '|', array_map( 'preg_quote', self::UI_CLASS ) ) . ')~', $id );
			$tokens = null !== $class ? ( preg_split( '~\s+~', trim( $class ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() ) : array();
			$hit    = $id_hit || array_filter( $tokens, array( self::class, 'is_ui_token' ) );
			if ( $hit ) {
				$keep = array_values( array_filter( $tokens, static fn( $t ) => ! self::is_ui_token( $t ) && ! in_array( $t, self::UI_COMPANION_CLASS, true ) ) );
				$rest = (string) preg_replace( '~\s*\bclass\s*=\s*"[^"]*"~i', '', $attrs );
				if ( $id_hit ) {
					$rest = (string) preg_replace( '~\sid\s*=\s*"[^"]*"~i', '', $rest );
				}
				$rest = (string) preg_replace( self::UI_COMPANION_ATTR, '', $rest );
				if ( $keep ) {
					$rest .= ' class="' . implode( ' ', $keep ) . '"';
				}
				$attrs = $rest;
			}
			$end_open = $start + strlen( $m[0][0] );
			if ( $hit && '' === trim( $attrs ) ) {
				$close = Text::element_end( $html, $end_open, $tag );
				if ( $close ) {
					$out .= substr( $html, $end_open, $close[0] - $end_open );
					$pos  = $close[1];
					continue;
				}
			}
			$out .= '<' . $m[1][0] . ( $hit ? $attrs : $m[2][0] ) . '>';
			$pos  = $end_open;
		}
		return $out . substr( $html, $pos );
	}

	/** `<pre>/<code>` dışı parçalara $fn uygular. */
	private static function outside_code( string $html, callable $fn ): string {
		$parts = preg_split( '~(<(pre|code|script|style)\b[^>]*>.*?</\2>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $html );
		$out   = '';
		for ( $i = 0, $n = count( $parts ); $i < $n; $i++ ) {
			if ( $i % 3 === 0 ) {
				$out .= $fn( $parts[ $i ] );
			} elseif ( $i % 3 === 1 ) {
				$out .= $parts[ $i ];
			}
		}
		return $out;
	}

	/** Markdown bağlantı / kalın / başlık satırı → HTML. Kod blokları atlanır. */
	public static function markdown( string $html ): string {
		return self::outside_code(
			$html,
			static function ( string $s ): string {
				$s = (string) preg_replace_callback(
					MarkdownLeftovers::RE_LINK,
					static fn( $m ) => '<a href="' . esc_attr( $m[3] ) . '">' . $m[2] . '</a>',
					$s
				);
				$s = (string) preg_replace( MarkdownLeftovers::RE_BOLD, '<strong>$1</strong>', $s );
				$s = (string) preg_replace_callback(
					'~(?:(?<=\n)|\A)((?:\*[ \t]+\S[^\r\n]*(?:\r?\n|\z))+)~u',
					static function ( $m ) {
						$items = preg_split( '~\r?\n~', trim( $m[1] ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
						$li    = array_map( static fn( $l ) => '<li>' . trim( (string) preg_replace( '~^\*[ \t]+~', '', $l ) ) . '</li>', $items );
						return "<ul>\r\n" . implode( "\r\n", $li ) . "\r\n</ul>\r\n";
					},
					$s
				);
				return (string) preg_replace_callback(
					'~<p>\s*(#{1,6})\s+([^<\n]{1,200}?)\s*</p>~u',
					static fn( $m ) => '<h' . max( 2, strlen( $m[1] ) ) . '>' . $m[2] . '</h' . max( 2, strlen( $m[1] ) ) . '>',
					$s
				);
			}
		);
	}

	/** `$5.3/10$` → `5.3/10`; \frac, \times vb. sade karşılığa. */
	public static function latex_score( string $html ): string {
		return self::outside_code(
			$html,
			static function ( string $s ): string {
				$s = (string) preg_replace( '~\$(\d+(?:[.,]\d+)?(?:\s*/\s*\d+(?:[.,]\d+)?)?)\$~', '$1', $s );
				$s = (string) preg_replace( '~\\\\frac\{([^{}]+)\}\{([^{}]+)\}~', '$1/$2', $s );
				$s = (string) preg_replace( '~\\\\text\{([^{}]*)\}~', '$1', $s );
				return strtr( $s, array( '\\times' => '×', '\\cdot' => '·', '\\approx' => '≈' ) );
			}
		);
	}

	/**
	 * Hatalı adres için düzeltilmiş adres.
	 *
	 * @return string|null Yeni adres; çözülemiyorsa null.
	 */
	public static function fix_url( string $url, string $live_host, string $home_host ): ?string {
		$fixed = (string) preg_replace( '~^(https?)\.~i', '$1://', $url );
		$parts = wp_parse_url( $fixed );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host ) {
			return null;
		}
		$h2 = (string) preg_replace( '~\.([a-z]{2,12})\.\1$~i', '.$1', $host );
		if ( $h2 !== $host && ! Url::classify( 'https://' . $h2 . '/', $home_host ) ) {
			$host = $h2;
		} elseif ( Url::classify( 'https://' . $h2 . '/', $home_host ) ) {
			$host = '' !== $live_host ? $live_host : '';
		}
		if ( '' === $host ) {
			return null;
		}
		$scheme = 'http' === ( $parts['scheme'] ?? 'https' ) && '' === $live_host ? 'http' : 'https';
		return $scheme . '://' . $host
			. ( isset( $parts['port'] ) && '' !== $live_host ? '' : ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) )
			. ( $parts['path'] ?? '' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Metindeki hatalı adresleri düzeltir.
	 *
	 * @return array{0:string,1:int,2:array<int,string>} [yeni metin, değişen adres sayısı, çözülemeyenler]
	 */
	public static function bad_hosts( string $text, string $home_host, string $live_host, array $dev_hosts = array() ): array {
		$map  = array();
		$skip = array();
		foreach ( array_unique( Url::candidates( $text ) ) as $u ) {
			if ( Url::classify( $u, $home_host, $dev_hosts ) ) {
				$n = self::fix_url( $u, $live_host, $home_host );
				if ( null === $n ) {
					$skip[] = $u;
				} else {
					$map[ $u ] = $n;
					// JSON'da kaçışlı eğik çizgi biçimi.
					$map[ str_replace( '/', '\\/', $u ) ] = str_replace( '/', '\\/', $n );
				}
			}
		}
		if ( ! $map ) {
			return array( $text, 0, $skip );
		}
		uksort( $map, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		return array( strtr( $text, $map ), (int) ( count( $map ) / 2 ) ?: 1, $skip );
	}

	/** Dosya adından okunur metin. */
	public static function humanize_filename( string $src ): string {
		$base = pathinfo( (string) wp_parse_url( $src, PHP_URL_PATH ), PATHINFO_FILENAME );
		$base = (string) preg_replace( '~-\d+x\d+$|-scaled$~', '', $base );
		return trim( (string) preg_replace( '~[\s_-]+~u', ' ', $base ) );
	}

	/** Alt şablonu: {başlık}, {dosya_adı}, {site_adı}. */
	public static function alt_text( string $tpl, string $title, string $src, string $site ): string {
		$alt = strtr(
			$tpl,
			array(
				'{başlık}'    => $title,
				'{dosya_adı}' => self::humanize_filename( $src ),
				'{site_adı}'  => $site,
			)
		);
		return trim( (string) preg_replace( '~\s+~u', ' ', $alt ) );
	}

	/** `<img>` etiketlerinde alt'ı olmayan/boş olanlara şablonla alt yazar; dolu alt'a dokunmaz. */
	public static function fill_alt( string $html, string $tpl, string $title, string $site, int &$changed = 0 ): string {
		return self::outside_code(
			$html,
			static function ( string $s ) use ( $tpl, $title, $site, &$changed ): string {
				return (string) preg_replace_callback(
					'~<img\b[^>]*>~i',
					static function ( $m ) use ( $tpl, $title, $site, &$changed ) {
						$tag = $m[0];
						$alt = Url::attr( $tag, 'alt' );
						if ( ( null !== $alt && '' !== trim( $alt ) ) || preg_match( '~\brole\s*=\s*["\']?(?:presentation|none)\b~i', $tag ) ) {
							return $tag;
						}
						$text = self::alt_text( $tpl, $title, (string) Url::attr( $tag, 'src' ), $site );
						if ( '' === $text ) {
							return $tag;
						}
						++$changed;
						$esc = esc_attr( $text );
						if ( null !== $alt ) {
							return (string) preg_replace( '~\balt\s*=\s*(?:"[^"]*"|\'[^\']*\')~i', 'alt="' . $esc . '"', $tag, 1 );
						}
						return (string) preg_replace( '~\s*/?>$~', ' alt="' . $esc . '"' . ( '/' === substr( rtrim( $tag, '>' ), -1 ) ? ' /' : '' ) . '>', $tag, 1 );
					},
					$s
				);
			}
		);
	}

	/** Terim adından sohbet/markdown artığını ayıklar; boşsa null. */
	public static function clean_term_name( string $name ): ?string {
		$n = (string) preg_replace( '~[\r\n]+~', ' ', $name );
		$n = (string) preg_replace( '~^(?:İşte|Elbette|Tabii(?: ki)?|Tamamdır|Here\s+(?:is|are)|Sure|Certainly)\b[^:]{0,80}:\s*~iu', '', $n );
		$n = str_replace( array( '**', '__', '`' ), '', $n );
		$n = (string) preg_replace( '~^\s*(?:#{1,6}\s+|[-*•]\s+)~u', '', $n );
		$n = trim( (string) preg_replace( '~[:：\s]+$~u', '', $n ) );
		$n = trim( (string) preg_replace( '~\s+~u', ' ', $n ) );
		return '' === $n ? null : $n;
	}

	/**
	 * Sade fark: önce/sonra parçalarından yalnız değişenleri verir.
	 *
	 * @return array{removed:array<int,string>,added:array<int,string>}
	 */
	public static function diff( string $a, string $b ): array {
		$split = static fn( string $s ): array => array_values( array_filter( array_map( 'trim', preg_split( '~(?<=</p>)|(?<=</h[1-6]>)|(?<=</li>)|\R~u', $s ) ?: array() ), static fn( $x ) => '' !== $x ) );
		$la    = $split( $a );
		$lb    = $split( $b );
		$ca    = array_count_values( $la );
		$cb    = array_count_values( $lb );
		$rem   = array();
		$add   = array();
		foreach ( $la as $x ) {
			if ( ( $cb[ $x ] ?? 0 ) > 0 ) {
				--$cb[ $x ];
			} else {
				$rem[] = Text::snippet( $x, 240 );
			}
		}
		foreach ( $lb as $x ) {
			if ( ( $ca[ $x ] ?? 0 ) > 0 ) {
				--$ca[ $x ];
			} else {
				$add[] = Text::snippet( $x, 240 );
			}
		}
		return array(
			'removed' => array_slice( $rem, 0, 6 ),
			'added'   => array_slice( $add, 0, 6 ),
		);
	}
}
