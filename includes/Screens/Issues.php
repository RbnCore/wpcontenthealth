<?php
/**
 * Bulgular: süzgeç, liste, toplu eylem çubuğu; ayrıntı çekmecesi, tek düzeltme onayı ve toplu düzeltme sihirbazı.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Screens;

use ContentHealth\Fixer;
use ContentHealth\Issues as IssueStore;
use ContentHealth\Registry;
use ContentHealth\Rule;
use ContentHealth\Settings;
use ContentHealth\Ui;

defined( 'ABSPATH' ) || exit;

final class Issues {

	public const PER_PAGE = 20;

	private const STATUS = array(
		'acik'       => 'open',
		'yoksayildi' => 'ignored',
		'duzeltildi' => 'fixed',
		'tum'        => '',
	);

	/** Sekme sayacı: açık bulgu toplamı. */
	public static function count(): int {
		$n = 0;
		foreach ( IssueStore::open_counts() as $types ) {
			$n += array_sum( $types );
		}
		return $n;
	}

	/**
	 * GET parametrelerinden Issues::query süzgeci.
	 *
	 * @param array<string,mixed> $src
	 * @return array<string,mixed>
	 */
	public static function filters_from( array $src ): array {
		$get    = static fn( string $k ): string => isset( $src[ $k ] ) && is_scalar( $src[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $src[ $k ] ) ) : '';
		$durum  = $get( 'durum' );
		$status = array_key_exists( $durum, self::STATUS ) ? self::STATUS[ $durum ] : 'open';
		$f      = array( 'status' => $status );
		$group  = sanitize_key( $get( 'grup' ) );
		if ( '' !== $group && isset( Registry::groups()[ $group ] ) ) {
			$f['group'] = $group;
		}
		$rule = sanitize_key( $get( 'kural' ) );
		if ( '' !== $rule && Registry::get( $rule ) ) {
			$f['rule'] = $rule;
		}
		$sev = sanitize_key( $get( 'ciddiyet' ) );
		if ( in_array( $sev, array( Rule::CRITICAL, Rule::WARNING, Rule::INFO ), true ) ) {
			$f['severity'] = $sev;
		}
		$q = $get( 'ara' );
		if ( '' !== $q ) {
			$f['search'] = mb_substr( $q, 0, 100 );
		}
		return $f;
	}

	/** @param array<string,mixed> $f */
	private static function url_args( array $f ): array {
		$rev  = array_flip( array_filter( self::STATUS, static fn( $v ) => '' !== $v ) );
		$args = array( 'tab' => 'issues' );
		if ( '' === $f['status'] ) {
			$args['durum'] = 'tum';
		} elseif ( 'open' !== $f['status'] ) {
			$args['durum'] = $rev[ $f['status'] ] ?? 'tum';
		}
		foreach ( array( 'grup' => 'group', 'kural' => 'rule', 'ciddiyet' => 'severity', 'ara' => 'search' ) as $k => $key ) {
			if ( isset( $f[ $key ] ) ) {
				$args[ $k ] = $f[ $key ];
			}
		}
		return $args;
	}

	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- yalnız okuma süzgeci.
		$f    = self::filters_from( $_GET );
		$page = max( 1, isset( $_GET['sayfa'] ) ? (int) $_GET['sayfa'] : 1 );
		$open = isset( $_GET['ac'] ) ? (int) $_GET['ac'] : 0;
		// phpcs:enable
		$res  = IssueStore::query( array_merge( $f, array( 'per_page' => self::PER_PAGE, 'page' => $page ) ) );
		$args = self::url_args( $f );
		$any  = IssueStore::query( array( 'status' => '', 'per_page' => 1 ) )['total'];

		echo '<div class="rbn-note rbn-note--ok" role="status" id="flash" hidden></div>';
		echo '<section class="rbn-card" aria-labelledby="h-list"><div class="rbn-card__h"><h2 id="h-list">' . esc_html__( 'Bulgular', 'contenthealth' ) . ' <span class="rbn-count" style="vertical-align:middle">' . (int) $res['total'] . '</span></h2><p>' . esc_html__( 'Tarama sonucunda bulunan içerik sorunları. Bir satırı açarak kanıtı ve önerilen düzeltmeyi görün.', 'contenthealth' ) . '</p></div>';

		if ( 0 === $any ) {
			echo '<div class="rbn-card__b rbn-card__b--pad">' . Ui::empty_state( 'scan', __( 'Henüz bulgu yok', 'contenthealth' ), __( 'Henüz tarama yapılmadı ya da tarama hiçbir sorun bulmadı. Taramayı başlatın.', 'contenthealth' ), '<button type="button" class="rbn-btn rbn-btn--pri" data-scan>' . Ui::icon( 'scan' ) . esc_html__( 'Şimdi tara', 'contenthealth' ) . '</button>' ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		self::filter_form( $f );
		self::chips( $f, $args, (int) $res['total'] );

		if ( ! $res['rows'] ) {
			echo '<div class="rbn-card__b rbn-card__b--pad">' . Ui::empty_state( 'shield', __( 'Bu süzgece uyan bulgu yok', 'contenthealth' ), __( 'Süzgeçleri gevşetin ya da temizleyin.', 'contenthealth' ), '<a class="rbn-btn" href="' . esc_url( Ui::url( array( 'tab' => 'issues' ) ) ) . '">' . esc_html__( 'Süzgeçleri temizle', 'contenthealth' ) . '</a>' ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$export = wp_nonce_url( add_query_arg( array_merge( $args, array( 'action' => 'contenthealth_export' ) ), admin_url( 'admin-post.php' ) ), 'contenthealth_export' );
		echo '<form method="post" id="bulkform" action="' . esc_url( Ui::url( $args ) ) . '">';
		wp_nonce_field( 'contenthealth_bulk', 'ch_bulk_nonce' );
		echo '<div class="rbn-bulk" id="bulk" role="region" aria-label="' . esc_attr__( 'Toplu eylemler', 'contenthealth' ) . '"><p id="bulk-n"><span data-n>0</span> ' . esc_html__( 'bulgu seçili', 'contenthealth' ) . '</p><div class="rbn-bulk__act">';
		echo '<button type="button" class="rbn-btn rbn-btn--pri rbn-btn--sm" data-open="dlg-wiz" data-wiz-open>' . Ui::icon( 'wand' ) . esc_html__( 'Seçilenleri düzelt…', 'contenthealth' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="submit" class="rbn-btn rbn-btn--sm" name="ch_act" value="ignore" data-bulk-ignore>' . esc_html__( 'Yok say', 'contenthealth' ) . '</button>';
		echo '<a class="rbn-btn rbn-btn--sm" href="' . esc_url( $export ) . '">' . Ui::icon( 'down' ) . esc_html__( 'CSV indir', 'contenthealth' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="button" class="rbn-btn rbn-btn--sm rbn-btn--link" data-bulk-clear>' . esc_html__( 'Seçimi kaldır', 'contenthealth' ) . '</button></div></div>';

		$pages = max( 1, (int) ceil( $res['total'] / self::PER_PAGE ) );
		/* translators: 1: current page, 2: page count */
		$cap = sprintf( __( 'Bulgu listesi, sayfa %1$d / %2$d', 'contenthealth' ), $page, $pages );
		echo '<div class="rbn-tw"><table class="rbn-table rbn-table--stack rbn-table--sel" id="tbl"' . ( $open ? ' data-autoopen="' . (int) $open . '"' : '' ) . '><caption class="rbn-sr">' . esc_html( $cap ) . '</caption>';
		echo '<thead><tr><th scope="col" class="rbn-ck"><label class="rbn-chk"><input type="checkbox" data-select-all aria-label="' . esc_attr__( 'Bu sayfadaki tüm bulguları seç', 'contenthealth' ) . '"></label></th><th scope="col">' . esc_html__( 'Nesne', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Kural', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Kanıt', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Ciddiyet', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Tarih', 'contenthealth' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Eylemler', 'contenthealth' ) . '</span></th></tr></thead><tbody>';
		$groups = Registry::groups();
		foreach ( $res['rows'] as $r ) {
			$rule  = Registry::get( $r->rule_id );
			$title = Ui::object_title( $r );
			$s     = Ui::sev( $r->severity );
			printf(
				'<tr data-id="%d" data-obj="%d" data-rule="%s" data-fix="%s" data-sev="%s" data-title="%s"><td class="rbn-ck"><label class="rbn-chk"><input type="checkbox" name="ids[]" value="%d" aria-label="%s"></label></td>',
				(int) $r->id,
				(int) $r->object_id,
				esc_attr( $r->rule_id ),
				esc_attr( (string) ( $rule ? $rule->fix_action() : '' ) ),
				esc_attr( $s['cls'] ),
				esc_attr( $title ),
				(int) $r->id,
				/* translators: %s: object title */
				esc_attr( sprintf( __( 'Seç: %s', 'contenthealth' ), $title ) )
			);
			echo '<th scope="row"><span class="rbn-obj">' . esc_html( $title ) . '</span><span class="rbn-sub">' . esc_html( Ui::object_sub( $r ) ) . '</span></th>';
			echo '<td data-l="' . esc_attr__( 'Kural', 'contenthealth' ) . '">' . esc_html( $rule ? $rule->label() : $r->rule_id ) . '<span class="rbn-sub">' . esc_html( (string) ( $groups[ $r->group_id ] ?? $r->group_id ) ) . '</span></td>';
			echo '<td data-l="' . esc_attr__( 'Kanıt', 'contenthealth' ) . '"><span class="rbn-ev"><mark>' . esc_html( Ui::evidence( $r ) ) . '</mark></span></td>';
			echo '<td data-l="' . esc_attr__( 'Ciddiyet', 'contenthealth' ) . '">' . Ui::sev_badge( $r->severity ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td data-l="' . esc_attr__( 'Tarih', 'contenthealth' ) . '" class="rbn-nowrap">' . Ui::date( $r->last_seen ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><div class="rbn-acts"><button type="button" class="rbn-btn rbn-btn--sm" data-open="dlg-detay" data-detail="' . (int) $r->id . '" aria-label="' . esc_attr( sprintf( /* translators: %s: object title */ __( 'Ayrıntı: %s', 'contenthealth' ), $title ) ) . '">' . esc_html__( 'Ayrıntı', 'contenthealth' ) . '</button>';
			if ( 'open' === $r->status ) {
				echo '<button type="submit" class="rbn-btn rbn-btn--sm rbn-btn--link" name="one" value="' . (int) $r->id . '" data-ignore aria-label="' . esc_attr( sprintf( /* translators: %s: object title */ __( 'Yok say: %s', 'contenthealth' ), $title ) ) . '">' . esc_html__( 'Yok say', 'contenthealth' ) . '</button>';
			} elseif ( 'ignored' === $r->status ) {
				echo '<button type="submit" class="rbn-btn rbn-btn--sm rbn-btn--link" name="reopen" value="' . (int) $r->id . '">' . esc_html__( 'Yeniden aç', 'contenthealth' ) . '</button>';
			}
			echo '</div></td></tr>';
		}
		echo '</tbody></table></div></form>';
		echo Ui::pager( (int) $res['total'], self::PER_PAGE, $page, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';
	}

	/** @param array<string,mixed> $f */
	private static function filter_form( array $f ): void {
		$sel = static function ( string $id, string $label, array $opts, string $cur ): void {
			echo '<div><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select class="rbn-select" id="' . esc_attr( $id ) . '" name="' . esc_attr( substr( $id, 2 ) ) . '">';
			foreach ( $opts as $v => $l ) {
				echo '<option value="' . esc_attr( (string) $v ) . '"' . selected( $cur, (string) $v, false ) . '>' . esc_html( (string) $l ) . '</option>';
			}
			echo '</select></div>';
		};
		$rules = array( '' => __( 'Tümü', 'contenthealth' ) );
		foreach ( Registry::rules() as $r ) {
			$rules[ $r->id() ] = $r->label();
		}
		$rev = array_flip( array_filter( self::STATUS, static fn( $v ) => '' !== $v ) );
		echo '<form class="rbn-filter" role="search" method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '" aria-label="' . esc_attr__( 'Bulguları süz', 'contenthealth' ) . '"><input type="hidden" name="page" value="' . esc_attr( \ContentHealth\Admin::SLUG ) . '"><input type="hidden" name="tab" value="issues">';
		$sel( 'f-grup', __( 'Grup', 'contenthealth' ), array( '' => __( 'Tümü', 'contenthealth' ) ) + Registry::groups(), (string) ( $f['group'] ?? '' ) );
		$sel( 'f-kural', __( 'Kural', 'contenthealth' ), $rules, (string) ( $f['rule'] ?? '' ) );
		$sel( 'f-ciddiyet', __( 'Ciddiyet', 'contenthealth' ), array( '' => __( 'Tümü', 'contenthealth' ) ) + Registry::severities(), (string) ( $f['severity'] ?? '' ) );
		$sel(
			'f-durum',
			__( 'Durum', 'contenthealth' ),
			array(
				'acik'       => __( 'Açık', 'contenthealth' ),
				'yoksayildi' => __( 'Yok sayıldı', 'contenthealth' ),
				'duzeltildi' => __( 'Düzeltildi', 'contenthealth' ),
				'tum'        => __( 'Tümü', 'contenthealth' ),
			),
			'' === $f['status'] ? 'tum' : ( $rev[ $f['status'] ] ?? 'acik' )
		);
		echo '<div class="rbn-search"><label for="f-ara">' . esc_html__( 'Ara', 'contenthealth' ) . '</label>' . Ui::icon( 'search' ) . '<input class="rbn-input" type="search" id="f-ara" name="ara" value="' . esc_attr( (string) ( $f['search'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Başlık veya kanıt', 'contenthealth' ) . '"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="rbn-filter__act"><button type="submit" class="rbn-btn rbn-btn--pri">' . esc_html__( 'Süz', 'contenthealth' ) . '</button><a class="rbn-btn" href="' . esc_url( Ui::url( array( 'tab' => 'issues' ) ) ) . '">' . esc_html__( 'Temizle', 'contenthealth' ) . '</a></div></form>';
	}

	/**
	 * @param array<string,mixed> $f
	 * @param array<string,mixed> $args
	 */
	private static function chips( array $f, array $args, int $total ): void {
		$labels = array(
			'grup'     => array( __( 'Grup', 'contenthealth' ), Registry::groups()[ $f['group'] ?? '' ] ?? '' ),
			'kural'    => array( __( 'Kural', 'contenthealth' ), ( isset( $f['rule'] ) && Registry::get( $f['rule'] ) ) ? Registry::get( $f['rule'] )->label() : '' ),
			'ciddiyet' => array( __( 'Ciddiyet', 'contenthealth' ), Registry::severities()[ $f['severity'] ?? '' ] ?? '' ),
			'ara'      => array( __( 'Arama', 'contenthealth' ), (string) ( $f['search'] ?? '' ) ),
			'durum'    => array(
				__( 'Durum', 'contenthealth' ),
				array(
					'open'    => __( 'Açık', 'contenthealth' ),
					'ignored' => __( 'Yok sayıldı', 'contenthealth' ),
					'fixed'   => __( 'Düzeltildi', 'contenthealth' ),
					''        => __( 'Tümü', 'contenthealth' ),
				)[ $f['status'] ] ?? '',
			),
		);
		echo '<div class="rbn-chips" aria-label="' . esc_attr__( 'Etkin süzgeçler', 'contenthealth' ) . '"><span class="rbn-muted">' . esc_html__( 'Etkin süzgeç:', 'contenthealth' ) . '</span>';
		foreach ( $labels as $key => $pair ) {
			if ( '' === (string) $pair[1] ) {
				continue;
			}
			$rest = $args;
			unset( $rest[ $key ] );
			if ( 'durum' === $key ) {
				$rest['durum'] = 'acik' === ( $args['durum'] ?? 'acik' ) ? 'tum' : 'acik';
				if ( 'tum' === ( $args['durum'] ?? '' ) ) {
					$rest['durum'] = 'acik';
				}
			}
			$txt = $pair[0] . ': ' . $pair[1];
			echo '<span class="rbn-fchip">' . esc_html( $txt ) . '<a href="' . esc_url( Ui::url( $rest ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: filter text */ __( 'Süzgeci kaldır: %s', 'contenthealth' ), $txt ) ) . '">' . Ui::icon( 'x' ) . '</a></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		/* translators: %d: result count */
		echo '<span class="rbn-muted" style="margin-left:auto">' . esc_html( sprintf( _n( '%d sonuç', '%d sonuç', $total, 'contenthealth' ), $total ) ) . '</span></div>';
	}

	/**
	 * Çekmece gövdesi (REST `/issues/<id>/detail`).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function detail( int $id ): ?array {
		$issue = IssueStore::get( $id );
		if ( ! $issue ) {
			return null;
		}
		$rule  = Registry::get( $issue->rule_id );
		$fix   = $rule ? $rule->fix_action() : null;
		$title = Ui::object_title( $issue );
		$d     = $issue->details_arr;

		$body  = '<p style="margin:0 0 16px"><strong style="color:var(--rbn-text)">' . esc_html( $title ) . '</strong><br><span class="rbn-muted">' . esc_html( Ui::object_sub( $issue ) ) . ' · ' . Ui::date( $issue->last_seen ) . ' ' . esc_html__( 'tarandı', 'contenthealth' ) . '</span></p>';
		$body .= '<div class="rbn-sect"><h3>' . esc_html__( 'Kanıt', 'contenthealth' ) . '</h3>';
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? array_slice( $d['items'], 0, 8 ) : array();
		if ( $items ) {
			foreach ( $items as $it ) {
				$txt   = is_array( $it ) ? implode( ' · ', array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE ), $it ) ) : (string) $it;
				$body .= '<p style="margin:0 0 8px;color:var(--rbn-text)"><code style="display:block;padding:8px"><mark>' . esc_html( \ContentHealth\Text::snippet( $txt, 300 ) ) . '</mark></code></p>';
			}
		} else {
			$body .= '<p style="margin:0 0 8px;color:var(--rbn-text)"><code style="display:block;padding:8px"><mark>' . esc_html( Ui::evidence( $issue ) ) . '</mark></code></p>';
		}
		if ( isset( $d['count'] ) ) {
			$body .= '<ul class="rbn-sum" style="color:var(--rbn-muted)"><li>' . Ui::icon( 'check' ) . esc_html( sprintf( /* translators: %d: count */ __( 'Toplam bulunan: %d', 'contenthealth' ), (int) $d['count'] ) ) . '</li></ul>';
		}
		$body .= '</div>';
		$body .= '<div class="rbn-sect"><h3>' . esc_html__( 'Neden önemli?', 'contenthealth' ) . '</h3><p style="margin:0;color:var(--rbn-text)">' . esc_html( $rule ? $rule->description() : '' ) . '</p></div>';
		$body .= '<div class="rbn-sect"><h3>' . esc_html__( 'Önerilen düzeltme', 'contenthealth' ) . '</h3><p style="margin:0;color:var(--rbn-text)">' . esc_html( Ui::fix_text( $fix ) ) . '</p></div>';

		$preview = null;
		if ( $fix && 'open' === $issue->status && 'term_merge_skip' !== $fix && 'fix_term_merge' !== $fix ) {
			$res = Fixer::run_rule( $issue->rule_id, array( $issue->object_id ), true );
			if ( is_array( $res ) && ! empty( $res['items'][0]['changes'] ) ) {
				$preview = $res['items'][0]['changes'];
			}
		}
		if ( $preview ) {
			$body .= '<div class="rbn-sect"><div class="rbn-row rbn-row--between" style="margin-bottom:8px"><h3 style="margin:0">' . esc_html__( 'Önizleme: önce / sonra', 'contenthealth' ) . '</h3><div class="rbn-seg" role="radiogroup" aria-label="' . esc_attr__( 'Diff görünümü', 'contenthealth' ) . '"><label><input type="radio" name="diffmode" value="inline" checked><span>' . esc_html__( 'Satır içi', 'contenthealth' ) . '</span></label><label><input type="radio" name="diffmode" value="split"><span>' . esc_html__( 'Yan yana', 'contenthealth' ) . '</span></label></div></div>' . Ui::diff( $preview, 'diff-' . $id ) . '</div>';
			$body .= '<label class="rbn-check"><input type="checkbox" data-keep-mod' . checked( (bool) Settings::get( 'keep_modified' ), true, false ) . '><span><b>' . esc_html__( 'Son değiştirilme tarihi değişmesin', 'contenthealth' ) . '</b><span class="rbn-help">' . esc_html__( 'Yazı listesinde “güncellendi” görünmez; geri alma günlüğü önceki değeri saklar.', 'contenthealth' ) . '</span></span></label>';
		}

		$edit = '';
		if ( 'post' === $issue->object_type ) {
			$edit = (string) get_edit_post_link( $issue->object_id, 'raw' );
		} else {
			$edit = (string) get_edit_term_link( $issue->object_id );
		}
		$sev = Ui::sev( $issue->severity );
		return array(
			'id'        => $issue->id,
			'rule'      => $issue->rule_id,
			'ruleLabel' => $rule ? $rule->label() : $issue->rule_id,
			'badge'     => Ui::badge( $sev['cls'], $sev['icon'], $sev['label'] ),
			'title'     => $title,
			'html'      => $body,
			'edit'      => $edit,
			'fixable'   => (bool) ( $fix && 'open' === $issue->status && 'fix_term_merge' !== $fix ),
			'status'    => $issue->status,
			'object'    => $issue->object_id,
		);
	}

	public static function dialogs(): void {
		$x = '<button type="button" class="rbn-dlg__x" data-close aria-label="' . esc_attr__( 'Kapat', 'contenthealth' ) . '">' . Ui::icon( 'x' ) . '</button>';
		$t = Ui::tone_attr();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui/esc_* ile kaçışlı.
		echo '<dialog class="rbn-dlg rbn-dlg--drawer" id="dlg-detay"' . $t . ' aria-labelledby="dlg-detay-t"><div class="rbn-dlg__h"><div><p style="margin:0 0 4px;display:flex;gap:8px;flex-wrap:wrap;align-items:center" data-d-badge></p><h2 id="dlg-detay-t" data-d-title>' . esc_html__( 'Ayrıntı', 'contenthealth' ) . '</h2></div>' . $x . '</div><div class="rbn-dlg__b" data-d-body></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn rbn-btn--link" data-ignore-detail>' . esc_html__( 'Yok say', 'contenthealth' ) . '</button><a class="rbn-btn" href="#" target="_blank" rel="noopener" style="margin-right:auto" data-d-edit>' . esc_html__( 'Yazıyı aç', 'contenthealth' ) . Ui::icon( 'out' ) . '</a><button type="button" class="rbn-btn rbn-btn--pri" data-open="dlg-onay" data-d-fix>' . esc_html__( 'Düzelt…', 'contenthealth' ) . '</button></div></dialog>';

		echo '<dialog class="rbn-dlg" id="dlg-onay"' . $t . ' aria-labelledby="dlg-onay-t" aria-describedby="dlg-onay-d"><div class="rbn-dlg__b"><h2 id="dlg-onay-t">' . esc_html__( 'Bu düzeltme 1 içeriği değiştirecek', 'contenthealth' ) . '</h2><p id="dlg-onay-d">' . esc_html__( 'Kuru koşu yapıldı; değişiklik uygulanacak ve geri alma günlüğüne yazılacak.', 'contenthealth' ) . '</p><ul class="rbn-sum" style="color:var(--rbn-text)" data-onay-list></ul></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-close>' . esc_html__( 'Vazgeç', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-apply-one>' . esc_html__( 'Düzeltmeyi uygula', 'contenthealth' ) . '</button></div></dialog>';

		echo '<dialog class="rbn-dlg rbn-dlg--wide" id="dlg-wiz"' . $t . ' aria-labelledby="dlg-wiz-t" data-wiz><div class="rbn-dlg__h"><div><h2 id="dlg-wiz-t">' . esc_html__( 'Toplu düzeltme', 'contenthealth' ) . '</h2></div>' . $x . '</div><div class="rbn-dlg__b">';
		echo '<ol class="rbn-stepper" style="--n:4" aria-label="' . esc_attr__( 'Adımlar', 'contenthealth' ) . '"><li aria-current="step"><b>1</b><span>' . esc_html__( 'Seçim', 'contenthealth' ) . '</span></li><li><b>2</b><span>' . esc_html__( 'Kuru koşu', 'contenthealth' ) . '</span></li><li><b>3</b><span>' . esc_html__( 'Onay', 'contenthealth' ) . '</span></li><li><b>4</b><span>' . esc_html__( 'Sonuç', 'contenthealth' ) . '</span></li></ol><p class="rbn-sr" id="wiz-live" aria-live="polite"></p>';
		echo '<section data-step="1" tabindex="-1" aria-labelledby="ws1"><h3 class="rbn-h3" id="ws1">' . esc_html__( '1 / 4 · Düzeltilecek bulgular', 'contenthealth' ) . '</h3><p class="rbn-p rbn-muted" data-wiz-intro></p><div class="rbn-tw"><table class="rbn-table rbn-table--fit"><caption class="rbn-sr">' . esc_html__( 'Seçilen kurallar', 'contenthealth' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Kural', 'contenthealth' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Bulgu', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Düzeltme', 'contenthealth' ) . '</th></tr></thead><tbody data-wiz-rules></tbody></table></div><div style="margin-top:12px" data-wiz-skipnote></div></section>';
		echo '<section data-step="2" tabindex="-1" aria-labelledby="ws2" hidden><h3 class="rbn-h3" id="ws2">' . esc_html__( '2 / 4 · Kuru koşu: hiçbir şey değiştirilmedi', 'contenthealth' ) . '</h3><ul class="rbn-stats" style="margin-bottom:12px" data-wiz-stats></ul><h4 class="rbn-h3">' . esc_html__( 'Örnek değişiklikler', 'contenthealth' ) . '</h4><div class="rbn-stack" data-wiz-samples></div></section>';
		echo '<section data-step="3" tabindex="-1" aria-labelledby="ws3" hidden><h3 class="rbn-h3" id="ws3">' . esc_html__( '3 / 4 · Onay', 'contenthealth' ) . '</h3><div class="rbn-stack" id="wiz-confirm"><label class="rbn-check"><input type="checkbox" data-wiz-keep' . checked( (bool) Settings::get( 'keep_modified' ), true, false ) . '><span><b>' . esc_html__( 'Son değiştirilme tarihi değişmesin', 'contenthealth' ) . '</b><span class="rbn-help">' . esc_html__( 'Önerilen. İçerikler “güncellendi” görünmez, sıralama bozulmaz.', 'contenthealth' ) . '</span></span></label><label class="rbn-check"><input type="checkbox" id="wiz-ok"><span><b data-wiz-oktext>' . esc_html__( 'Değişikliği ve işlemin geri alınabileceğini anlıyorum', 'contenthealth' ) . '</b><span class="rbn-help">' . esc_html__( 'Geri alma: Geri alma günlüğü → bu işlem → Geri al. Revizyon oluşturulmaz; günlük önceki değeri saklar.', 'contenthealth' ) . '</span></span></label></div><div id="wiz-run" hidden style="margin-top:16px"><div class="rbn-progress__l"><span id="wiz-msg">' . esc_html__( 'Uygulanıyor…', 'contenthealth' ) . '</span><b id="wiz-pct">0%</b></div><div class="rbn-progress" role="progressbar" id="wiz-bar" aria-label="' . esc_attr__( 'Düzeltme ilerlemesi', 'contenthealth' ) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="--w:0%"><i></i></div></div></section>';
		echo '<section data-step="4" tabindex="-1" aria-labelledby="ws4" hidden><h3 class="rbn-h3" id="ws4">' . esc_html__( '4 / 4 · Sonuç', 'contenthealth' ) . '</h3><div data-wiz-result></div><p class="rbn-help" style="margin-top:12px">' . esc_html__( 'Düzelen bulgular listede “Düzeltildi” olarak işaretlenir; sonraki taramada yeniden doğrulanır.', 'contenthealth' ) . '</p></section>';
		echo '</div><div class="rbn-dlg__f" style="justify-content:space-between"><button type="button" class="rbn-btn" data-wiz-prev hidden>' . esc_html__( 'Geri', 'contenthealth' ) . '</button><div class="rbn-row" style="margin-left:auto"><button type="button" class="rbn-btn" data-close data-wiz-cancel>' . esc_html__( 'Vazgeç', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-wiz-next>' . esc_html__( 'Kuru koşuyu çalıştır', 'contenthealth' ) . '</button></div></div></dialog>';

		UndoLog::undo_dialog( $t );
		// phpcs:enable
	}
}
