<?php
/**
 * Yönetim arayüzü yardımcıları: rbn-admin-ui kitinin ortak parçaları (ikon, rozet, not, boş durum, sayfalama).
 * Tüm çıktı burada kaçışlanır; çağıranlar yalnız hazır HTML parçası alır.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class Ui {

	/** SVG sembol sayfasını bir kez basar (kitin ikonları `<use>` ile bu sembollere bakar). */
	public static function sprite(): void {
		\AdminKit\Helpers\Icons::sprite( CONTENTHEALTH_DIR . 'assets/admin/sprite.svg' );
	}

	public static function icon( string $name ): string {
		return \AdminKit\Helpers\Icons::icon( $name );
	}

	/** Koyu ton: WP "Gece yarısı" şeması ya da koyu mod eklentisi; süzgeçle ezilebilir. */
	public static function tone(): string {
		$dark = false;
		if ( function_exists( 'get_user_option' ) ) {
			$dark = 'midnight' === (string) get_user_option( 'admin_color' );
		}
		if ( defined( 'WP_DARK_MODE_VERSION' ) || defined( 'WP_DARK_MODE_PLUGIN_FILE' ) ) {
			$dark = true;
		}
		$tone = (string) apply_filters( 'contenthealth_tone', $dark ? 'koyu' : 'acik' );
		return 'koyu' === $tone ? 'koyu' : 'acik';
	}

	public static function tone_attr(): string {
		return 'koyu' === self::tone() ? ' data-tone="koyu"' : '';
	}

	public static function url( array $args = array() ): string {
		return \AdminKit\Helpers\Menu::page_url( Admin::SLUG, $args );
	}

	/** 0–100 puanı bandına çevirir. @return array{key:string,label:string,icon:string} */
	public static function band( int $score ): array {
		if ( $score >= 80 ) {
			return array( 'key' => 'ok', 'label' => __( 'Good', 'contenthealth' ), 'icon' => 'check' );
		}
		if ( $score >= 50 ) {
			return array( 'key' => 'warn', 'label' => __( 'Fair', 'contenthealth' ), 'icon' => 'alert' );
		}
		return array( 'key' => 'err', 'label' => __( 'Poor', 'contenthealth' ), 'icon' => 'octo' );
	}

	/** Ciddiyet → kit sınıfı/ikonu. @return array{cls:string,icon:string,label:string} */
	public static function sev( string $severity ): array {
		$labels = Registry::severities();
		$map    = array(
			Rule::CRITICAL => array( 'crit', 'octo' ),
			Rule::WARNING  => array( 'warn', 'alert' ),
			Rule::INFO     => array( 'info', 'info' ),
		);
		$m      = $map[ $severity ] ?? $map[ Rule::INFO ];
		return array(
			'cls'   => $m[0],
			'icon'  => $m[1],
			'label' => $labels[ $severity ] ?? $severity,
		);
	}

	/** Rozet: renk tek başına bilgi taşımaz, ikon + metin birlikte. */
	public static function badge( string $kind, string $icon, string $text ): string {
		return '<span class="rbn-badge rbn-badge--' . esc_attr( $kind ) . '">' . self::icon( $icon ) . esc_html( $text ) . '</span>';
	}

	public static function sev_badge( string $severity ): string {
		$s = self::sev( $severity );
		return self::badge( $s['cls'], $s['icon'], $s['label'] );
	}

	/**
	 * Bilgi/uyarı notu.
	 *
	 * @param string $kind    ok|info|warn|err.
	 * @param string $inner   Hazır (güvenli) HTML.
	 * @param string $actions Hazır HTML düğmeler.
	 * @param array<string,string> $attrs Ek öznitelikler (role vb.).
	 */
	public static function note( string $kind, string $inner, string $actions = '', array $attrs = array() ): string {
		$icons = array(
			'ok'   => 'check',
			'info' => 'info',
			'warn' => 'alert',
			'err'  => 'octo',
		);
		$a     = '';
		foreach ( $attrs as $k => $v ) {
			$a .= ' ' . esc_attr( $k ) . ( '' === $v ? '' : '="' . esc_attr( $v ) . '"' );
		}
		return '<div class="rbn-note rbn-note--' . esc_attr( $kind ) . '"' . $a . '>' . self::icon( $icons[ $kind ] ?? 'info' ) . '<div><p>' . $inner . '</p>' . ( '' !== $actions ? '<div class="rbn-note__act">' . $actions . '</div>' : '' ) . '</div></div>';
	}

	/** @param string $actions Hazır HTML. */
	public static function empty_state( string $icon, string $title, string $text, string $actions = '' ): string {
		return '<div class="rbn-empty"><span class="rbn-empty__i">' . self::icon( $icon ) . '</span><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>' . $actions . '</div>';
	}

	public static function date( ?string $gmt ): string {
		if ( ! $gmt ) {
			return '—';
		}
		return esc_html( wp_date( 'j M Y', (int) strtotime( $gmt . ' UTC' ) ) );
	}

	public static function datetime( ?string $gmt ): string {
		if ( ! $gmt ) {
			return '—';
		}
		return esc_html( wp_date( 'j M Y, H:i', (int) strtotime( $gmt . ' UTC' ) ) );
	}

	/** Yazı/etiket nesnesi için "Yazı #388" gibi alt başlık. */
	public static function object_sub( object $issue ): string {
		if ( 'term' === $issue->object_type ) {
			/* translators: %d: term id */
			return sprintf( __( 'Tag #%d', 'contenthealth' ), $issue->object_id );
		}
		$type = get_post_type( $issue->object_id );
		$obj  = $type ? get_post_type_object( $type ) : null;
		/* translators: 1: content type label, 2: id */
		return sprintf( __( '%1$s #%2$d', 'contenthealth' ), $obj ? $obj->labels->singular_name : __( 'Post', 'contenthealth' ), $issue->object_id );
	}

	public static function object_title( object $issue ): string {
		if ( 'term' === $issue->object_type ) {
			$t = get_term( $issue->object_id );
			return $t && ! is_wp_error( $t ) ? (string) $t->name : '#' . $issue->object_id;
		}
		$title = get_the_title( $issue->object_id );
		return '' !== $title ? $title : '#' . $issue->object_id;
	}

	/** Bulgu ayrıntısından tek satırlık kanıt metni (ham). */
	public static function evidence( object $issue ): string {
		$d = $issue->details_arr;
		if ( isset( $d['items'][0] ) ) {
			$first = $d['items'][0];
			$txt   = is_array( $first ) ? (string) reset( $first ) : (string) $first;
			return Text::snippet( $txt, 90 );
		}
		if ( isset( $d['reasons'] ) && is_array( $d['reasons'] ) ) {
			return implode( ', ', array_keys( $d['reasons'] ) );
		}
		if ( isset( $d['name'] ) ) {
			return Text::snippet( (string) $d['name'], 90 );
		}
		return Text::snippet( (string) wp_json_encode( $d, JSON_UNESCAPED_UNICODE ), 90 );
	}

	/**
	 * Sayfalama (kit .rbn-pager).
	 *
	 * @param array<string,mixed> $args Sayfa bağlantılarına taşınacak sorgu parametreleri.
	 */
	public static function pager( int $total, int $per, int $page, array $args ): string {
		$pages = max( 1, (int) ceil( $total / max( 1, $per ) ) );
		if ( $total <= 0 ) {
			return '';
		}
		$from = ( $page - 1 ) * $per + 1;
		$to   = min( $total, $page * $per );
		$link = static function ( int $p ) use ( $args ): string {
			return esc_url( self::url( array_merge( $args, array( 'sayfa' => $p ) ) ) );
		};
		$li   = '<li>' . ( $page > 1
			? '<a href="' . $link( $page - 1 ) . '">' . self::icon( 'chevl' ) . '<span class="rbn-sr">' . esc_html__( 'Previous page', 'contenthealth' ) . '</span></a>'
			: '<span class="ch-pg" aria-disabled="true">' . self::icon( 'chevl' ) . '<span class="rbn-sr">' . esc_html__( 'Previous page', 'contenthealth' ) . '</span></span>' ) . '</li>';
		$show = array_unique( array_filter( array( 1, $page - 1, $page, $page + 1, $pages ), static fn( $p ) => $p >= 1 && $p <= $pages ) );
		sort( $show );
		$prev = 0;
		foreach ( $show as $p ) {
			if ( $p - $prev > 1 ) {
				$li .= '<li><span class="ch-pg" aria-hidden="true">…</span></li>';
			}
			$li  .= '<li><a href="' . $link( $p ) . '"' . ( $p === $page ? ' aria-current="page"' : '' ) . '><span class="rbn-sr">' . esc_html__( 'Page', 'contenthealth' ) . ' </span>' . (int) $p . '</a></li>';
			$prev = $p;
		}
		$li .= '<li>' . ( $page < $pages
			? '<a href="' . $link( $page + 1 ) . '">' . self::icon( 'chevr' ) . '<span class="rbn-sr">' . esc_html__( 'Next page', 'contenthealth' ) . '</span></a>'
			: '<span class="ch-pg" aria-disabled="true">' . self::icon( 'chevr' ) . '<span class="rbn-sr">' . esc_html__( 'Next page', 'contenthealth' ) . '</span></span>' ) . '</li>';
		return '<nav class="rbn-pager" aria-label="' . esc_attr__( 'Pagination', 'contenthealth' ) . '"><p class="rbn-pager__n">' . (int) $from . '–' . (int) $to . ' / ' . (int) $total . '</p><ul>' . $li . '</ul></nav>';
	}

	/** Otomatik düzeltme eyleminin kullanıcıya gösterilen tek cümlelik açıklaması. */
	public static function fix_text( ?string $action ): string {
		$map = array(
			'fix_ai_chat_residue'   => __( 'Removes only the chat intro and outro; the rest of the post is not touched.', 'contenthealth' ),
			'fix_ai_ui_artifacts'   => __( 'Cleans interface-leftover attributes and wrappers; the content stays.', 'contenthealth' ),
			'fix_markdown_links'    => __( 'Converts markdown marks to their HTML equivalents.', 'contenthealth' ),
			'fix_latex_score'       => __( 'Converts LaTeX score leftovers to plain text.', 'contenthealth' ),
			'fix_bad_host_url'      => __( 'Replaces wrong / development addresses with the site\'s real address.', 'contenthealth' ),
			'fix_alt_from_title'    => __( 'Fills empty alt texts from the template; existing ones are not touched.', 'contenthealth' ),
			'fix_term_delete_empty' => __( 'Deletes tags that are attached to no content.', 'contenthealth' ),
			'fix_term_merge'        => __( 'Merges duplicate tags into the chosen canonical tag.', 'contenthealth' ),
			'fix_term_noindex'      => __( 'Removes the single-item tag from the search engine index.', 'contenthealth' ),
			'fix_term_rename'       => __( 'Cleans up the tag name.', 'contenthealth' ),
		);
		return $action && isset( $map[ $action ] ) ? $map[ $action ] : __( 'No automatic fix; open the post and fix it by hand.', 'contenthealth' );
	}

	/**
	 * Önce/sonra karşılaştırması (kit .ch-diff). Satırlar Transform::diff çıktısıdır.
	 *
	 * @param array<int,array<string,mixed>> $changes Fixer değişiklik listesi (field, removed[], added[]).
	 */
	public static function diff( array $changes, string $id, string $label = '' ): string {
		$label = '' !== $label ? $label : __( 'Preview: before / after', 'contenthealth' );
		$row   = static function ( string $kind, string $text ): string {
			$sr  = 'del' === $kind ? __( 'Deleted line: ', 'contenthealth' ) : __( 'Added line: ', 'contenthealth' );
			$sym = 'del' === $kind ? '−' : '+';
			return '<div class="ch-diff__r ch-diff__r--' . $kind . '"><span class="ch-diff__n" aria-hidden="true"></span><span class="ch-diff__s" aria-hidden="true">' . $sym . '</span><span class="ch-diff__c"><span class="rbn-sr">' . esc_html( $sr ) . '</span><' . ( 'del' === $kind ? 'del' : 'ins' ) . '>' . esc_html( $text ) . '</' . ( 'del' === $kind ? 'del' : 'ins' ) . '></span></div>';
		};
		$rem    = array();
		$add    = array();
		$fields = array();
		foreach ( $changes as $c ) {
			$fields[ (string) ( $c['field'] ?? '' ) ] = true;
			foreach ( (array) ( $c['removed'] ?? array() ) as $x ) {
				$rem[] = (string) $x;
			}
			foreach ( (array) ( $c['added'] ?? array() ) as $x ) {
				$add[] = (string) $x;
			}
		}
		$inline = '';
		$left   = '';
		$right  = '';
		foreach ( $rem as $x ) {
			$inline .= $row( 'del', $x );
			$left   .= $row( 'del', $x );
		}
		foreach ( $add as $x ) {
			$inline .= $row( 'add', $x );
			$right  .= $row( 'add', $x );
		}
		$names = implode( ', ', array_filter( array_keys( $fields ) ) );
		/* translators: 1: removed line count, 2: added line count */
		$sum = '<span>− ' . (int) count( $rem ) . ' ' . esc_html__( 'line', 'contenthealth' ) . '</span><span>+ ' . (int) count( $add ) . ' ' . esc_html__( 'line', 'contenthealth' ) . '</span>' . ( '' !== $names ? '<span>' . esc_html__( 'Changed field:', 'contenthealth' ) . ' ' . (int) count( $fields ) . ' (' . esc_html( $names ) . ')</span>' : '' );
		return '<div class="ch-diff" data-diff id="' . esc_attr( $id ) . '" role="group" aria-label="' . esc_attr( $label ) . '"><div class="ch-diff__hd"><b>' . esc_html( $label ) . '</b>' . ( '' !== $names ? '<span class="rbn-muted"><code>' . esc_html( $names ) . '</code></span>' : '' ) . '</div><div class="ch-diff__pane" data-pane="inline">' . $inline . '</div><div class="ch-diff__pane" data-pane="split" hidden><div><p class="ch-diff__lab">' . esc_html__( 'Before', 'contenthealth' ) . '</p>' . $left . '</div><div><p class="ch-diff__lab">' . esc_html__( 'After', 'contenthealth' ) . '</p>' . $right . '</div></div><p class="ch-diff__sum">' . $sum . '</p></div>';
	}
}
