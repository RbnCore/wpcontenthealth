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

		echo '<div class="rbn-grid rbn-grid--score">';
		self::score_card( $done, $live );
		self::scan_card( $done, $active );
		self::spark_card( $hist, $live );
		echo '</div>';

		if ( ! $done && ! $active ) {
			echo '<section class="rbn-card"><div class="rbn-card__b rbn-card__b--pad">' . Ui::empty_state( 'scan', __( 'Henüz tarama yapılmadı', 'contenthealth' ), __( 'İlk taramayı başlatın; sonuçlar burada puan ve gruplar olarak görünür.', 'contenthealth' ), '<button type="button" class="rbn-btn rbn-btn--pri" data-scan>' . Ui::icon( 'scan' ) . esc_html__( 'Şimdi tara', 'contenthealth' ) . '</button>' ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		echo '<h2 class="rbn-h3" id="h-grp">' . esc_html__( 'Gruplara göre durum', 'contenthealth' ) . '</h2><ul class="rbn-grid rbn-grid--5" style="list-style:none;padding:0" aria-labelledby="h-grp">';
		foreach ( Registry::groups() as $gid => $glabel ) {
			$g    = self::group_stats( (string) $gid, $open );
			$band = Ui::band( $g['score'] );
			echo '<li class="rbn-gcard"><h3 class="rbn-gcard__h"><span class="rbn-gcard__i">' . Ui::icon( self::GROUP_ICONS[ $gid ] ?? 'doc' ) . '</span>' . esc_html( (string) $glabel ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			printf(
				'<p class="rbn-gcard__sc">%1$d<small>/ 100 · %2$s</small></p><div class="rbn-progress rbn-progress--%3$s" role="progressbar" aria-label="%4$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%1$d" style="--w:%1$d%%"><i></i></div>',
				(int) $g['score'],
				esc_html( $band['label'] ),
				esc_attr( $band['key'] ),
				/* translators: %s: group label */
				esc_attr( sprintf( __( '%s puanı', 'contenthealth' ), $glabel ) )
			);
			echo '<ul>';
			foreach ( array( Rule::CRITICAL, Rule::WARNING, Rule::INFO ) as $sev ) {
				$s = Ui::sev( $sev );
				echo '<li><span class="rbn-sevico rbn-sevico--' . esc_attr( $s['cls'] ) . '">' . Ui::icon( $s['icon'] ) . '</span>' . esc_html( $s['label'] ) . '<b>' . (int) $g['counts'][ $sev ] . '</b></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul><a class="rbn-btn rbn-btn--sm" href="' . esc_url( Ui::url( array( 'tab' => 'issues', 'grup' => $gid ) ) ) . '">' . esc_html__( 'Bulguları gör', 'contenthealth' ) . '<span class="rbn-sr">: ' . esc_html( (string) $glabel ) . '</span></a></li>';
		}
		echo '</ul>';

		self::top_card();
	}

	/** @param array{error:int,suggest:int}|null $live */
	private static function score_card( ?object $done, ?array $live ): void {
		echo '<section class="rbn-card" aria-labelledby="h-score"><div class="rbn-card__h"><h2 id="h-score">' . esc_html__( 'Hata puanı', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Sitedeki hatalar, 0–100.', 'contenthealth' ) . '</p></div><div class="rbn-card__b rbn-card__b--pad">';
		if ( ! $done || ! $live ) {
			echo Ui::empty_state( 'chart', __( 'Puan yok', 'contenthealth' ), __( 'İlk tarama bittiğinde hesaplanır.', 'contenthealth' ) ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$score = $live['error'];
		$band  = Ui::band( $score );
		printf(
			'<div class="rbn-ring rbn-ring--%1$s"><div class="rbn-ring__svg" role="img" aria-label="%2$s"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="rbn-ring__track" cx="60" cy="60" r="52"/><circle class="rbn-ring__val" cx="60" cy="60" r="52" style="--p:%3$d"/></svg><div class="rbn-ring__n" aria-hidden="true"><b data-score-error>%3$d</b><span>/ 100</span></div></div>%4$s</div>',
			esc_attr( $band['key'] ),
			/* translators: 1: score, 2: band label */
			esc_attr( sprintf( __( 'Hata puanı: %1$d / 100, %2$s', 'contenthealth' ), $score, $band['label'] ) ),
			$score,
			Ui::badge( $band['key'], $band['icon'], $band['label'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		$d   = $score - (int) $done->score;
		$col = $d > 0 ? 'var(--rbn-ok)' : ( $d < 0 ? 'var(--rbn-err)' : 'inherit' );
		printf(
			'<p class="rbn-muted" style="text-align:center;margin:12px 0 0">%s%s <strong style="color:%s" data-score-delta>%s</strong></p>',
			Ui::icon( 'chart' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Son taramaya göre', 'contenthealth' ),
			esc_attr( $col ),
			/* translators: %s: signed number */
			esc_html( sprintf( __( '%s puan', 'contenthealth' ), ( $d > 0 ? '+' : ( $d < 0 ? '−' : '±' ) ) . abs( $d ) ) )
		);
		$sg   = $live['suggest'];
		$sb   = Ui::band( $sg );
		$last = isset( $done->stats['suggest_score'] ) ? (string) (int) $done->stats['suggest_score'] : '—';
		printf(
			'<div class="rbn-suggest" style="margin-top:18px"><div class="rbn-progress__l"><span>%1$s</span><b style="white-space:nowrap" data-score-suggest>%3$d / 100</b></div><div class="rbn-progress rbn-progress--%4$s" role="progressbar" aria-label="%1$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%3$d" style="--w:%3$d%%"><i></i></div><p class="rbn-help" style="margin:6px 0 0">%2$s. %5$s</p></div>',
			esc_html__( 'Öneri puanı', 'contenthealth' ),
			esc_html__( 'İyileştirme önerileri', 'contenthealth' ),
			$sg,
			esc_attr( $sb['key'] ),
			/* translators: 1: error score at last scan, 2: suggestion score at last scan or a dash */
			esc_html( sprintf( __( 'Son taramada: hata puanı %1$d · öneri puanı %2$s', 'contenthealth' ), (int) $done->score, $last ) )
		);
		echo '<details class="rbn-help" style="margin-top:14px"><summary>' . esc_html__( 'Nasıl hesaplanır?', 'contenthealth' ) . '</summary><p>'
			. esc_html__( 'Ceza = ağırlık × etkilenen içerik yüzdesi; kritik/uyarı/bilgi tavanı 15/8/3.', 'contenthealth' ) . '<br>'
			. esc_html__( 'Hata puanı = 100 − kritik ve uyarı cezaları. Öneri puanı = 100 − bilgi cezaları.', 'contenthealth' ) . '<br>'
			. esc_html__( 'Puan bulgu durumuna göre canlı hesaplanır; yazıyı kaydedince ya da düzeltince hemen değişir.', 'contenthealth' )
			. '</p></details>';
		echo '</div></section>';
	}

	private static function scan_card( ?object $done, ?object $active ): void {
		$p      = $active ? Runs::progress( $active ) : array( 'percent' => 0, 'done' => 0, 'total' => 0 );
		$paused = $active && 'paused' === $active->status;
		echo '<section class="rbn-card" aria-labelledby="h-scan"><div class="rbn-card__h"><h2 id="h-scan">' . esc_html__( 'Tarama', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Yazı, sayfa, ürün, medya ve etiketler partiler halinde taranır.', 'contenthealth' ) . '</p></div><div class="rbn-card__b rbn-card__b--pad">';
		printf(
			'<div id="scanbox"%1$s><div class="rbn-progress__l"><span id="scan-msg">%2$s</span><b id="scan-pct">%3$d%%</b></div><div class="rbn-progress" role="progressbar" id="scan-bar" aria-label="%4$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%3$d" style="--w:%3$d%%"><i></i></div><p class="rbn-help" id="scan-sub" style="margin:8px 0 12px">%5$s</p><div class="rbn-row"><button type="button" class="rbn-btn rbn-btn--sm" data-scan-pause>%6$s<span>%7$s</span></button><button type="button" class="rbn-btn rbn-btn--sm rbn-btn--danger" data-scan-cancel>%8$s</button></div></div>',
			$active ? '' : ' hidden',
			esc_html( $paused ? __( 'Tarama duraklatıldı', 'contenthealth' ) : __( 'Taranıyor…', 'contenthealth' ) ),
			(int) $p['percent'],
			esc_attr__( 'Tarama ilerlemesi', 'contenthealth' ),
			esc_html( (int) $p['done'] . ' / ' . (int) $p['total'] . ' ' . __( 'içerik', 'contenthealth' ) ),
			Ui::icon( $paused ? 'play' : 'pause' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $paused ? __( 'Sürdür', 'contenthealth' ) : __( 'Duraklat', 'contenthealth' ) ),
			esc_html__( 'İptal et', 'contenthealth' )
		);
		echo '<div id="scanidle"' . ( $active ? ' hidden' : '' ) . '>';
		if ( $done ) {
			$next = wp_next_scheduled( 'contenthealth_cron_scan' );
			$freq = (string) Settings::get( 'frequency' );
			$fl   = array(
				'hourly' => __( 'saatlik', 'contenthealth' ),
				'daily'  => __( 'günlük', 'contenthealth' ),
				'weekly' => __( 'haftalık', 'contenthealth' ),
			);
			echo '<dl class="rbn-dl"><dt>' . esc_html__( 'Son tarama', 'contenthealth' ) . '</dt><dd>' . Ui::datetime( $done->finished_at ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( isset( $done->stats['seconds'] ) ) {
				echo '<dt>' . esc_html__( 'Süre', 'contenthealth' ) . '</dt><dd>' . esc_html( (string) round( (float) $done->stats['seconds'] ) ) . ' ' . esc_html__( 'sn', 'contenthealth' ) . '</dd>';
			}
			echo '<dt>' . esc_html__( 'Kapsam', 'contenthealth' ) . '</dt><dd>' . esc_html( (int) ( $done->stats['posts'] ?? 0 ) . ' ' . __( 'içerik', 'contenthealth' ) . ' · ' . (int) ( $done->stats['terms'] ?? 0 ) . ' ' . __( 'etiket', 'contenthealth' ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Sonraki tarama', 'contenthealth' ) . '</dt><dd>' . ( $next && isset( $fl[ $freq ] ) ? esc_html( wp_date( 'j M Y, H:i', (int) $next ) . ' (' . $fl[ $freq ] . ')' ) : esc_html__( 'Zamanlanmadı (elle taranır)', 'contenthealth' ) ) . '</dd></dl>';
		} else {
			echo '<p class="rbn-muted">' . esc_html__( 'Henüz tarama yapılmadı.', 'contenthealth' ) . '</p>';
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
		$sub = sprintf( __( 'Son %d tarama + şimdi', 'contenthealth' ), max( 1, count( $hist ) ) );
		echo '<section class="rbn-card" aria-labelledby="h-spark"><div class="rbn-card__h"><h2 id="h-spark">' . esc_html__( 'Zamanla hata puanı', 'contenthealth' ) . '</h2><p>' . esc_html( $sub ) . '</p></div><div class="rbn-card__b rbn-card__b--pad">';
		if ( count( $pts ) < 2 ) {
			echo '<p class="rbn-muted">' . esc_html__( 'Grafik için en az bir bitmiş tarama gerekir.', 'contenthealth' ) . '</p></div></section>';
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
			__( '%1$d noktada hata puanı %2$d → %3$d (%4$s)', 'contenthealth' ),
			$n,
			$first,
			$last,
			( $delta > 0 ? '+' : ( $delta < 0 ? '−' : '±' ) ) . abs( $delta )
		);
		echo '<svg class="rbn-spark" viewBox="0 0 300 60" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $label ) . '"><path class="rbn-spark__area" d="' . esc_attr( $line . 'L300 60L0 60Z' ) . '"/><path class="rbn-spark__line" d="' . esc_attr( $line ) . '"/></svg>';
		/* translators: %d: score */
		$a = sprintf( __( 'İlk: %d', 'contenthealth' ), $first );
		/* translators: %d: score */
		$b = sprintf( __( 'Şimdi: %d', 'contenthealth' ), $last );
		echo '<div class="rbn-spark__ax"><span>' . esc_html( $a ) . '</span><span>' . esc_html( $b ) . '</span></div></div></section>';
	}

	private static function top_card(): void {
		$res = Issues::query( array( 'status' => 'open', 'severity' => Rule::CRITICAL, 'per_page' => 5, 'orderby' => 'last_seen' ) );
		echo '<section class="rbn-card" aria-labelledby="h-top"><div class="rbn-card__h"><h2 id="h-top">' . esc_html__( 'Önce bunlara bakın', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Ziyaretçiye doğrudan görünen kritik sorunlar.', 'contenthealth' ) . ' <a href="' . esc_url( Ui::url( array( 'tab' => 'issues' ) ) ) . '">' . esc_html__( 'Tüm bulgu listesi', 'contenthealth' ) . '</a></p></div>';
		if ( ! $res['rows'] ) {
			echo '<div class="rbn-card__b rbn-card__b--pad">' . Ui::empty_state( 'shield', __( 'Kritik sorun yok', 'contenthealth' ), __( 'Açık kritik bulgu bulunmuyor.', 'contenthealth' ) ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Öncelikli sorunlar', 'contenthealth' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Ciddiyet', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Nesne', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Sorun', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Kanıt', 'contenthealth' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Eylem', 'contenthealth' ) . '</span></th></tr></thead><tbody>';
		foreach ( $res['rows'] as $r ) {
			$rule  = Registry::get( $r->rule_id );
			$title = Ui::object_title( $r );
			echo '<tr><td data-l="' . esc_attr__( 'Ciddiyet', 'contenthealth' ) . '">' . Ui::sev_badge( $r->severity ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<th scope="row"><span class="rbn-obj">' . esc_html( $title ) . '</span><span class="rbn-sub">' . esc_html( Ui::object_sub( $r ) ) . '</span></th>';
			echo '<td data-l="' . esc_attr__( 'Sorun', 'contenthealth' ) . '">' . esc_html( $rule ? $rule->label() : $r->rule_id ) . '</td>';
			echo '<td data-l="' . esc_attr__( 'Kanıt', 'contenthealth' ) . '"><code>' . esc_html( Ui::evidence( $r ) ) . '</code></td>';
			echo '<td><div class="rbn-acts"><a class="rbn-btn rbn-btn--sm" href="' . esc_url( Ui::url( array( 'tab' => 'issues', 'ac' => $r->id ) ) ) . '">' . esc_html__( 'İncele', 'contenthealth' ) . '<span class="rbn-sr">: ' . esc_html( $title ) . '</span></a></div></td></tr>';
		}
		echo '</tbody></table></div></section>';
	}
}
