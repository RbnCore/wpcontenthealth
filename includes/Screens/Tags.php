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
			'term_empty'        => __( 'Boş etiket', 'contenthealth' ),
			'term_duplicate'    => __( 'Yinelenen küme', 'contenthealth' ),
			'term_too_long'     => __( 'Çok uzun ad', 'contenthealth' ),
			'term_single_use'   => __( 'Tek yazılı', 'contenthealth' ),
			'term_ai_residue'   => __( 'YZ kalıntılı', 'contenthealth' ),
			'term_numeric_only' => __( 'Yalnız sayı', 'contenthealth' ),
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
			echo Ui::empty_state( 'tag', __( 'Etiketlerde sorun görünmüyor', 'contenthealth' ), __( 'Tarama sonuçlarında etiket sorunu yok. Yeni bir tarama sonrası burada görünür.', 'contenthealth' ), '<button type="button" class="rbn-btn rbn-btn--pri" data-scan>' . Ui::icon( 'scan' ) . esc_html__( 'Şimdi tara', 'contenthealth' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<ul class="rbn-tiles" aria-label="' . esc_attr__( 'Sorun sınıfları', 'contenthealth' ) . '">';
		foreach ( $classes as $id => $label ) {
			echo '<li><a class="rbn-tile" href="' . esc_url( Ui::url( array( 'tab' => 'tags', 'sinif' => $id ) ) ) . '"' . ( $id === $cur ? ' aria-current="true"' : '' ) . '><b>' . (int) $counts[ $id ] . '</b><span>' . esc_html( $label ) . '</span></a></li>';
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
		echo '<section class="rbn-card" aria-labelledby="h-km"><div class="rbn-card__h"><div class="rbn-row rbn-row--between"><div><h2 id="h-km">' . esc_html__( 'Yakın yinelenen etiketler', 'contenthealth' ) . ' <span class="rbn-count">' . esc_html( sprintf( /* translators: %d: cluster count */ _n( '%d küme', '%d küme', count( $data ), 'contenthealth' ), count( $data ) ) ) . '</span></h2><p>' . esc_html__( 'Yazım biçimi farklı ama aynı konuyu anlatan etiketler. Her kümede korunacak kanonik etiketi seçin.', 'contenthealth' ) . '</p></div>';
		if ( $empty > 0 ) {
			echo '<button type="button" class="rbn-btn rbn-btn--sm" data-open="dlg-empty" data-terms-fix="term_empty">' . Ui::icon( 'trash' ) . esc_html__( 'Boş etiketleri sil…', 'contenthealth' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></div><div class="rbn-card__b rbn-card__b--pad">';
		if ( ! $data ) {
			echo Ui::empty_state( 'check', __( 'Yinelenen etiket kümesi yok', 'contenthealth' ), __( 'Açık yinelenen etiket bulgusu bulunmuyor.', 'contenthealth' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
			$legend = sprintf( __( 'Küme %1$d · %2$s', 'contenthealth' ), $n, $name );
			echo '<fieldset class="rbn-cluster" data-cluster="' . (int) $n . '" data-issues="' . esc_attr( implode( ',', $c['issues'] ) ) . '"><legend>' . esc_html( $legend ) . '</legend>';
			echo '<div role="radiogroup" aria-label="' . esc_attr( sprintf( /* translators: %s: cluster legend */ __( 'Kanonik etiket: %s', 'contenthealth' ), $legend ) ) . '">';
			foreach ( $terms as $t ) {
				printf(
					'<label class="rbn-term"><input type="radio" name="kanon-%1$d" value="%2$d" data-name="%3$s" data-count="%4$d"%5$s><span><span class="rbn-term__n">%3$s</span><span class="rbn-term__m"><span>%6$s <code>%7$s</code></span><span>#%2$d</span></span></span><span class="rbn-term__u">%8$s</span></label>',
					(int) $n,
					(int) $t->term_id,
					esc_attr( $t->name ),
					(int) $t->count,
					checked( (int) $t->term_id, $c['canon'], false ),
					esc_html__( 'Kısa ad:', 'contenthealth' ),
					esc_html( $t->slug ),
					esc_html( sprintf( /* translators: %d: post count */ __( '%d yazı', 'contenthealth' ), (int) $t->count ) )
				);
			}
			echo '</div><div class="rbn-cluster__f"><p data-merge-sum></p><div class="rbn-row"><button type="button" class="rbn-btn rbn-btn--link rbn-btn--sm" data-cluster-ignore>' . esc_html__( 'Yok say', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri rbn-btn--sm" data-open="dlg-merge" data-merge-from="' . (int) $n . '">' . Ui::icon( 'merge' ) . esc_html__( 'Birleştir…', 'contenthealth' ) . '</button></div></div></fieldset>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></section>';
	}

	private static function list( string $rule_id, string $label ): void {
		$rule = Registry::get( $rule_id );
		$page = max( 1, isset( $_GET['sayfa'] ) ? (int) $_GET['sayfa'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res  = IssueStore::query( array( 'rule' => $rule_id, 'status' => 'open', 'per_page' => 20, 'page' => $page ) );
		$fix  = $rule ? $rule->fix_action() : null;
		echo '<section class="rbn-card" aria-labelledby="h-sn"><div class="rbn-card__h"><div class="rbn-row rbn-row--between"><div><h2 id="h-sn">' . esc_html( $label ) . ' <span class="rbn-count">' . (int) $res['total'] . '</span></h2><p>' . esc_html( $rule ? $rule->description() : '' ) . '</p></div>';
		if ( $fix && $res['total'] > 0 ) {
			echo '<button type="button" class="rbn-btn rbn-btn--sm rbn-btn--pri" data-open="dlg-empty" data-terms-fix="' . esc_attr( $rule_id ) . '">' . esc_html( Ui::fix_text( $fix ) ? __( 'Düzelt…', 'contenthealth' ) : '' ) . '</button>';
		}
		echo '</div></div>';
		if ( ! $res['rows'] ) {
			echo '<div class="rbn-card__b rbn-card__b--pad">' . Ui::empty_state( 'check', __( 'Bu sınıfta açık bulgu yok', 'contenthealth' ), '' ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html( $label ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Etiket', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Kanıt', 'contenthealth' ) . '</th><th scope="col" class="rbn-num">' . esc_html__( 'Yazı', 'contenthealth' ) . '</th></tr></thead><tbody>';
		foreach ( $res['rows'] as $r ) {
			$t = get_term( (int) $r->object_id );
			echo '<tr><th scope="row"><span class="rbn-obj">' . esc_html( Ui::object_title( $r ) ) . '</span><span class="rbn-sub">' . esc_html( Ui::object_sub( $r ) ) . '</span></th><td data-l="' . esc_attr__( 'Kanıt', 'contenthealth' ) . '"><span class="rbn-ev"><mark>' . esc_html( Ui::evidence( $r ) ) . '</mark></span></td><td data-l="' . esc_attr__( 'Yazı', 'contenthealth' ) . '" class="rbn-num">' . (int) ( $t && ! is_wp_error( $t ) ? $t->count : 0 ) . '</td></tr>';
		}
		echo '</tbody></table></div>' . Ui::pager( (int) $res['total'], 20, $page, array( 'tab' => 'tags', 'sinif' => $rule_id ) ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function seo_card(): void {
		$seo = Seo::active_plugin();
		$map = array(
			'yoast'    => array( 'Yoast SEO', __( 'Etiket arşivleri Yoast’ın “Taksonomiler” ayarından yönetilir; İçerik Sağlığı bu ayara yazmaz.', 'contenthealth' ) ),
			'rankmath' => array( 'Rank Math', __( 'Etiket arşivleri Rank Math’ın “Başlıklar ve Meta” bölümünden yönetilir.', 'contenthealth' ) ),
			'aioseo'   => array( 'All in One SEO', __( 'Etiket arşivleri All in One SEO’nun arama görünümü ayarından yönetilir.', 'contenthealth' ) ),
		);
		echo '<section class="rbn-card" aria-labelledby="h-ni"><div class="rbn-card__h"><h2 id="h-ni">' . esc_html__( 'Etiketler için noindex', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Zayıf etiket arşivlerinin arama sonuçlarına girmesini engelleyin.', 'contenthealth' ) . '</p></div><div class="rbn-card__b rbn-card__b--pad" id="noindex">';
		if ( isset( $map[ $seo ] ) ) {
			echo Ui::note( 'ok', '<p><strong>' . esc_html( sprintf( /* translators: %s: plugin name */ __( '%s algılandı.', 'contenthealth' ), $map[ $seo ][0] ) ) . '</strong> ' . esc_html( $map[ $seo ][1] ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo Ui::note( 'info', '<p><strong>' . esc_html__( 'SEO eklentisi bulunamadı.', 'contenthealth' ) . '</strong> ' . esc_html__( 'Tek yazılı etiket arşivlerine İçerik Sağlığı noindex ekleyebilir: “Tek yazılı” sınıfını açıp düzeltin. Değişiklik geri alınabilir.', 'contenthealth' ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></section>';
	}

	public static function dialogs(): void {
		$t = Ui::tone_attr();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui/esc_* ile kaçışlı.
		echo '<dialog class="rbn-dlg" id="dlg-merge"' . $t . ' aria-labelledby="dlg-merge-t" aria-describedby="dlg-merge-d"><div class="rbn-dlg__b"><h2 id="dlg-merge-t" data-merge-title></h2><p id="dlg-merge-d">' . esc_html__( 'Kuru koşu: henüz bir şey değişmedi.', 'contenthealth' ) . '</p><ul class="rbn-sum" style="color:var(--rbn-text)" data-merge-list></ul></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-close>' . esc_html__( 'Vazgeç', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-confirm-merge>' . esc_html__( 'Birleştir', 'contenthealth' ) . '</button></div></dialog>';
		echo '<dialog class="rbn-dlg" id="dlg-empty"' . $t . ' aria-labelledby="dlg-empty-t" aria-describedby="dlg-empty-d"><div class="rbn-dlg__b"><h2 id="dlg-empty-t" data-terms-title></h2><p id="dlg-empty-d" data-terms-text></p><div class="rbn-toks" style="margin:0 0 16px" data-terms-toks></div><div class="rbn-note rbn-note--warn">' . Ui::icon( 'alert' ) . '<div><p>' . esc_html__( 'Değişiklikler geri alma günlüğüne yazılır; geri alınabilir.', 'contenthealth' ) . '</p></div></div></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-close>' . esc_html__( 'Vazgeç', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--danger" data-confirm-empty>' . esc_html__( 'Uygula', 'contenthealth' ) . '</button></div></dialog>';
		UndoLog::undo_dialog( $t );
		// phpcs:enable
	}
}
