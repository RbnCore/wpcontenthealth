<?php
/**
 * A grubu: içerik temizliği kuralları.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Rules;

use ContentHealth\Ctx;
use ContentHealth\Patterns;
use ContentHealth\PostView;
use ContentHealth\Rule;
use ContentHealth\Text;

defined( 'ABSPATH' ) || exit;

/** Yazının ilk/son bloğundaki sohbet cümleleri. Konum kuralı yanlış pozitifi düşürür. */
final class AiChatResidue extends Rule {

	public function id(): string {
		return 'ai_chat_residue';
	}
	public function group(): string {
		return 'content';
	}
	public function severity(): string {
		return Rule::CRITICAL;
	}
	public function label(): string {
		return __( 'Yapay zekâ sohbet kalıntısı', 'contenthealth' );
	}
	public function description(): string {
		return __( 'Yazının ilk ya da son bloğunda kalan sohbet cümleleri ("İşte … :", "Umarım yardımcı olur"). Yalnız ilk/son kısa blok sayılır; gövdedeki "Elbette…" gibi editoryal ifadelere dokunulmaz.', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_ai_chat_residue';
	}
	public function defaults(): array {
		return array(
			'enabled'           => true,
			'max_len'           => 260,
			'patterns_head'     => array(
				'^İşte\b[^\n]{0,220}:$',
				'^(?:Elbette|Tabii ki|Tabi ki|Tamamdır|Anladım)\b[^\n]{0,220}[:!]$',
				'^(?:Yanıt|Cevap|Response|Answer)\s*:\s*$',
				'^(?:Here is|Here\'s|Here are)\b[^\n]{0,220}:$',
				'^(?:Certainly|Sure|Of course|Absolutely)\b[^\n]{0,220}[:!]$',
			),
			'patterns_tail'     => array(
				'\b(?:Umarım|Umarız)\b[^\n]{0,80}\b(?:yardımcı\s+ol|işinize\s+yara|işine\s+yara)',
				'\b(?:İsterseniz|Dilerseniz)\b[^\n]{0,120}\b(?:yardımcı\s+olabilirim|devam\s+edebilirim|hazırlayabilirim|ekleyebilirim|düzenleyebilirim)',
				'\bBaşka\s+(?:bir\s+)?(?:sorunuz|isteğiniz)\b[^\n]{0,80}(?:varsa|olursa)',
				'\bI\s+hope\s+this\s+helps\b',
				'\bLet\s+me\s+know\s+if\s+you\b[^\n]{0,100}',
				'\bFeel\s+free\s+to\s+ask\b',
			),
			'patterns_anywhere' => array(
				'\bAs\s+an\s+AI\b(?:\s+language\s+model)?',
				'\bbir\s+yapay\s+zek[aâ](?:\s+dil\s+modeli)?\s+olarak\b',
				'\bdil\s+modeli\s+olarak\b',
				'\bAs\s+a\s+large\s+language\s+model\b',
				'\bI\'m\s+sorry,\s+but\s+I\b',
			),
		);
	}

	/**
	 * Bulgular: baş/son blok eşleşmeleri ve gövdede her yerde geçen kesin kalıplar.
	 *
	 * @param array<string,mixed> $cfg Kural ayarı.
	 * @return array<int,array{position:string,text:string,pattern:string,start:int,end:int}>
	 */
	public static function find( string $html, array $cfg ): array {
		$segs = Text::segments( $html );
		if ( ! $segs ) {
			return array();
		}
		$max   = (int) ( $cfg['max_len'] ?? 260 );
		$found = array();
		$first = $segs[0];
		if ( Text::len( $first[2] ) <= $max ) {
			$hit = Patterns::first( (array) ( $cfg['patterns_head'] ?? array() ), $first[2] );
			if ( $hit ) {
				$found[] = array(
					'position' => 'head',
					'text'     => $first[2],
					'pattern'  => $hit[0],
					'start'    => $first[0],
					'end'      => $first[1],
				);
			}
		}
		$last = $segs[ count( $segs ) - 1 ];
		if ( count( $segs ) > 1 && Text::len( $last[2] ) <= $max ) {
			$hit = Patterns::first( (array) ( $cfg['patterns_tail'] ?? array() ), $last[2] );
			if ( $hit ) {
				$found[] = array(
					'position' => 'tail',
					'text'     => $last[2],
					'pattern'  => $hit[0],
					'start'    => $last[0],
					'end'      => $last[1],
				);
			}
		}
		$plain = Text::plain( $html );
		$hit   = Patterns::first( (array) ( $cfg['patterns_anywhere'] ?? array() ), $plain );
		if ( $hit ) {
			$found[] = array(
				'position' => 'anywhere',
				'text'     => Text::snippet( $hit[1], 120 ),
				'pattern'  => $hit[0],
				'start'    => -1,
				'end'      => -1,
			);
		}
		return $found;
	}

	public function check( object $obj, Ctx $ctx ): ?array {
		$items = array_map(
			static fn( $f ) => array(
				'position' => $f['position'],
				'text'     => Text::snippet( $f['text'], 200 ),
			),
			self::find( $obj->html, $this->config() )
		);
		return $items ? $this->found( $items ) : null;
	}
}

/** Gemini / ChatGPT arayüzünden yapıştırma izleri. */
final class AiUiArtifacts extends Rule {

