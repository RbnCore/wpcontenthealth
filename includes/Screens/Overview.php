<?php
/**
 * Pano: hata puanı + öneri puanı, tarama durumu, puan geçmişi, grup kartları ve öncelikli kritik sorunlar.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Screens;

use ContentHealth\Issues;
use ContentHealth\Registry;
use ContentHealth\Rule;
use ContentHealth\Runs;
use ContentHealth\Score;
use ContentHealth\Settings;
use ContentHealth\Ui;

defined( 'ABSPATH' ) || exit;

final class Overview {

	private const GROUP_ICONS = array(
		'content'  => 'broom',
		'seo'      => 'seo',
		'media'    => 'image',
		'links'    => 'link',
		'taxonomy' => 'tag',
	);

	public static function dialogs(): void {}

	/** @return array{post:int,term:int} Son bitmiş koşunun taranan nesne sayıları. */
	public static function totals(): array {
		$done = Runs::latest_finished();
		return array(
			'post' => (int) ( $done->stats['posts'] ?? 0 ),
			'term' => (int) ( $done->stats['terms'] ?? 0 ),
		);
	}

	/**
	 * Bir grubun puanı ve ciddiyet sayıları.
	 *
	 * @param array<string,array<string,int>> $open rule_id → [tür → sayı].
	 * @return array{score:int,counts:array<string,int>}
	 */
	public static function group_stats( string $group, array $open ): array {
		$sub    = array();
		$counts = array(
			Rule::CRITICAL => 0,
			Rule::WARNING  => 0,
			Rule::INFO     => 0,
		);
		foreach ( $open as $rid => $types ) {
			$rule = Registry::get( (string) $rid );
			if ( $rule && $rule->group() === $group ) {
				$sub[ $rid ]                  = $types;
				$counts[ $rule->severity() ] += array_sum( $types );
			}
		}
		$sc = Score::compute( $sub, self::totals() );
		return array(
			'score'  => $sc['all'],
			'counts' => $counts,
		);
	}

	public static function render(): void {
		$done   = Runs::latest_finished();
		$active = Runs::active();
		$open   = Issues::open_counts();
		$hist   = array_values( array_filter( array_reverse( Runs::history( 30 ) ), static fn( $r ) => 'finished' === $r->status && null !== $r->score ) );
		$live   = $done ? Score::live() : null;

		echo '<div class="ch-grid ch-grid--score">';
		self::score_card( $done, $live );
		self::scan_card( $done, $active );
		self::spark_card( $hist, $live );
		self::how_card( $live, $open );
		echo '</div>';

		if ( ! $done && ! $active ) {
			echo '<section class="rbn-card"><div class="rbn-card__b">' . Ui::empty_state( 'scan', __( 'No scan has been run yet', 'contenthealth' ), __( 'Start the first scan; the results appear here as a score and groups.', 'contenthealth' ), '<button type="button" class="rbn-btn rbn-btn--pri" data-scan>' . Ui::icon( 'scan' ) . esc_html__( 'Scan now', 'contenthealth' ) . '</button>' ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		echo '<h2 class="ch-h3" id="h-grp">' . esc_html__( 'Status by group', 'contenthealth' ) . '</h2><ul class="ch-grid ch-grid--5" style="list-style:none;padding:0" aria-labelledby="h-grp">';
		foreach ( Registry::groups() as $gid => $glabel ) {
			$g    = self::group_stats( (string) $gid, $open );
			$band = Ui::band( $g['score'] );
			echo '<li class="ch-gcard"><h3 class="ch-gcard__h"><span class="ch-gcard__i">' . Ui::icon( self::GROUP_ICONS[ $gid ] ?? 'doc' ) . '</span>' . esc_html( (string) $glabel ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			printf(
				'<p class="ch-gcard__sc">%1$d<small>/ 100 · %2$s</small></p><div class="ch-progress ch-progress--%3$s" role="progressbar" aria-label="%4$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%1$d" style="--w:%1$d%%"><i></i></div>',
				(int) $g['score'],
				esc_html( $band['label'] ),
				esc_attr( $band['key'] ),
				/* translators: %s: group label */
				esc_attr( sprintf( __( '%s score', 'contenthealth' ), $glabel ) )
			);
			echo '<ul>';
			foreach ( array( Rule::CRITICAL, Rule::WARNING, Rule::INFO ) as $sev ) {
				$s = Ui::sev( $sev );
				echo '<li><span class="ch-sevico ch-sevico--' . esc_attr( $s['cls'] ) . '">' . Ui::icon( $s['icon'] ) . '</span>' . esc_html( $s['label'] ) . '<b>' . (int) $g['counts'][ $sev ] . '</b></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul><a class="rbn-btn rbn-btn--sm" href="' . esc_url( Ui::url( array( 'tab' => 'issues', 'grup' => $gid ) ) ) . '">' . esc_html__( 'View findings', 'contenthealth' ) . '<span class="rbn-sr">: ' . esc_html( (string) $glabel ) . '</span></a></li>';
		}
		echo '</ul>';

		self::top_card();
	}

	/** @param array{error:int,suggest:int}|null $live */
	private static function score_card( ?object $done, ?array $live ): void {
		echo '<section class="rbn-card ch-card--puan" aria-labelledby="h-score"><div class="rbn-card__h"><h2 id="h-score">' . esc_html__( 'Error score', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Errors on the site, 0–100.', 'contenthealth' ) . '</p></div><div class="rbn-card__b">';
		if ( ! $done || ! $live ) {
			echo Ui::empty_state( 'chart', __( 'No score', 'contenthealth' ), __( 'Calculated when the first scan finishes.', 'contenthealth' ) ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$score = $live['error'];
		$band  = Ui::band( $score );
		printf(
			'<div class="ch-ring ch-ring--%1$s"><div class="ch-ring__svg" role="img" aria-label="%2$s"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="ch-ring__track" cx="60" cy="60" r="52"/><circle class="ch-ring__val" cx="60" cy="60" r="52" style="--p:%3$d"/></svg><div class="ch-ring__n" aria-hidden="true"><b data-score-error>%3$d</b><span>/ 100</span></div></div>%4$s</div>',
			esc_attr( $band['key'] ),
			/* translators: 1: score, 2: band label */
			esc_attr( sprintf( __( 'Error score: %1$d / 100, %2$s', 'contenthealth' ), $score, $band['label'] ) ),
			$score,
			Ui::badge( $band['key'], $band['icon'], $band['label'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		$d   = $score - (int) $done->score;
		$col = $d > 0 ? 'var(--rbn-ok)' : ( $d < 0 ? 'var(--rbn-err)' : 'inherit' );
		printf(
			'<p class="rbn-muted" style="text-align:center;margin:var(--rbn-s3) 0 0">%s%s <strong style="color:%s" data-score-delta>%s</strong></p>',
			Ui::icon( 'chart' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Based on the last scan', 'contenthealth' ),
			esc_attr( $col ),
			/* translators: %s: signed number */
			esc_html( sprintf( __( '%s points', 'contenthealth' ), ( $d > 0 ? '+' : ( $d < 0 ? '−' : '±' ) ) . abs( $d ) ) )
		);
		$sg   = $live['suggest'];
		$sb   = Ui::band( $sg );
		$last = isset( $done->stats['suggest_score'] ) ? (string) (int) $done->stats['suggest_score'] : '—';
		printf(
			'<div class="ch-suggest" style="margin-top:var(--rbn-s4)"><div class="ch-progress__l"><span>%1$s</span><b style="white-space:nowrap" data-score-suggest>%3$d / 100</b></div><div class="ch-progress ch-progress--%4$s" role="progressbar" aria-label="%1$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%3$d" style="--w:%3$d%%"><i></i></div><p class="rbn-help" style="margin:var(--rbn-s1) 0 0">%2$s. %5$s</p></div>',
			esc_html__( 'Suggestion score', 'contenthealth' ),
			esc_html__( 'Improvement suggestions', 'contenthealth' ),
			$sg,
			esc_attr( $sb['key'] ),
			/* translators: 1: error score at last scan, 2: suggestion score at last scan or a dash */
			esc_html( sprintf( __( 'In the last scan: error score %1$d · suggestion score %2$s', 'contenthealth' ), (int) $done->score, $last ) )
		);
		echo '</div></section>';
	}

	/** Sabit ağırlık/tavan değeri: 15 → "15", 1.5 → "1,5" (site diline göre). */
	private static function num( float $v ): string {
		return number_format_i18n( $v, floor( $v ) === $v ? 0 : 1 );
	}

	/**
	 * "Puan nasıl hesaplanır?": her zaman açık, yatay kart. Tablo Score::WEIGHT/CAP'ten, "en çok puan götüren 3 kural" Score::live()['penalties']'ten.
	 *
	 * @param array{error:int,suggest:int,penalties?:array<string,float>}|null $live
	 * @param array<string,array<string,int>>                                 $open rule_id → [tür → açık bulgu].
	 */
	private static function how_card( ?array $live, array $open ): void {
		echo '<section class="rbn-card ch-card--nasil" aria-labelledby="h-how"><div class="rbn-card__h"><h2 id="h-how">' . esc_html__( 'How is the score calculated?', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Score = 100 − Σ penalty. Penalty = weight × percentage of affected content; the penalty of each rule cannot exceed its cap.', 'contenthealth' ) . '</p></div><div class="rbn-card__b"><div class="ch-how">';
		echo '<div><div class="rbn-tw" tabindex="0" role="region" aria-label="' . esc_attr__( 'Weight and cap by severity', 'contenthealth' ) . '"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Weight and cap by severity', 'contenthealth' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Level', 'contenthealth' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Weight per percent', 'contenthealth' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Maximum penalty per rule', 'contenthealth' ) . '</th></tr></thead><tbody>';
		foreach ( array( Rule::CRITICAL, Rule::WARNING, Rule::INFO ) as $sev ) {
			echo '<tr><th scope="row">' . esc_html( Ui::sev( $sev )['label'] ) . '</th><td class="rbn-num" data-label="' . esc_attr__( 'Weight per percent', 'contenthealth' ) . '">' . esc_html( self::num( Score::WEIGHT[ $sev ] ) ) . '</td><td class="rbn-num" data-label="' . esc_attr__( 'Maximum penalty per rule', 'contenthealth' ) . '">' . esc_html( self::num( Score::CAP[ $sev ] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div><p class="rbn-help">' . esc_html__( 'Error score = Critical + Warning only; Suggestion score = Info only.', 'contenthealth' ) . '</p></div>';

		$pen = array_filter( $live['penalties'] ?? array(), static fn( $p ) => $p > 0 );
		arsort( $pen );
		echo '<div><h3 class="ch-h3" id="h-how-top">' . esc_html__( 'The 3 rules costing the most points right now', 'contenthealth' ) . '</h3>';
		if ( ! $pen ) {
			echo '<p class="rbn-muted">' . esc_html__( 'No rule is losing points', 'contenthealth' ) . '</p>';
		} else {
			echo '<ol class="ch-how__top" aria-labelledby="h-how-top">';
			foreach ( array_slice( $pen, 0, 3, true ) as $rid => $p ) {
				$rule = Registry::get( (string) $rid );
				echo '<li><span class="ch-how__n">' . esc_html( $rule ? $rule->label() : (string) $rid ) . '</span><span class="ch-how__c">'
					/* translators: %d: open issue count */
					. esc_html( sprintf( __( '%d open', 'contenthealth' ), array_sum( $open[ $rid ] ?? array() ) ) ) . '</span><b class="ch-how__p">'
					/* translators: %s: penalty points */
					. esc_html( sprintf( __( '−%s points', 'contenthealth' ), number_format_i18n( $p, 1 ) ) ) . '</b></li>';
			}
			echo '</ol>';
		}
		echo '</div></div></div></section>';
	}

	private static function scan_card( ?object $done, ?object $active ): void {
		$p      = $active ? Runs::progress( $active ) : array( 'percent' => 0, 'done' => 0, 'total' => 0 );
		$paused = $active && 'paused' === $active->status;
		echo '<section class="rbn-card ch-card--tarama" aria-labelledby="h-scan"><div class="rbn-card__h"><h2 id="h-scan">' . esc_html__( 'Scan', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Posts, pages, products, media and tags are scanned in batches.', 'contenthealth' ) . '</p></div><div class="rbn-card__b">';
		printf(
			'<div id="scanbox"%1$s><div class="ch-progress__l"><span id="scan-msg">%2$s</span><b id="scan-pct">%3$d%%</b></div><div class="ch-progress" role="progressbar" id="scan-bar" aria-label="%4$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%3$d" style="--w:%3$d%%"><i></i></div><p class="rbn-help" id="scan-sub" style="margin:var(--rbn-s2) 0 var(--rbn-s3)">%5$s</p><div class="ch-row"><button type="button" class="rbn-btn rbn-btn--sm" data-scan-pause>%6$s<span>%7$s</span></button><button type="button" class="rbn-btn rbn-btn--sm rbn-btn--danger" data-scan-cancel>%8$s</button></div></div>',
			$active ? '' : ' hidden',
			esc_html( $paused ? __( 'Scan paused', 'contenthealth' ) : __( 'Scanning…', 'contenthealth' ) ),
			(int) $p['percent'],
			esc_attr__( 'Scan progress', 'contenthealth' ),
			esc_html( (int) $p['done'] . ' / ' . (int) $p['total'] . ' ' . __( 'items', 'contenthealth' ) ),
			Ui::icon( $paused ? 'play' : 'pause' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $paused ? __( 'Resume', 'contenthealth' ) : __( 'Pause', 'contenthealth' ) ),
			esc_html__( 'Cancel scan', 'contenthealth' )
		);
		echo '<div id="scanidle"' . ( $active ? ' hidden' : '' ) . '>';
		if ( $done ) {
			$next = wp_next_scheduled( 'contenthealth_cron_scan' );
			$freq = (string) Settings::get( 'frequency' );
			$fl   = array(
				'hourly' => __( 'hourly', 'contenthealth' ),
				'daily'  => __( 'daily', 'contenthealth' ),
				'weekly' => __( 'weekly', 'contenthealth' ),
			);
			echo '<dl class="ch-dl"><dt>' . esc_html__( 'Last scan', 'contenthealth' ) . '</dt><dd>' . Ui::datetime( $done->finished_at ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( isset( $done->stats['seconds'] ) ) {
				echo '<dt>' . esc_html__( 'Duration', 'contenthealth' ) . '</dt><dd>' . esc_html( (string) round( (float) $done->stats['seconds'] ) ) . ' ' . esc_html__( 's', 'contenthealth' ) . '</dd>';
			}
			echo '<dt>' . esc_html__( 'Scope', 'contenthealth' ) . '</dt><dd>' . esc_html( (int) ( $done->stats['posts'] ?? 0 ) . ' ' . __( 'items', 'contenthealth' ) . ' · ' . (int) ( $done->stats['terms'] ?? 0 ) . ' ' . __( 'tags', 'contenthealth' ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Next scan', 'contenthealth' ) . '</dt><dd>' . ( $next && isset( $fl[ $freq ] ) ? esc_html( wp_date( 'j M Y, H:i', (int) $next ) . ' (' . $fl[ $freq ] . ')' ) : esc_html__( 'Not scheduled (scanned manually)', 'contenthealth' ) ) . '</dd></dl>';
		} else {
			echo '<p class="rbn-muted">' . esc_html__( 'No scan has been run yet.', 'contenthealth' ) . '</p>';
		}
		echo '</div><div id="scandone" hidden></div></div></section>';
	}

	/**
	 * Tarama noktaları (Hata puanı) + son nokta "şimdi" (canlı).
	 *
	 * @param array<int,object>                 $hist Bitmiş taramalar, eskiden yeniye.
	 * @param array{error:int,suggest:int}|null $live
	 */
	private static function spark_card( array $hist, ?array $live ): void {
		$pts = array_map( static fn( $r ) => (int) $r->score, $hist );
		if ( $live ) {
			$pts[] = $live['error'];
		}
		/* translators: %d: scan count */
		$sub = sprintf( __( 'Last %d scans + now', 'contenthealth' ), max( 1, count( $hist ) ) );
		echo '<section class="rbn-card ch-card--grafik" aria-labelledby="h-spark"><div class="rbn-card__h"><h2 id="h-spark">' . esc_html__( 'Error score over time', 'contenthealth' ) . '</h2><p>' . esc_html( $sub ) . '</p></div><div class="rbn-card__b">';
		if ( count( $pts ) < 2 ) {
			echo '<p class="rbn-muted">' . esc_html__( 'At least one finished scan is needed for the chart.', 'contenthealth' ) . '</p></div></section>';
			return;
		}
		$n    = count( $pts );
		$line = '';
		foreach ( $pts as $i => $v ) {
			$line .= ( 0 === $i ? 'M' : 'L' ) . round( 300 * $i / ( $n - 1 ), 1 ) . ' ' . round( 56 - 52 * $v / 100, 1 );
		}
		$first = $pts[0];
		$last  = $pts[ $n - 1 ];
		$delta = $last - $first;
		$label = sprintf(
			/* translators: 1: point count, 2: first score, 3: last score, 4: signed change */
			__( 'Error score over %1$d points: %2$d → %3$d (%4$s)', 'contenthealth' ),
			$n,
			$first,
			$last,
			( $delta > 0 ? '+' : ( $delta < 0 ? '−' : '±' ) ) . abs( $delta )
		);
		echo '<svg class="ch-spark" viewBox="0 0 300 60" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $label ) . '"><path class="ch-spark__area" d="' . esc_attr( $line . 'L300 60L0 60Z' ) . '"/><path class="ch-spark__line" d="' . esc_attr( $line ) . '"/></svg>';
		/* translators: %d: score */
		$a = sprintf( __( 'First: %d', 'contenthealth' ), $first );
		/* translators: %d: score */
		$b = sprintf( __( 'Now: %d', 'contenthealth' ), $last );
		echo '<div class="ch-spark__ax"><span>' . esc_html( $a ) . '</span><span>' . esc_html( $b ) . '</span></div></div></section>';
	}

	private static function top_card(): void {
		$res = Issues::query( array( 'status' => 'open', 'severity' => Rule::CRITICAL, 'per_page' => 5, 'orderby' => 'last_seen' ) );
		echo '<section class="rbn-card" aria-labelledby="h-top"><div class="rbn-card__h"><h2 id="h-top">' . esc_html__( 'Look at these first', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Critical problems visible directly to visitors.', 'contenthealth' ) . ' <a href="' . esc_url( Ui::url( array( 'tab' => 'issues' ) ) ) . '">' . esc_html__( 'Full list of findings', 'contenthealth' ) . '</a></p></div>';
		if ( ! $res['rows'] ) {
			echo '<div class="rbn-card__b">' . Ui::empty_state( 'shield', __( 'No critical problems', 'contenthealth' ), __( 'There are no open critical findings.', 'contenthealth' ) ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<div class="rbn-tw" tabindex="0" role="region" aria-label="' . esc_attr__( 'Priority problems', 'contenthealth' ) . '"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Priority problems', 'contenthealth' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Severity', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Object', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Problem', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Evidence', 'contenthealth' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Action', 'contenthealth' ) . '</span></th></tr></thead><tbody>';
		foreach ( $res['rows'] as $r ) {
			$rule  = Registry::get( $r->rule_id );
			$title = Ui::object_title( $r );
			echo '<tr><td data-label="' . esc_attr__( 'Severity', 'contenthealth' ) . '">' . Ui::sev_badge( $r->severity ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<th scope="row"><span class="ch-obj">' . esc_html( $title ) . '</span><span class="ch-sub">' . esc_html( Ui::object_sub( $r ) ) . '</span></th>';
			echo '<td data-label="' . esc_attr__( 'Problem', 'contenthealth' ) . '">' . esc_html( $rule ? $rule->label() : $r->rule_id ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Evidence', 'contenthealth' ) . '"><code>' . esc_html( Ui::evidence( $r ) ) . '</code></td>';
			echo '<td><div class="rbn-acts"><a class="rbn-btn rbn-btn--sm" href="' . esc_url( Ui::url( array( 'tab' => 'issues', 'ac' => $r->id ) ) ) . '">' . esc_html__( 'Review', 'contenthealth' ) . '<span class="rbn-sr">: ' . esc_html( $title ) . '</span></a></div></td></tr>';
		}
		echo '</tbody></table></div></section>';
	}
}
