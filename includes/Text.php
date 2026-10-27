<?php
/**
 * Saf metin/HTML yardımcıları (WordPress'e bağımlı değil; birim testlenebilir).
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Text {

	private const BLOCK_SPLIT = '~(?:<!--.*?-->|</?(?:p|div|h[1-6]|blockquote|ul|ol|li|table|thead|tbody|tr|td|th|pre|figure|figcaption|section|article|hr|br)\b[^>]*>|\r?\n)+~is';

	/** `<pre>`, `<code>`, `<script>`, `<style>` kaldırılmış HTML (düzyazı analizi için). */
	public static function strip_code( string $html ): string {
		return (string) preg_replace( '~<(pre|code|script|style)\b[^>]*>.*?</\1>~is', ' ', $html );
	}

	/** Etiketsiz, varlıkları çözülmüş, boşlukları sadeleştirilmiş düz metin. */
	public static function plain( string $html ): string {
		$t = preg_replace( '~<!--.*?-->~s', ' ', self::strip_code( $html ) ) ?? '';
		$t = html_entity_decode( strip_tags( (string) preg_replace( '~<(br|/p|/div|/li|/h[1-6])\b[^>]*>~i', "\n", $t ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '~[ \t\x{00A0}]+~u', ' ', $t ) );
	}

	public static function words( string $plain ): int {
		if ( '' === $plain ) {
			return 0;
		}
		return count( preg_split( '~\s+~u', $plain, -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
	}

	/** Karakter uzunluğu (çok baytlı güvenli). */
	public static function len( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	/**
	 * Blok parçaları: [başlangıç, bitiş, metin]. Yalnız metni dolu olanlar.
	 *
	 * @return array<int,array{0:int,1:int,2:string}>
	 */
	public static function segments( string $html ): array {
		$parts = preg_split( self::BLOCK_SPLIT, $html, -1, PREG_SPLIT_OFFSET_CAPTURE ) ?: array();
		$out   = array();
		foreach ( $parts as $p ) {
			$raw  = (string) $p[0];
			$text = trim( (string) preg_replace( '~\s+~u', ' ', html_entity_decode( strip_tags( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array( (int) $p[1], (int) $p[1] + strlen( $raw ), $text );
		}
		return $out;
	}

	/**
	 * Bir blok parçasını (ve yalnızca onu saran `<p>` + Gutenberg yorumlarını) kaldırır.
	 */
	public static function remove_segment( string $html, int $start, int $end ): string {
		$before = substr( $html, 0, $start );
		$after  = substr( $html, $end );
		// Tek başına sarmalayıcı <p> ve Gutenberg yorum çifti.
		for ( $i = 0; $i < 2; $i++ ) {
			if ( preg_match( '~<p\b[^>]*>\s*$~i', $before, $mb, PREG_OFFSET_CAPTURE ) && preg_match( '~^\s*</p>~i', $after, $ma ) ) {
				$before = substr( $before, 0, $mb[0][1] );
				$after  = substr( $after, strlen( $ma[0] ) );
			}
			if ( preg_match( '~<!--\s*wp:[a-z/-]+[^>]*-->\s*$~i', $before, $mb, PREG_OFFSET_CAPTURE ) && preg_match( '~^\s*<!--\s*/wp:[a-z/-]+\s*-->~i', $after, $ma ) ) {
				$before = substr( $before, 0, $mb[0][1] );
				$after  = substr( $after, strlen( $ma[0] ) );
			}
		}
		return $before . ltrim( $after, "\r\n \t" );
	}

	/**
	 * Açılış etiketinden sonraki konumdan eşleşen kapanış etiketini bulur (aynı ad iç içe sayılır).
	 *
	 * @return array{0:int,1:int}|null [kapanış başı, kapanış sonu].
	 */
	public static function element_end( string $html, int $from, string $tag ): ?array {
		$depth = 1;
		$pos   = $from;
		while ( preg_match( '~<(/?)' . preg_quote( $tag, '~' ) . '\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$self_close = '/' === substr( $m[0][0], -2, 1 );
			if ( '/' === $m[1][0] ) {
				--$depth;
			} elseif ( ! $self_close ) {
				++$depth;
			}
			$pos = (int) $m[0][1] + strlen( $m[0][0] );
			if ( 0 === $depth ) {
				return array( (int) $m[0][1], $pos );
			}
		}
		return null;
	}

	/** Türkçe duyarlı katlama: küçük harf, aksan/Türkçe karakter → ASCII, yalnız harf-rakam. */
	public static function fold( string $s ): string {
		$s = strtr(
			$s,
			array(
				'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g',
				'Ü' => 'u', 'ü' => 'u', 'Ö' => 'o', 'ö' => 'o', 'Ç' => 'c', 'ç' => 'c',
				'â' => 'a', 'Â' => 'a', 'î' => 'i', 'Î' => 'i', 'û' => 'u', 'Û' => 'u',
				'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
			)
		);
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		return (string) preg_replace( '~[^\p{L}\p{N}]+~u', '', $s );
	}

	public static function hash( string $s ): string {
		return hash( 'sha256', $s );
	}

	/** Uzun metni görüntü için kırp. */
	public static function snippet( string $s, int $max = 160 ): string {
		$s = trim( (string) preg_replace( '~\s+~u', ' ', $s ) );
		return self::len( $s ) > $max ? mb_substr( $s, 0, $max - 1, 'UTF-8' ) . '…' : $s;
	}
}