	public function id(): string {
		return 'ai_ui_artifacts';
	}
	public function group(): string {
		return 'content';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Yapay zekâ arayüz kalıntısı', 'contenthealth' );
	}
	public function description(): string {
		return __( 'Sohbet arayüzünden yapıştırılan sarmalayıcı sınıflar ve veri öznitelikleri (data-path-to-node, model-response-message-content, math-inline …). Düzeltme sarmalayıcıyı açar, içeriği korur.', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_ai_ui_artifacts';
	}
	public function defaults(): array {
		return array(
			'enabled'  => true,
			'patterns' => array(
				'\bdata-(?:path-to-node|index-in-node|sourcepos|hveid|ved|spm-anchor-id|math)=',
				'model-response-message-content',
				'markdown-main-panel',
				'source-inline-chip',
				'math-inline',
				'horizontal-scroll-wrapper',
				'table-block-component',
				'class="[^"]*\bcitation(?:-end)?-\d+',
				'attachment-container\s+(?:search-images|youtube)',
			),
		);
	}

	public function check( object $obj, Ctx $ctx ): ?array {
		$found = array();
		foreach ( (array) $this->config()['patterns'] as $p ) {
			$m = array();
			if ( Patterns::match( (string) $p, $obj->html, $m ) ) {
				$found[] = array(
					'pattern' => (string) $p,
					'sample'  => Text::snippet( $m[0], 80 ),
				);
			}
		}
		return $found ? $this->found( $found ) : null;
	}
}

/** Markdown biçimlemesi HTML'e dönüşmemiş. */
final class MarkdownLeftovers extends Rule {

	public function id(): string {
		return 'markdown_leftovers';
	}
	public function group(): string {
		return 'content';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Markdown kalıntısı', 'contenthealth' );
	}
	public function description(): string {
		return __( 'HTML\'e dönüşmemiş **kalın**, ### başlık, kod çiti, [metin](adres) bağlantısı ve "* madde" satırları. Kod blokları sayılmaz.', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_markdown_links';
	}
	public function defaults(): array {
		return array(
			'enabled'      => true,
			'check_bold'   => true,
			'check_heading' => true,
			'check_bullets' => true,
		);
	}

	public const RE_LINK = '~\[(`?)([^\[\]\n]{1,200}?)\1\]\(\s*((?:https?://|/)[^)\s]+)\s*\)~u';
	public const RE_BOLD = '~(?<![*\\\\])\*\*(?!\s)([^*\n<>]{1,200}?)(?<!\s)\*\*(?!\*)~u';

	public function check( object $obj, Ctx $ctx ): ?array {
		$cfg   = $this->config();
		$prose = Text::strip_code( $obj->html );
		$items = array();
		if ( preg_match_all( self::RE_LINK, $prose, $m ) ) {
			$items[] = array(
				'kind'   => 'link',
				'count'  => count( $m[0] ),
				'sample' => Text::snippet( $m[0][0], 100 ),
			);
		}
		if ( ! empty( $cfg['check_bold'] ) && preg_match_all( self::RE_BOLD, $prose, $m ) ) {
			$items[] = array(
				'kind'   => 'bold',
				'count'  => count( $m[0] ),
				'sample' => Text::snippet( $m[0][0], 100 ),
			);
		}
		if ( false !== strpos( $prose, '```' ) ) {
			$items[] = array(
				'kind'   => 'fence',
				'count'  => substr_count( $prose, '```' ),
				'sample' => '```',
			);
		}
		$h = 0;
		$b = 0;
		foreach ( Text::segments( $prose ) as $s ) {
			if ( ! empty( $cfg['check_heading'] ) && preg_match( '~^#{1,6}\s+\S~u', $s[2] ) ) {
				++$h;
			}
			if ( ! empty( $cfg['check_bullets'] ) && preg_match( '~^\*\s+\S~u', $s[2] ) ) {
				++$b;
			}
		}
		if ( $h ) {
			$items[] = array(
				'kind'   => 'heading',
				'count'  => $h,
				'sample' => '#',
			);
		}
		if ( $b ) {
			$items[] = array(
				'kind'   => 'bullet',
				'count'  => $b,
				'sample' => '* ',
			);
		}
		return $items ? $this->found( $items ) : null;
	}
}

/** Doldurulmamış yer tutucular. */
final class PlaceholderLeftovers extends Rule {

