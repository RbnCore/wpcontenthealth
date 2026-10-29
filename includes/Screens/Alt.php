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
		$tpl  = (string) ( Settings::get( 'alt_template' ) ?: __( '{title}', 'contenthealth' ) );
		$site = (string) get_bloginfo( 'name' );

		echo '<div class="rbn-note rbn-note--ok" role="status" id="flash" hidden></div>';
		if ( ! $res['total'] ) {
			echo Ui::empty_state( 'image', __( 'No image is missing alt text', 'contenthealth' ), __( 'There is no open “image has no alt text” finding.', 'contenthealth' ), '<button type="button" class="rbn-btn rbn-btn--pri" data-scan>' . Ui::icon( 'scan' ) . esc_html__( 'Scan now', 'contenthealth' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		echo '<section class="rbn-card" aria-labelledby="h-sab"><div class="rbn-card__h"><h2 id="h-sab">' . esc_html__( 'Alt text template', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Missing alt texts are filled with this template. Existing ones are not touched.', 'contenthealth' ) . '</p></div><div class="rbn-card__b">';
		echo '<div class="rbn-field"><div class="ch-field__l"><label for="sab">' . esc_html__( 'Template', 'contenthealth' ) . '</label><p class="rbn-help" id="sab-h">' . esc_html__( 'Add tokens by clicking them. The template is used only for this fill; the permanent default is in Settings.', 'contenthealth' ) . '</p></div><div class="ch-field__c"><input class="rbn-input rbn-input--mono" id="sab" value="' . esc_attr( $tpl ) . '" maxlength="200" aria-describedby="sab-h sab-t" data-tpl data-site="' . esc_attr( $site ) . '"><div class="ch-toks" id="sab-t" role="group" aria-label="' . esc_attr__( 'Tokens', 'contenthealth' ) . '">';
		foreach ( array( __( '{title}', 'contenthealth' ), __( '{file_name}', 'contenthealth' ), __( '{site_name}', 'contenthealth' ) ) as $tok ) {
			echo '<button type="button" class="ch-tok" data-tok="' . esc_attr( $tok ) . '">' . esc_html( $tok ) . '</button>';
		}
		echo '</div></div></div></div></section>';

		$shown = count( $res['rows'] );
		echo '<section class="rbn-card" aria-labelledby="h-on"><div class="rbn-card__h"><h2 id="h-on">' . esc_html__( 'Preview', 'contenthealth' ) . ' <span class="rbn-count">' . (int) $shown . ' / ' . (int) $res['total'] . '</span></h2><p>' . esc_html__( 'How the template will look on images with empty alt text in the selected posts. Nothing has been saved yet.', 'contenthealth' ) . '</p></div>';
		echo '<div class="ch-bulk is-on" id="bulk" role="region" aria-label="' . esc_attr__( 'Bulk actions', 'contenthealth' ) . '" style="position:static"><p><span data-n>' . (int) $shown . '</span> ' . esc_html__( 'posts selected', 'contenthealth' ) . '</p><div class="ch-bulk__act"><button type="button" class="rbn-btn rbn-btn--pri rbn-btn--sm" data-open="dlg-doldur" data-alt-fill>' . Ui::icon( 'wand' ) . esc_html__( 'Fill in missing…', 'contenthealth' ) . '</button></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="rbn-tw"><table class="rbn-table rbn-table--stack" id="tbl" data-alt><caption class="rbn-sr">' . esc_html__( 'Images missing alt text, preview', 'contenthealth' ) . '</caption><thead><tr><th scope="col" class="ch-ck"><label class="ch-chk"><input type="checkbox" checked data-select-all aria-label="' . esc_attr__( 'Select all', 'contenthealth' ) . '"></label></th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Thumbnail', 'contenthealth' ) . '</span></th><th scope="col">' . esc_html__( 'File', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Used in', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Now', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Suggested alt text', 'contenthealth' ) . '</th></tr></thead><tbody>';
		foreach ( $res['rows'] as $i => $r ) {
			$items = (array) ( $r->details_arr['items'] ?? array() );
			$src   = (string) ( $items[0]['src'] ?? '' );
			$file  = '' !== $src ? basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) : '';
			$title = Ui::object_title( $r );
			$fname = Transform::alt_text( '{file_name}', $title, $src, $site );
			$n     = max( 1, (int) ( $r->details_arr['count'] ?? count( $items ) ) );
			echo '<tr data-obj="' . (int) $r->object_id . '"><td class="ch-ck"><label class="ch-chk"><input type="checkbox" name="b[]" value="' . (int) $r->object_id . '" checked aria-label="' . esc_attr( sprintf( /* translators: %s: object title */ __( 'Select: %s', 'contenthealth' ), $title ) ) . '"></label></td>';
			echo '<td data-label="' . esc_attr__( 'Image', 'contenthealth' ) . '"><span class="ch-thumb" role="img" aria-label="' . esc_attr( sprintf( /* translators: %s: file name */ __( 'Thumbnail of %s', 'contenthealth' ), $file ?: $title ) ) . '" style="background:linear-gradient(135deg,var(--rbn-ac-soft),var(--rbn-surface-2))"></span></td>';
			echo '<th scope="row"><span class="ch-obj">' . esc_html( $file ?: __( '(no file name)', 'contenthealth' ) ) . '</span><span class="ch-sub">' . esc_html( sprintf( /* translators: 1: post title, 2: image count */ _n( 'Title: %1$s · %2$d image', 'Title: %1$s · %2$d images', $n, 'contenthealth' ), $title, $n ) ) . '</span></th>';
			echo '<td data-label="' . esc_attr__( 'Used in', 'contenthealth' ) . '">' . esc_html( Ui::object_sub( $r ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Now', 'contenthealth' ) . '"><span class="ch-empty-v">' . esc_html__( 'empty', 'contenthealth' ) . '</span></td>';
			echo '<td data-label="' . esc_attr__( 'Suggested', 'contenthealth' ) . '"><span class="ch-arrow" aria-hidden="true">→ </span><span data-prev-i="' . (int) $i . '" data-title="' . esc_attr( $title ) . '" data-file="' . esc_attr( $fname ) . '">' . esc_html( Transform::alt_text( $tpl, $title, $src, $site ) ) . '</span></td></tr>';
		}
		echo '</tbody></table></div>' . Ui::pager( (int) $res['total'], self::PER_PAGE, $page, array( 'tab' => 'alt' ) ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function dialogs(): void {
		$t = Ui::tone_attr();
		echo '<dialog class="rbn-dlg" id="dlg-doldur"' . $t . ' aria-labelledby="dlg-doldur-t" aria-describedby="dlg-doldur-d"><div class="rbn-dlg__b"><h2 id="dlg-doldur-t" data-fill-title>' . esc_html__( 'Fill in the alt texts?', 'contenthealth' ) . '</h2><p id="dlg-doldur-d">' . esc_html__( 'Only empty alt texts are filled; existing ones are not touched.', 'contenthealth' ) . '</p><ul class="ch-sum" style="color:var(--rbn-text)" data-fill-list></ul><label class="rbn-check" style="margin-top:var(--rbn-s3)"><input type="checkbox" data-fill-keep' . checked( (bool) Settings::get( 'keep_modified' ), true, false ) . '><span><b>' . esc_html__( 'Keep the last modified date unchanged', 'contenthealth' ) . '</b></span></label></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-close>' . esc_html__( 'Cancel', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--pri" data-confirm-fill>' . esc_html__( 'Fill in alt texts', 'contenthealth' ) . '</button></div></dialog>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		UndoLog::undo_dialog( $t );
	}
}
