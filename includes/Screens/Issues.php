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
use ContentHealth\Runs;
use ContentHealth\SaveAudit;
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
		echo '<section class="rbn-card" aria-labelledby="h-list"><div class="rbn-card__h"><h2 id="h-list">' . esc_html__( 'Findings', 'contenthealth' ) . ' <span class="rbn-count" style="vertical-align:middle">' . (int) $res['total'] . '</span></h2><p>' . esc_html__( 'Content problems found by the scan. Open a row to see the evidence and the suggested fix.', 'contenthealth' ) . '</p>' . self::last_audit_line() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( 0 === $any ) {
			echo '<div class="rbn-card__b">' . Ui::empty_state( 'scan', __( 'No findings yet', 'contenthealth' ), __( 'No scan has been run yet, or the scan found no problems. Start a scan.', 'contenthealth' ), '<button type="button" class="rbn-btn rbn-btn--pri" data-scan>' . Ui::icon( 'scan' ) . esc_html__( 'Scan now', 'contenthealth' ) . '</button>' ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		self::filter_form( $f );
		self::chips( $f, $args, (int) $res['total'] );

		if ( ! $res['rows'] ) {
			echo '<div class="rbn-card__b">' . Ui::empty_state( 'shield', __( 'No findings match this filter', 'contenthealth' ), __( 'Loosen or clear the filters.', 'contenthealth' ), '<a class="rbn-btn" href="' . esc_url( Ui::url( array( 'tab' => 'issues' ) ) ) . '">' . esc_html__( 'Clear filters', 'contenthealth' ) . '</a>' ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$export = wp_nonce_url( add_query_arg( array_merge( $args, array( 'action' => 'contenthealth_export' ) ), admin_url( 'admin-post.php' ) ), 'contenthealth_export' );
		echo '<form method="post" id="bulkform" action="' . esc_url( Ui::url( $args ) ) . '">';
		wp_nonce_field( 'contenthealth_bulk', 'ch_bulk_nonce' );
		echo '<div class="ch-bulk" id="bulk" role="region" aria-label="' . esc_attr__( 'Bulk actions', 'contenthealth' ) . '"><p id="bulk-n"><span data-n>0</span> ' . esc_html__( 'findings selected', 'contenthealth' ) . '</p><div class="ch-bulk__act">';
		echo '<button type="button" class="rbn-btn rbn-btn--pri rbn-btn--sm" data-open="dlg-wiz" data-wiz-open>' . Ui::icon( 'wand' ) . esc_html__( 'Fix selected…', 'contenthealth' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="submit" class="rbn-btn rbn-btn--sm" name="ch_act" value="ignore" data-bulk-ignore>' . esc_html__( 'Ignore', 'contenthealth' ) . '</button>';
		echo '<a class="rbn-btn rbn-btn--sm" href="' . esc_url( $export ) . '">' . Ui::icon( 'down' ) . esc_html__( 'Download CSV', 'contenthealth' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="button" class="rbn-btn rbn-btn--sm rbn-btn--link" data-bulk-clear>' . esc_html__( 'Clear selection', 'contenthealth' ) . '</button></div></div>';

		$pages = max( 1, (int) ceil( $res['total'] / self::PER_PAGE ) );
		/* translators: 1: current page, 2: page count */
		$cap = sprintf( __( 'Findings list, page %1$d / %2$d', 'contenthealth' ), $page, $pages );
		echo '<div class="rbn-tw"><table class="rbn-table rbn-table--stack" id="tbl"' . ( $open ? ' data-autoopen="' . (int) $open . '"' : '' ) . '><caption class="rbn-sr">' . esc_html( $cap ) . '</caption>';
		echo '<thead><tr><th scope="col" class="ch-ck"><label class="ch-chk"><input type="checkbox" data-select-all aria-label="' . esc_attr__( 'Select all findings on this page', 'contenthealth' ) . '"></label></th><th scope="col">' . esc_html__( 'Object', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Rule', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Evidence', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Severity', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Date', 'contenthealth' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Actions', 'contenthealth' ) . '</span></th></tr></thead><tbody>';
		$groups   = Registry::groups();
		$modified = self::modified_map( $res['rows'] );
		foreach ( $res['rows'] as $r ) {
			$rule  = Registry::get( $r->rule_id );
			$title = Ui::object_title( $r );
			$s     = Ui::sev( $r->severity );
			printf(
				'<tr data-id="%d" data-obj="%d" data-rule="%s" data-fix="%s" data-sev="%s" data-title="%s"><td class="ch-ck"><label class="ch-chk"><input type="checkbox" name="ids[]" value="%d" aria-label="%s"></label></td>',
				(int) $r->id,
				(int) $r->object_id,
				esc_attr( $r->rule_id ),
				esc_attr( (string) ( $rule ? $rule->fix_action() : '' ) ),
				esc_attr( $s['cls'] ),
				esc_attr( $title ),
				(int) $r->id,
				/* translators: %s: object title */
				esc_attr( sprintf( __( 'Select: %s', 'contenthealth' ), $title ) )
			);
			echo '<th scope="row"><span class="ch-obj">' . esc_html( $title ) . '</span><span class="ch-sub">' . esc_html( Ui::object_sub( $r ) ) . '</span></th>';
			echo '<td data-label="' . esc_attr__( 'Rule', 'contenthealth' ) . '">' . esc_html( $rule ? $rule->label() : $r->rule_id ) . '<span class="ch-sub">' . esc_html( (string) ( $groups[ $r->group_id ] ?? $r->group_id ) ) . '</span></td>';
			echo '<td data-label="' . esc_attr__( 'Evidence', 'contenthealth' ) . '"><span class="ch-ev"><mark>' . esc_html( Ui::evidence( $r ) ) . '</mark></span>' . self::state_badge( $r, $modified ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td data-label="' . esc_attr__( 'Severity', 'contenthealth' ) . '">' . Ui::sev_badge( $r->severity ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td data-label="' . esc_attr__( 'Date', 'contenthealth' ) . '" class="rbn-nowrap">' . Ui::date( $r->last_seen ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><div class="rbn-acts"><button type="button" class="rbn-btn rbn-btn--sm" data-open="dlg-detay" data-detail="' . (int) $r->id . '" aria-label="' . esc_attr( sprintf( /* translators: %s: object title */ __( 'Details: %s', 'contenthealth' ), $title ) ) . '">' . esc_html__( 'Details', 'contenthealth' ) . '</button>';
			if ( 'open' === $r->status ) {
				echo '<button type="submit" class="rbn-btn rbn-btn--sm rbn-btn--link" name="one" value="' . (int) $r->id . '" data-ignore aria-label="' . esc_attr( sprintf( /* translators: %s: object title */ __( 'Ignore: %s', 'contenthealth' ), $title ) ) . '">' . esc_html__( 'Ignore', 'contenthealth' ) . '</button>';
			} elseif ( 'ignored' === $r->status ) {
				echo '<button type="submit" class="rbn-btn rbn-btn--sm rbn-btn--link" name="reopen" value="' . (int) $r->id . '">' . esc_html__( 'Reopen', 'contenthealth' ) . '</button>';
			}
			echo '</div></td></tr>';
		}
		echo '</tbody></table></div></form>';
		echo Ui::pager( (int) $res['total'], self::PER_PAGE, $page, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';
	}

	/** "Son denetim: <tarih>" satırı: son tarama ile son kayıt denetiminden yeni olanı. */
	private static function last_audit_line(): string {
		$run  = Runs::latest_finished();
		$full = $run ? (string) $run->finished_at : '';
		$save = SaveAudit::last();
		$last = max( $full, $save );
		if ( '' === $last ) {
			return '';
		}
		$how = $last === $save && '' !== $save ? __( 'when a post is saved', 'contenthealth' ) : __( 'full scan', 'contenthealth' );
		/* translators: 1: date and time, 2: how (full scan / on post save) */
		return '<p class="rbn-muted" id="last-audit">' . sprintf( esc_html__( 'Last check: %1$s (%2$s)', 'contenthealth' ), Ui::datetime( $last ), esc_html( $how ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Listedeki yazı bulguları için yazının son değişiklik zamanı (GMT), tek sorguda.
	 *
	 * @param array<int,object> $rows
	 * @return array<int,string> yazı kimliği → post_modified_gmt
	 */
	private static function modified_map( array $rows ): array {
		global $wpdb;
		$ids = array();
		foreach ( $rows as $r ) {
			if ( 'post' === $r->object_type ) {
				$ids[] = (int) $r->object_id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) ) as $p ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (int) $p->ID ] = (string) $p->post_modified_gmt;
		}
		return $out;
	}

	/**
	 * Durum rozeti: elle düzeltilen bulgu "elle düzeltildi"; açık bulgu yazı ondan sonra değiştiyse "yazı sonradan değişti".
	 * Kayıt anında denetlenen bulgunun last_seen'i kayıttan yeni olduğundan rozet çıkmaz.
	 *
	 * @param array<int,string> $modified
	 */
	private static function state_badge( object $r, array $modified ): string {
		if ( 'fixed' === $r->status && 'user' === ( $r->details_arr['fixed_by'] ?? '' ) ) {
			return ' ' . Ui::badge( 'ok', 'check', __( 'Fixed by hand', 'contenthealth' ) );
		}
		$mod = $modified[ (int) $r->object_id ] ?? '';
		if ( 'open' === $r->status && 'post' === $r->object_type && '' !== $mod && strtotime( $mod . ' UTC' ) > strtotime( (string) $r->last_seen . ' UTC' ) ) {
			return ' ' . Ui::badge( 'info', 'info', __( 'Post changed afterwards', 'contenthealth' ) );
		}
		return '';
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
		$rules = array( '' => __( 'All', 'contenthealth' ) );
		foreach ( Registry::rules() as $r ) {
			$rules[ $r->id() ] = $r->label();
		}
		$rev = array_flip( array_filter( self::STATUS, static fn( $v ) => '' !== $v ) );
		echo '<form class="ch-filter" role="search" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" aria-label="' . esc_attr__( 'Filter findings', 'contenthealth' ) . '"><input type="hidden" name="page" value="' . esc_attr( \ContentHealth\Admin::SLUG ) . '"><input type="hidden" name="tab" value="issues">';
		$sel( 'f-grup', __( 'Group', 'contenthealth' ), array( '' => __( 'All', 'contenthealth' ) ) + Registry::groups(), (string) ( $f['group'] ?? '' ) );
		$sel( 'f-kural', __( 'Rule', 'contenthealth' ), $rules, (string) ( $f['rule'] ?? '' ) );
		$sel( 'f-ciddiyet', __( 'Severity', 'contenthealth' ), array( '' => __( 'All', 'contenthealth' ) ) + Registry::severities(), (string) ( $f['severity'] ?? '' ) );
		$sel(
			'f-durum',
			__( 'Status', 'contenthealth' ),
			array(
				'acik'       => __( 'Open', 'contenthealth' ),
				'yoksayildi' => __( 'Ignored', 'contenthealth' ),
				'duzeltildi' => __( 'Fixed', 'contenthealth' ),
				'tum'        => __( 'All', 'contenthealth' ),
			),
			'' === $f['status'] ? 'tum' : ( $rev[ $f['status'] ] ?? 'acik' )
		);
		echo '<div class="ch-search"><label for="f-ara">' . esc_html__( 'Search', 'contenthealth' ) . '</label><span class="ch-search__box">' . Ui::icon( 'search' ) . '<input class="rbn-input" type="search" id="f-ara" name="ara" value="' . esc_attr( (string) ( $f['search'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Title or evidence', 'contenthealth' ) . '"></span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="ch-filter__act"><button type="submit" class="rbn-btn rbn-btn--pri">' . esc_html__( 'Filter', 'contenthealth' ) . '</button><a class="rbn-btn" href="' . esc_url( Ui::url( array( 'tab' => 'issues' ) ) ) . '">' . esc_html__( 'Clear', 'contenthealth' ) . '</a></div></form>';
	}

	/**
	 * @param array<string,mixed> $f
	 * @param array<string,mixed> $args
	 */
	private static function chips( array $f, array $args, int $total ): void {
		$labels = array(
			'grup'     => array( __( 'Group', 'contenthealth' ), Registry::groups()[ $f['group'] ?? '' ] ?? '' ),
			'kural'    => array( __( 'Rule', 'contenthealth' ), ( isset( $f['rule'] ) && Registry::get( $f['rule'] ) ) ? Registry::get( $f['rule'] )->label() : '' ),
			'ciddiyet' => array( __( 'Severity', 'contenthealth' ), Registry::severities()[ $f['severity'] ?? '' ] ?? '' ),
			'ara'      => array( __( 'Search term', 'contenthealth' ), (string) ( $f['search'] ?? '' ) ),
			'durum'    => array(
				__( 'Status', 'contenthealth' ),
				array(
					'open'    => __( 'Open', 'contenthealth' ),
					'ignored' => __( 'Ignored', 'contenthealth' ),
					'fixed'   => __( 'Fixed', 'contenthealth' ),
					''        => __( 'All', 'contenthealth' ),
				)[ $f['status'] ] ?? '',
			),
		);
		echo '<div class="ch-chips" aria-label="' . esc_attr__( 'Active filters', 'contenthealth' ) . '"><span class="rbn-muted">' . esc_html__( 'Active filter:', 'contenthealth' ) . '</span>';
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
			echo '<span class="ch-fchip">' . esc_html( $txt ) . '<a href="' . esc_url( Ui::url( $rest ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: filter text */ __( 'Remove filter: %s', 'contenthealth' ), $txt ) ) . '">' . Ui::icon( 'x' ) . '</a></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		/* translators: %d: result count */
		echo '<span class="rbn-muted" style="margin-left:auto">' . esc_html( sprintf( _n( '%d result', '%d results', $total, 'contenthealth' ), $total ) ) . '</span></div>';
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

		$body  = '<p style="margin:0 0 var(--rbn-s4)"><strong style="color:var(--rbn-text)">' . esc_html( $title ) . '</strong><br><span class="rbn-muted">' . esc_html( Ui::object_sub( $issue ) ) . ' · ' . Ui::date( $issue->last_seen ) . ' ' . esc_html__( 'scanned', 'contenthealth' ) . '</span></p>';
		$body .= '<div class="ch-sect"><h3>' . esc_html__( 'Evidence', 'contenthealth' ) . '</h3>';
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? array_slice( $d['items'], 0, 8 ) : array();
		if ( $items ) {
			foreach ( $items as $it ) {
				$txt   = is_array( $it ) ? implode( ' · ', array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE ), $it ) ) : (string) $it;
				$body .= '<p style="margin:0 0 var(--rbn-s2);color:var(--rbn-text)"><code style="display:block;padding:var(--rbn-s2)"><mark>' . esc_html( \ContentHealth\Text::snippet( $txt, 300 ) ) . '</mark></code></p>';
			}
		} else {
			$body .= '<p style="margin:0 0 var(--rbn-s2);color:var(--rbn-text)"><code style="display:block;padding:var(--rbn-s2)"><mark>' . esc_html( Ui::evidence( $issue ) ) . '</mark></code></p>';
		}
		if ( isset( $d['count'] ) ) {
			$body .= '<ul class="ch-sum" style="color:var(--rbn-muted)"><li>' . Ui::icon( 'check' ) . esc_html( sprintf( /* translators: %d: count */ __( 'Total found: %d', 'contenthealth' ), (int) $d['count'] ) ) . '</li></ul>';
		}
		$body .= '</div>';
		$body .= '<div class="ch-sect"><h3>' . esc_html__( 'Why does it matter?', 'contenthealth' ) . '</h3><p style="margin:0;color:var(--rbn-text)">' . esc_html( $rule ? $rule->description() : '' ) . '</p></div>';
		$body .= '<div class="ch-sect"><h3>' . esc_html__( 'Suggested fix', 'contenthealth' ) . '</h3><p style="margin:0;color:var(--rbn-text)">' . esc_html( Ui::fix_text( $fix ) ) . '</p></div>';

		$preview = null;
		if ( $fix && 'open' === $issue->status && 'term_merge_skip' !== $fix && 'fix_term_merge' !== $fix ) {
			$res = Fixer::run_rule( $issue->rule_id, array( $issue->object_id ), true );
			if ( is_array( $res ) && ! empty( $res['items'][0]['changes'] ) ) {
				$preview = $res['items'][0]['changes'];
			}
		}
		if ( $preview ) {
			$body .= '<div class="ch-sect"><div class="ch-row ch-row--between" style="margin-bottom:var(--rbn-s2)"><h3 style="margin:0">' . esc_html__( 'Preview: before / after', 'contenthealth' ) . '</h3><div class="rbn-seg" role="radiogroup" aria-label="' . esc_attr__( 'Diff view', 'contenthealth' ) . '"><label><input type="radio" name="diffmode" value="inline" checked><span>' . esc_html__( 'Inline', 'contenthealth' ) . '</span></label><label><input type="radio" name="diffmode" value="split"><span>' . esc_html__( 'Side by side', 'contenthealth' ) . '</span></label></div></div>' . Ui::diff( $preview, 'diff-' . $id ) . '</div>';
			$body .= '<label class="rbn-check"><input type="checkbox" data-keep-mod' . checked( (bool) Settings::get( 'keep_modified' ), true, false ) . '><span><b>' . esc_html__( 'Keep the last modified date unchanged', 'contenthealth' ) . '</b><span class="rbn-help">' . esc_html__( 'The post list does not show “updated”; the undo log keeps the previous value.', 'contenthealth' ) . '</span></span></label>';
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
		$x = '<button type="button" class="ch-dlg__x" data-close aria-label="' . esc_attr__( 'Close', 'contenthealth' ) . '">' . Ui::icon( 'x' ) . '</button>';
		$t = Ui::tone_attr();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui/esc_* ile kaçışlı.
		echo '<dialog class="rbn-dlg ch-dlg--drawer" id="dlg-detay"' . $t . ' aria-labelledby="dlg-detay-t"><div class="rbn-dlg__h"><div><p style="margin:0 0 var(--rbn-s1);display:flex;gap:var(--rbn-s2);flex-wrap:wrap;align-items:center" data-d-badge></p><h2 id="dlg-detay-t" data-d-title>' . esc_html__( 'Details', 'contenthealth' ) . '</h2></div>' . $x . '</div><div class="rbn-dlg__b" data-d-body></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn rbn-btn--link" data-ignore-detail>' . esc_html__( 'Ignore', 'contenthealth' ) . '</button><a class="rbn-btn" href="#" target="_blank" rel="noopener" style="margin-right:auto" data-d-edit>' . esc_html__( 'Open post', 'contenthealth' ) . Ui::icon( 'out' ) . '</a><button type="button" class="rbn-btn rbn-btn--pri" data-d-fix>' . esc_html__( 'Fix…', 'contenthealth' ) . '</button></div></dialog>';

		echo '<dialog class="rbn-dlg ch-dlg--wide" id="dlg-wiz"' . $t . ' aria-labelledby="dlg-wiz-t" data-wiz><div class="rbn-dlg__h"><div><h2 id="dlg-wiz-t">' . esc_html__( 'Bulk fix', 'contenthealth' ) . '</h2></div>' . $x . '</div><div class="rbn-dlg__b">';
		echo '<ol class="ch-stepper" style="--n:4" aria-label="' . esc_attr__( 'Steps', 'contenthealth' ) . '"><li aria-current="step"><b>1</b><span>' . esc_html__( 'Selection', 'contenthealth' ) . '</span></li><li><b>2</b><span>' . esc_html__( 'Dry run', 'contenthealth' ) . '</span></li><li><b>3</b><span>' . esc_html__( 'Confirm', 'contenthealth' ) . '</span></li><li><b>4</b><span>' . esc_html__( 'Result', 'contenthealth' ) . '</span></li></ol><p class="rbn-sr" id="wiz-live" aria-live="polite"></p>';
		echo '<section data-step="1" tabindex="-1" aria-labelledby="ws1"><h3 class="ch-h3" id="ws1">' . esc_html__( '1 / 4 · Findings to fix', 'contenthealth' ) . '</h3><p class="rbn-p rbn-muted" data-wiz-intro></p><div class="rbn-tw"><table class="rbn-table"><caption class="rbn-sr">' . esc_html__( 'Selected rules', 'contenthealth' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Rule', 'contenthealth' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Finding', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Fix', 'contenthealth' ) . '</th></tr></thead><tbody data-wiz-rules></tbody></table></div><div style="margin-top:var(--rbn-s3)" data-wiz-skipnote></div></section>';
		echo '<section data-step="2" tabindex="-1" aria-labelledby="ws2" hidden><h3 class="ch-h3" id="ws2">' . esc_html__( '2 / 4 · Dry run: nothing was changed', 'contenthealth' ) . '</h3><ul class="rbn-stats" style="margin-bottom:var(--rbn-s3)" data-wiz-stats></ul><h4 class="ch-h3">' . esc_html__( 'Sample changes', 'contenthealth' ) . '</h4><div class="ch-stack" data-wiz-samples></div></section>';
		echo '<section data-step="3" tabindex="-1" aria-labelledby="ws3" hidden><h3 class="ch-h3" id="ws3">' . esc_html__( '3 / 4 · Confirm', 'contenthealth' ) . '</h3><div class="ch-stack" id="wiz-confirm"><label class="rbn-check"><input type="checkbox" data-wiz-keep' . checked( (bool) Settings::get( 'keep_modified' ), true, false ) . '><span><b>' . esc_html__( 'Keep the last modified date unchanged', 'contenthealth' ) . '</b><span class="rbn-help">' . esc_html__( 'Recommended. Items do not show as “updated” and the ordering is not disturbed.', 'contenthealth' ) . '</span></span></label><label class="rbn-check"><input type="checkbox" id="wiz-ok"><span><b data-wiz-oktext>' . esc_html__( 'I understand the change and that the operation can be undone', 'contenthealth' ) . '</b><span class="rbn-help">' . esc_html__( 'Undo: Undo log → this operation → Undo. No revision is created; the log keeps the previous value.', 'contenthealth' ) . '</span></span></label></div><div id="wiz-run" hidden style="margin-top:var(--rbn-s4)"><div class="ch-progress__l"><span id="wiz-msg">' . esc_html__( 'Applying…', 'contenthealth' ) . '</span><b id="wiz-pct">0%</b></div><div class="ch-progress" role="progressbar" id="wiz-bar" aria-label="' . esc_attr__( 'Fix progress', 'contenthealth' ) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="--w:0%"><i></i></div></div></section>';
		echo '<section data-step="4" tabindex="-1" aria-labelledby="ws4" hidden><h3 class="ch-h3" id="ws4">' . esc_html__( '4 / 4 · Result', 'contenthealth' ) . '</h3><div data-wiz-result></div><p class="rbn-help" style="margin-top:var(--rbn-s3)">' . esc_html__( 'Fixed findings are marked “Fixed” in the list; they are verified again in the next scan.', 'contenthealth' ) . '</p></section>';
		echo '</div><div class="rbn-dlg__f" style="justify-content:space-between"><button type="button" class="rbn-btn" data-wiz-prev hidden>' . esc_html__( 'Back', 'contenthealth' ) . '</button><div class="ch-row" style="margin-left:auto"><button type="button" class="rbn-btn" data-close data-wiz-cancel>' . esc_html__( 'Cancel', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-wiz-next>' . esc_html__( 'Run dry run', 'contenthealth' ) . '</button></div></div></dialog>';
		// phpcs:enable
	}
}