	public function id(): string {
		return 'placeholder_leftovers';
	}
	public function group(): string {
		return 'content';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Yer tutucu kalıntısı', 'contenthealth' );
	}
	public function description(): string {
		return __( '[Karakter 1], {{alan}}, Lorem ipsum ve "…eklenecektir" gibi doldurulmamış şablon metinleri. Otomatik düzeltilmez: neyle doldurulacağı editörün kararıdır.', 'contenthealth' );
	}
	public function defaults(): array {
		return array(
			'enabled'       => true,
			'bracket_names' => true,
			'patterns'      => array(
				'\{\{[^{}\n]{1,60}\}\}',
				'\blorem\s+ipsum\b',
				'\[(?:Karakter|Oyuncu|Yönetmen|Yazar|İsim|Adı|Name|Insert|Your|Buraya|Placeholder|Yer\s*tutucu|TODO|TBD)\b[^\]\n]{0,60}\]',
				'\(\s*[^)\n]{0,80}\b(?:eklenecektir|doldurulacaktır|eklenecek)\s*\)',
				'\[(?:\.{3}|…)\]',
			),
		);
	}

	public function check( object $obj, Ctx $ctx ): ?array {
		$plain = Text::plain( $obj->html );
		$items = array();
		foreach ( (array) $this->config()['patterns'] as $p ) {
			$m = array();
			if ( Patterns::match( (string) $p, $plain, $m ) ) {
				$items[] = array( 'sample' => Text::snippet( $m[0], 80 ) );
			}
		}
		if ( ! empty( $this->config()['bracket_names'] ) && preg_match_all( '~\[([^\[\]\n]{2,30})\](?![(\[])~u', $plain, $mm ) ) {
			foreach ( $mm[1] as $i => $name ) {
				$first = trim( explode( ' ', $name )[0] );
				if ( shortcode_exists( $first ) || mb_strtoupper( $name ) === $name || ! preg_match( '~^\p{Lu}~u', $name ) || preg_match( '~^[\d\s.,:/-]+$~', $name ) ) {
					continue;
				}
				$items[] = array( 'sample' => Text::snippet( $mm[0][ $i ], 80 ) );
			}
		}
		return $items ? $this->found( $items ) : null;
	}
}

/** Bozuk karakter kodlaması (mojibake). */
final class EncodingGarbage extends Rule {

	public function id(): string {
		return 'encoding_garbage';
	}
	public function group(): string {
		return 'content';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Bozuk karakter kodlaması', 'contenthealth' );
	}
	public function description(): string {
		return __( 'â€™, Ã¼, ÅŸ, � gibi UTF-8 metnin yanlış çözülmesiyle oluşan karakterler. Otomatik düzeltilmez.', 'contenthealth' );
	}
	public function defaults(): array {
		return array(
			'enabled'  => true,
			'patterns' => array( 'â€[™œ”˜“¦\x{201A}\x{2122}\x{0153}\x{201C}\x{201D}\x{02DC}]', 'Ã[¼¶§±¤©¨¢«]', 'Ä[±°Ÿ]', 'Å[Ÿž]', '\x{FFFD}' ),
		);
	}

