<?php
/**
 * Alt Metin: şablon, önizleme ve toplu doldurma (yazı içeriğindeki alt'ı boş görseller).
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Screens;

use ContentHealth\Issues as IssueStore;
use ContentHealth\Settings;
use ContentHealth\Transform;
use ContentHealth\Ui;

defined( 'ABSPATH' ) || exit;

final class Alt {

	private const RULE     = 'images_missing_alt';
	private const PER_PAGE = 20;

	public static function count(): int {
		$open = IssueStore::open_counts();
		return isset( $open[ self::RULE ] ) ? (int) array_sum( $open[ self::RULE ] ) : 0;
	}

	public static function render(): void {
		$page = max( 1, isset( $_GET['sayfa'] ) ? (int) $_GET['sayfa'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res  = IssueStore::query( array( 'rule' => self::RULE, 'status' => 'open', 'per_page' => self::PER_PAGE, 'page' => $page ) );
		$tpl  = (string) ( Settings::get( 'alt_template' ) ?: '{başlık}' );
		$site = (string) get_bloginfo( 'name' );

		echo '<div class="rbn-note rbn-note--ok" role="status" id="flash" hidden></div>';
		if ( ! $res['total'] ) {
			echo Ui::empty_state( 'img', __( 'Alt metni eksik görsel yok', 'contenthealth' ), __( 'Açık “görselde alt metin yok” bulgusu bulunmuyor.', 'contenthealth' ), '<button type="button" class="rbn-btn rbn-btn--pri" data-scan>' . Ui::icon( 'scan' ) . esc_html__( 'Şimdi tara', 'contenthealth' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		echo '<section class="rbn-card" aria-labelledby="h-sab"><div class="rbn-card__h"><h2 id="h-sab">' . esc_html__( 'Alt metin şablonu', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Eksik alt metinler bu şablonla doldurulur. Dolu olanlara dokunulmaz.', 'contenthealth' ) . '</p></div><div class="rbn-card__b">';
		echo '<div class="rbn-field"><div class="rbn-field__l"><label for="sab">' . esc_html__( 'Şablon', 'contenthealth' ) . '</label><p class="rbn-help" id="sab-h">' . esc_html__( 'Jetonlara tıklayarak ekleyin. Şablon yalnız bu doldurma için kullanılır; kalıcı varsayılan Ayarlar’dadır.', 'contenthealth' ) . '</p></div><div class="rbn-field__c"><input class="rbn-input rbn-input--mono" id="sab" value="' . esc_attr( $tpl ) . '" maxlength="200" aria-describedby="sab-h sab-t" data-tpl data-site="' . esc_attr( $site ) . '"><div class="rbn-toks" id="sab-t" role="group" aria-label="' . esc_attr__( 'Jetonlar', 'contenthealth' ) . '">';
		foreach ( array( '{başlık}', '{dosya_adı}', '{site_adı}' ) as $tok ) {
			echo '<button type="button" class="rbn-tok" data-tok="' . esc_attr( $tok ) . '">' . esc_html( $tok ) . '</button>';
		}
		echo '</div></div></div></div></section>';

		$shown = count( $res['rows'] );
		echo '<section class="rbn-card" aria-labelledby="h-on"><div class="rbn-card__h"><h2 id="h-on">' . esc_html__( 'Önizleme', 'contenthealth' ) . ' <span class="rbn-count">' . (int) $shown . ' / ' . (int) $res['total'] . '</span></h2><p>' . esc_html__( 'Seçili yazılardaki alt metni boş görsellerde şablonun nasıl görüneceği. Henüz hiçbir şey kaydedilmedi.', 'contenthealth' ) . '</p></div>';
		echo '<div class="rbn-bulk is-on" id="bulk" role="region" aria-label="' . esc_attr__( 'Toplu eylemler', 'contenthealth' ) . '" style="position:static"><p><span data-n>' . (int) $shown . '</span> ' . esc_html__( 'yazı seçili', 'contenthealth' ) . '</p><div class="rbn-bulk__act"><button type="button" class="rbn-btn rbn-btn--pri rbn-btn--sm" data-open="dlg-doldur" data-alt-fill>' . Ui::icon( 'wand' ) . esc_html__( 'Eksik olanları doldur…', 'contenthealth' ) . '</button></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="rbn-tw"><table class="rbn-table rbn-table--stack rbn-table--sel" id="tbl" data-alt><caption class="rbn-sr">' . esc_html__( 'Alt metni eksik görseller, önizleme', 'contenthealth' ) . '</caption><thead><tr><th scope="col" class="rbn-ck"><label class="rbn-chk"><input type="checkbox" checked data-select-all aria-label="' . esc_attr__( 'Tümünü seç', 'contenthealth' ) . '"></label></th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Küçük resim', 'contenthealth' ) . '</span></th><th scope="col">' . esc_html__( 'Dosya', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Kullanıldığı yer', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Şimdi', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Önerilen alt metin', 'contenthealth' ) . '</th></tr></thead><tbody>';
		foreach ( $res['rows'] as $i => $r ) {
			$items = (array) ( $r->details_arr['items'] ?? array() );
			$src   = (string) ( $items[0]['src'] ?? '' );
			$file  = '' !== $src ? basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) : '';
			$title = Ui::object_title( $r );
			$fname = Transform::alt_text( '{dosya_adı}', $title, $src, $site );
			$n     = max( 1, (int) ( $r->details_arr['count'] ?? count( $items ) ) );
			echo '<tr data-obj="' . (int) $r->object_id . '"><td class="rbn-ck"><label class="rbn-chk"><input type="checkbox" name="b[]" value="' . (int) $r->object_id . '" checked aria-label="' . esc_attr( sprintf( /* translators: %s: object title */ __( 'Seç: %s', 'contenthealth' ), $title ) ) . '"></label></td>';
			echo '<td data-l="' . esc_attr__( 'Görsel', 'contenthealth' ) . '"><span class="rbn-thumb" role="img" aria-label="' . esc_attr( sprintf( /* translators: %s: file name */ __( '%s küçük resmi', 'contenthealth' ), $file ?: $title ) ) . '" style="background:linear-gradient(135deg,var(--rbn-ac-soft),var(--rbn-surface-2))"></span></td>';
			echo '<th scope="row"><span class="rbn-obj">' . esc_html( $file ?: __( '(dosya adı yok)', 'contenthealth' ) ) . '</span><span class="rbn-sub">' . esc_html( sprintf( /* translators: 1: post title, 2: image count */ _n( 'Başlık: %1$s · %2$d görsel', 'Başlık: %1$s · %2$d görsel', $n, 'contenthealth' ), $title, $n ) ) . '</span></th>';
			echo '<td data-l="' . esc_attr__( 'Kullanıldığı yer', 'contenthealth' ) . '">' . esc_html( Ui::object_sub( $r ) ) . '</td>';
			echo '<td data-l="' . esc_attr__( 'Şimdi', 'contenthealth' ) . '"><span class="rbn-empty-v">' . esc_html__( 'boş', 'contenthealth' ) . '</span></td>';
			echo '<td data-l="' . esc_attr__( 'Önerilen', 'contenthealth' ) . '"><span class="rbn-arrow" aria-hidden="true">→ </span><span data-prev-i="' . (int) $i . '" data-title="' . esc_attr( $title ) . '" data-file="' . esc_attr( $fname ) . '">' . esc_html( Transform::alt_text( $tpl, $title, $src, $site ) ) . '</span></td></tr>';
		}
		echo '</tbody></table></div>' . Ui::pager( (int) $res['total'], self::PER_PAGE, $page, array( 'tab' => 'alt' ) ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function dialogs(): void {
		$t = Ui::tone_attr();
		echo '<dialog class="rbn-dlg" id="dlg-doldur"' . $t . ' aria-labelledby="dlg-doldur-t" aria-describedby="dlg-doldur-d"><div class="rbn-dlg__b"><h2 id="dlg-doldur-t" data-fill-title>' . esc_html__( 'Alt metinler doldurulsun mu?', 'contenthealth' ) . '</h2><p id="dlg-doldur-d">' . esc_html__( 'Yalnız boş alt metinler doldurulur; dolu olanlara dokunulmaz.', 'contenthealth' ) . '</p><ul class="rbn-sum" style="color:var(--rbn-text)" data-fill-list></ul><label class="rbn-check" style="margin-top:12px"><input type="checkbox" data-fill-keep' . checked( (bool) Settings::get( 'keep_modified' ), true, false ) . '><span><b>' . esc_html__( 'Son değiştirilme tarihi değişmesin', 'contenthealth' ) . '</b></span></label></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-close>' . esc_html__( 'Vazgeç', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-confirm-fill>' . esc_html__( 'Alt metinleri doldur', 'contenthealth' ) . '</button></div></dialog>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		UndoLog::undo_dialog( $t );
	}
}
