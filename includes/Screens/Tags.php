<?php
/**
 * Etiket Doktoru: terim sorunu sınıfları, yakın yinelenen küme birleştirme, toplu terim eylemleri.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Screens;

use ContentHealth\Issues as IssueStore;
use ContentHealth\Registry;
use ContentHealth\Seo;
use ContentHealth\Ui;

defined( 'ABSPATH' ) || exit;

final class Tags {

	/** Sınıf kuralı → kısa ad. */
	private static function classes(): array {
		return array(
			'term_empty'        => __( 'Empty tag', 'contenthealth' ),
			'term_duplicate'    => __( 'Duplicate cluster', 'contenthealth' ),
			'term_too_long'     => __( 'Very long name', 'contenthealth' ),
			'term_single_use'   => __( 'Single post', 'contenthealth' ),
			'term_ai_residue'   => __( 'AI leftover', 'contenthealth' ),
			'term_numeric_only' => __( 'Digits only', 'contenthealth' ),
		);
	}

	/** @return array<string,int> */
	private static function counts(): array {
		$open = IssueStore::open_counts();
		$out  = array();
		foreach ( array_keys( self::classes() ) as $id ) {
			$out[ $id ] = isset( $open[ $id ] ) ? (int) array_sum( $open[ $id ] ) : 0;
		}
		return $out;
	}

	public static function count(): int {
		return (int) array_sum( self::counts() );
	}

	public static function render(): void {
		$classes = self::classes();
		$counts  = self::counts();
		$cur     = isset( $_GET['sinif'] ) ? sanitize_key( wp_unslash( (string) $_GET['sinif'] ) ) : 'term_duplicate'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $classes[ $cur ] ) ) {
			$cur = 'term_duplicate';
		}
		echo '<div class="rbn-note rbn-note--ok" role="status" id="flash" hidden></div>';
		if ( 0 === array_sum( $counts ) ) {
			echo Ui::empty_state( 'tag', __( 'No problems seen in tags', 'contenthealth' ), __( 'The scan results contain no tag problems. They appear here after a new scan.', 'contenthealth' ), '<button type="button" class="rbn-btn rbn-btn--pri" data-scan>' . Ui::icon( 'scan' ) . esc_html__( 'Scan now', 'contenthealth' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<ul class="ch-tiles" aria-label="' . esc_attr__( 'Problem classes', 'contenthealth' ) . '">';
		foreach ( $classes as $id => $label ) {
			echo '<li><a class="ch-tile" href="' . esc_url( Ui::url( array( 'tab' => 'tags', 'sinif' => $id ) ) ) . '"' . ( $id === $cur ? ' aria-current="true"' : '' ) . '><b>' . (int) $counts[ $id ] . '</b><span>' . esc_html( $label ) . '</span></a></li>';
		}
		echo '</ul>';
		if ( 'term_duplicate' === $cur ) {
			self::clusters( $counts['term_empty'] );
		} else {
			self::list( $cur, $classes[ $cur ] );
		}
		self::seo_card();
	}

	/** @return array<int,array<string,mixed>> */
	private static function cluster_data(): array {
		$res  = IssueStore::query( array( 'rule' => 'term_duplicate', 'status' => 'open', 'per_page' => 500 ) );
		$by   = array();
		foreach ( $res['rows'] as $i ) {
			$group = array_map( 'intval', (array) ( $i->details_arr['group'] ?? array( $i->object_id ) ) );
			sort( $group );
			$key              = implode( '-', $group );
			$by[ $key ]['ids'] = $group;
			$by[ $key ]['canon'] = (int) ( $i->details_arr['canonical'] ?? $group[0] );
			$by[ $key ]['issues'][] = (int) $i->id;
		}
		return array_values( $by );
	}

	private static function clusters( int $empty ): void {
		$data = self::cluster_data();
		echo '<section class="rbn-card" aria-labelledby="h-km"><div class="rbn-card__h"><div class="ch-row ch-row--between"><div><h2 id="h-km">' . esc_html__( 'Near-duplicate tags', 'contenthealth' ) . ' <span class="rbn-count">' . esc_html( sprintf( /* translators: %d: cluster count */ _n( '%d cluster', '%d clusters', count( $data ), 'contenthealth' ), count( $data ) ) ) . '</span></h2><p>' . esc_html__( 'Tags that are spelled differently but describe the same topic. In each cluster, choose the canonical tag to keep.', 'contenthealth' ) . '</p></div>';
		if ( $empty > 0 ) {
			echo '<button type="button" class="rbn-btn rbn-btn--sm" data-open="dlg-empty" data-terms-fix="term_empty">' . Ui::icon( 'trash' ) . esc_html__( 'Delete empty tags…', 'contenthealth' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></div><div class="rbn-card__b">';
		if ( ! $data ) {
			echo Ui::empty_state( 'check', __( 'No duplicate tag clusters', 'contenthealth' ), __( 'There are no open duplicate-tag findings.', 'contenthealth' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div></section>';
			return;
		}
		$n = 0;
		foreach ( $data as $c ) {
			++$n;
			$terms = array();
			foreach ( $c['ids'] as $id ) {
				$t = get_term( (int) $id );
				if ( $t && ! is_wp_error( $t ) ) {
					$terms[] = $t;
				}
			}
			if ( count( $terms ) < 2 ) {
				continue;
			}
			$name = '';
			foreach ( $terms as $t ) {
				if ( (int) $t->term_id === $c['canon'] ) {
					$name = $t->name;
				}
			}
			$name = '' !== $name ? $name : $terms[0]->name;
			/* translators: 1: cluster number, 2: canonical name */
			$legend = sprintf( __( 'Cluster %1$d · %2$s', 'contenthealth' ), $n, $name );
			echo '<fieldset class="ch-cluster" data-cluster="' . (int) $n . '" data-issues="' . esc_attr( implode( ',', $c['issues'] ) ) . '"><legend>' . esc_html( $legend ) . '</legend>';
			echo '<div role="radiogroup" aria-label="' . esc_attr( sprintf( /* translators: %s: cluster legend */ __( 'Canonical tag: %s', 'contenthealth' ), $legend ) ) . '">';
			foreach ( $terms as $t ) {
				printf(
					'<label class="ch-term"><input type="radio" name="kanon-%1$d" value="%2$d" data-name="%3$s" data-count="%4$d"%5$s><span><span class="ch-term__n">%3$s</span><span class="ch-term__m"><span>%6$s <code>%7$s</code></span><span>#%2$d</span></span></span><span class="ch-term__u">%8$s</span></label>',
					(int) $n,
					(int) $t->term_id,
					esc_attr( $t->name ),
					(int) $t->count,
					checked( (int) $t->term_id, $c['canon'], false ),
					esc_html__( 'Short name:', 'contenthealth' ),
					esc_html( $t->slug ),
					esc_html( sprintf( /* translators: %d: post count */ __( '%d posts', 'contenthealth' ), (int) $t->count ) )
				);
			}
			echo '</div><div class="ch-cluster__f"><p data-merge-sum></p><div class="ch-row"><button type="button" class="rbn-btn rbn-btn--link rbn-btn--sm" data-cluster-ignore>' . esc_html__( 'Ignore', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri rbn-btn--sm" data-open="dlg-merge" data-merge-from="' . (int) $n . '">' . Ui::icon( 'merge' ) . esc_html__( 'Merge…', 'contenthealth' ) . '</button></div></div></fieldset>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></section>';
	}

	private static function list( string $rule_id, string $label ): void {
		$rule = Registry::get( $rule_id );
		$page = max( 1, isset( $_GET['sayfa'] ) ? (int) $_GET['sayfa'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res  = IssueStore::query( array( 'rule' => $rule_id, 'status' => 'open', 'per_page' => 20, 'page' => $page ) );
		$fix  = $rule ? $rule->fix_action() : null;
		echo '<section class="rbn-card" aria-labelledby="h-sn"><div class="rbn-card__h"><div class="ch-row ch-row--between"><div><h2 id="h-sn">' . esc_html( $label ) . ' <span class="rbn-count">' . (int) $res['total'] . '</span></h2><p>' . esc_html( $rule ? $rule->description() : '' ) . '</p></div>';
		if ( $fix && $res['total'] > 0 ) {
			echo '<button type="button" class="rbn-btn rbn-btn--sm rbn-btn--pri" data-open="dlg-empty" data-terms-fix="' . esc_attr( $rule_id ) . '">' . esc_html( Ui::fix_text( $fix ) ? __( 'Fix…', 'contenthealth' ) : '' ) . '</button>';
		}
		echo '</div></div>';
		if ( ! $res['rows'] ) {
			echo '<div class="rbn-card__b">' . Ui::empty_state( 'check', __( 'No open findings in this class', 'contenthealth' ), '' ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html( $label ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Tag', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Evidence', 'contenthealth' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Post', 'contenthealth' ) . '</th></tr></thead><tbody>';
		foreach ( $res['rows'] as $r ) {
			$t = get_term( (int) $r->object_id );
			echo '<tr><th scope="row"><span class="ch-obj">' . esc_html( Ui::object_title( $r ) ) . '</span><span class="ch-sub">' . esc_html( Ui::object_sub( $r ) ) . '</span></th><td data-label="' . esc_attr__( 'Evidence', 'contenthealth' ) . '"><span class="ch-ev"><mark>' . esc_html( Ui::evidence( $r ) ) . '</mark></span></td><td data-label="' . esc_attr__( 'Post', 'contenthealth' ) . '" class="rbn-num">' . (int) ( $t && ! is_wp_error( $t ) ? $t->count : 0 ) . '</td></tr>';
		}
		echo '</tbody></table></div>' . Ui::pager( (int) $res['total'], 20, $page, array( 'tab' => 'tags', 'sinif' => $rule_id ) ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function seo_card(): void {
		$seo = Seo::active_plugin();
		$name = Seo::label( $seo );
		echo '<section class="rbn-card" aria-labelledby="h-ni"><div class="rbn-card__h"><h2 id="h-ni">' . esc_html__( 'Noindex for tags', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Keep weak tag archives out of search results.', 'contenthealth' ) . '</p></div><div class="rbn-card__b" id="noindex">';
		if ( '' !== $name ) {
			echo Ui::note( 'ok', '<p><strong>' . esc_html( sprintf( /* translators: %s: plugin name */ __( '%s detected.', 'contenthealth' ), $name ) ) . '</strong> ' . esc_html( Seo::tag_hint( $seo ) ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo Ui::note( 'info', '<p><strong>' . esc_html__( 'No SEO plugin found.', 'contenthealth' ) . '</strong> ' . esc_html__( 'Content Health can add noindex to single-post tag archives: open the “Single post” class and fix it. The change can be undone.', 'contenthealth' ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></section>';
	}

	public static function dialogs(): void {
		$t = Ui::tone_attr();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui/esc_* ile kaçışlı.
		echo '<dialog class="rbn-dlg" id="dlg-merge"' . $t . ' aria-labelledby="dlg-merge-t" aria-describedby="dlg-merge-d"><div class="rbn-dlg__b"><h2 id="dlg-merge-t" data-merge-title></h2><p id="dlg-merge-d">' . esc_html__( 'Dry run: nothing has changed yet.', 'contenthealth' ) . '</p><ul class="ch-sum" style="color:var(--rbn-text)" data-merge-list></ul></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-close>' . esc_html__( 'Cancel', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-confirm-merge>' . esc_html__( 'Merge', 'contenthealth' ) . '</button></div></dialog>';
		echo '<dialog class="rbn-dlg" id="dlg-empty"' . $t . ' aria-labelledby="dlg-empty-t" aria-describedby="dlg-empty-d"><div class="rbn-dlg__b"><h2 id="dlg-empty-t" data-terms-title></h2><p id="dlg-empty-d" data-terms-text></p><div class="ch-toks" style="margin:0 0 var(--rbn-s4)" data-terms-toks></div><div class="rbn-note rbn-note--warn">' . Ui::icon( 'alert' ) . '<div><p>' . esc_html__( 'Changes are written to the undo log; they can be undone.', 'contenthealth' ) . '</p></div></div></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-close>' . esc_html__( 'Cancel', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--danger" data-confirm-empty>' . esc_html__( 'Apply', 'contenthealth' ) . '</button></div></dialog>';
		UndoLog::undo_dialog( $t );
		// phpcs:enable
	}
}