	public function check( object $obj, Ctx $ctx ): ?array {
		$items = array();
		$plain = Text::plain( $obj->html );
		foreach ( (array) $this->config()['patterns'] as $p ) {
			$m = array();
			if ( Patterns::match( (string) $p, $plain, $m ) ) {
				$items[] = array( 'sample' => Text::snippet( $m[0], 40 ) );
			}
		}
		return $items ? $this->found( $items ) : null;
	}
}

/** LaTeX puan/oran kalıntısı. */
final class LatexLeftovers extends Rule {

	public function id(): string {
		return 'latex_leftovers';
	}
	public function group(): string {
		return 'content';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'LaTeX kalıntısı', 'contenthealth' );
	}
	public function description(): string {
		return __( '$5.3/10$ gibi dolar ayraçlı sayı/puan ve \\frac, \\times komutları.', 'contenthealth' );
	}
	public function fix_action(): ?string {
		return 'fix_latex_score';
	}
	public function defaults(): array {
		return array(
			'enabled'  => true,
			'patterns' => array(
				'\$\d+(?:[.,]\d+)?(?:\s*/\s*\d+(?:[.,]\d+)?)?\$',
				'\\\\(?:frac|times|cdot|approx|text)\b',
			),
		);
	}

	public function check( object $obj, Ctx $ctx ): ?array {
		$items = array();
		$prose = Text::plain( $obj->html );
		foreach ( (array) $this->config()['patterns'] as $p ) {
			$m = array();
			if ( Patterns::match( (string) $p, $prose, $m ) ) {
				$items[] = array( 'sample' => Text::snippet( $m[0], 60 ) );
			}
		}
		return $items ? $this->found( $items ) : null;
	}
}

/** Aynı yazıda yinelenen uzun paragraf. */
final class DuplicateParagraphs extends Rule {

	public function id(): string {
		return 'duplicate_paragraphs';
	}
	public function group(): string {
		return 'content';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Yinelenen paragraf', 'contenthealth' );
	}
	public function description(): string {
		return __( 'Aynı yazıda birebir tekrar eden uzun paragraflar (kopyala-yapıştır hatası).', 'contenthealth' );
	}
	public function defaults(): array {
		return array(
			'enabled'  => true,
			'min_len'  => 80,
		);
	}

	public function check( object $obj, Ctx $ctx ): ?array {
		$min  = max( 20, (int) $this->config()['min_len'] );
		$seen = array();
		$dups = array();
		foreach ( Text::segments( Text::strip_code( $obj->html ) ) as $s ) {
			if ( Text::len( $s[2] ) < $min ) {
				continue;
			}
			$k = md5( $s[2] );
			if ( isset( $seen[ $k ] ) ) {
				$dups[ $k ] = Text::snippet( $s[2], 100 );
			}
			$seen[ $k ] = true;
		}
		return $dups ? $this->found( array_values( array_map( static fn( $t ) => array( 'text' => $t ), $dups ) ) ) : null;
	}
}

/** Boş başlık etiketi. */
final class EmptyHeadings extends Rule {

	public function id(): string {
		return 'empty_headings';
	}
	public function group(): string {
		return 'content';
	}
	public function severity(): string {
		return Rule::WARNING;
	}
	public function label(): string {
		return __( 'Boş başlık', 'contenthealth' );
	}
	public function description(): string {
		return __( 'İçinde metin olmayan <h1>–<h6> etiketleri.', 'contenthealth' );
	}

	public function check( object $obj, Ctx $ctx ): ?array {
		if ( preg_match_all( '~<h([1-6])\b[^>]*>(?:\s|&nbsp;|<br\s*/?>)*</h\1>~i', $obj->html, $m ) ) {
			return $this->found( array_map( static fn( $x ) => array( 'tag' => $x ), $m[0] ) );
		}
		return null;
	}
}
