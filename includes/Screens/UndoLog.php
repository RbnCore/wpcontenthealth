<?php
/**
 * Geri alma günlüğü: uygulanan düzeltme yığınları ve geri alma onayı.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Screens;

use ContentHealth\Registry;
use ContentHealth\Text;
use ContentHealth\Ui;
use ContentHealth\Undo;

defined( 'ABSPATH' ) || exit;

final class UndoLog {

	private const PER_PAGE = 10;

	/** Eylem adından kullanıcıya görünen işlem adı (eylemi taşıyan kuralın etiketi). */
	private static function action_label( string $action ): string {
		foreach ( Registry::rules() as $r ) {
			if ( $r->fix_action() === $action ) {
				return $r->label();
			}
		}
		return $action;
	}

	public static function render(): void {
		$page    = max( 1, isset( $_GET['sayfa'] ) ? (int) $_GET['sayfa'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total   = Undo::batch_count();
		$batches = Undo::batches( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		echo '<section class="rbn-card" aria-labelledby="h-gl"><div class="rbn-card__h"><h2 id="h-gl">' . esc_html__( 'Undo log', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Every automatic fix is stored with its previous values; you can undo it at any time.', 'contenthealth' ) . '</p></div>';
		echo '<div class="rbn-note rbn-note--ok" role="status" id="flash" hidden style="margin:0 16px 16px"></div>';
		if ( ! $batches ) {
			echo '<div class="rbn-card__b rbn-card__b--pad">' . Ui::empty_state( 'undo', __( 'No undoable operation yet', 'contenthealth' ), __( 'It appears here when you apply a fix.', 'contenthealth' ) ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' . esc_html__( 'Applied fixes', 'contenthealth' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'No.', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Time', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Operation', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Scope', 'contenthealth' ) . '</th><th scope="col">' . esc_html__( 'Status', 'contenthealth' ) . '</th><th scope="col"><span class="rbn-sr">' . esc_html__( 'Actions', 'contenthealth' ) . '</span></th></tr></thead><tbody>';
		foreach ( $batches as $b ) {
			$no      = (int) $b->no;
			$pending = (int) $b->pending;
			$items   = (int) $b->items;
			$label   = self::action_label( (string) $b->action );
			$unit    = 'term' === $b->object_type ? __( 'tags', 'contenthealth' ) : __( 'posts', 'contenthealth' );
			if ( 0 === $pending ) {
				$badge = Ui::badge( 'mute', 'undo', __( 'Undone', 'contenthealth' ) );
			} elseif ( $pending < $items ) {
				$badge = Ui::badge( 'warn', 'alert', __( 'Partly undone', 'contenthealth' ) );
			} else {
				$badge = Ui::badge( 'ok', 'check', __( 'Applied', 'contenthealth' ) );
			}
			echo '<tr><th scope="row">#' . $no . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- int.
			echo '<td data-label="' . esc_attr__( 'Time', 'contenthealth' ) . '" class="rbn-nowrap">' . Ui::datetime( $b->created_at ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td data-label="' . esc_attr__( 'Operation', 'contenthealth' ) . '">' . esc_html( $label ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Scope', 'contenthealth' ) . '">' . esc_html( (int) $b->objects . ' ' . $unit ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Status', 'contenthealth' ) . '">' . $badge . '</td><td><div class="rbn-acts">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( $pending > 0 ) {
				printf(
					'<button type="button" class="rbn-btn rbn-btn--sm" data-open="dlg-geri" data-undo="%1$s" data-undo-no="%2$d" data-undo-n="%3$d" data-undo-unit="%4$s" aria-label="%5$s">%6$s%7$s</button>',
					esc_attr( (string) $b->batch_id ),
					$no,
					(int) $b->objects,
					esc_attr( $unit ),
					/* translators: %d: batch number */
					esc_attr( sprintf( __( 'Undo: operation #%d', 'contenthealth' ), $no ) ),
					Ui::icon( 'undo' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html__( 'Undo', 'contenthealth' )
				);
			}
			printf(
				'<button type="button" class="rbn-btn rbn-btn--sm rbn-btn--link" aria-expanded="false" aria-controls="gd-%1$d" data-undo-detail aria-label="%2$s">%3$s</button></div></td></tr>',
				$no,
				/* translators: %d: batch number */
				esc_attr( sprintf( __( 'Details: operation #%d', 'contenthealth' ), $no ) ),
				esc_html__( 'Details', 'contenthealth' )
			);
			echo '<tr id="gd-' . $no . '" hidden><td colspan="6">' . self::batch_detail( (string) $b->batch_id ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>' . Ui::pager( $total, self::PER_PAGE, $page, array( 'tab' => 'undo' ) ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Yığındaki ilk birkaç kaydın nesne ve alan özeti. */
	private static function batch_detail( string $batch ): string {
		$rows = array_slice( Undo::rows( $batch ), 0, 8 );
		$li   = '';
		foreach ( $rows as $r ) {
			$name = 'term' === $r->object_type ? ( ( $t = get_term( (int) $r->object_id ) ) && ! is_wp_error( $t ) ? $t->name : '#' . (int) $r->object_id ) : ( get_the_title( (int) $r->object_id ) ?: '#' . (int) $r->object_id );
			$li  .= '<li>' . Ui::icon( 'check' ) . '<span><strong>' . esc_html( Text::snippet( (string) $name, 80 ) ) . '</strong> · <code>' . esc_html( (string) $r->field ) . '</code>' . ( null !== $r->undone_at ? ' · ' . esc_html__( 'undone', 'contenthealth' ) : '' ) . '</span></li>';
		}
		return '<ul class="rbn-sum" style="color:var(--rbn-text)">' . $li . '</ul>';
	}

	public static function dialogs(): void {
		self::undo_dialog( Ui::tone_attr() );
	}

	/** Geri alma onayı (Bulgular sihirbazı ile ortak). */
	public static function undo_dialog( string $tone_attr ): void {
		echo '<dialog class="rbn-dlg" id="dlg-geri"' . $tone_attr . ' aria-labelledby="dlg-geri-t" aria-describedby="dlg-geri-d"><div class="rbn-dlg__b"><h2 id="dlg-geri-t" data-undo-title>' . esc_html__( 'Undo this operation?', 'contenthealth' ) . '</h2><p id="dlg-geri-d" data-undo-text>' . esc_html__( 'The affected items return to their state before the fix. Manual changes made in between are not overwritten; changed items are skipped.', 'contenthealth' ) . '</p><div class="rbn-note rbn-note--warn">' . Ui::icon( 'alert' ) . '<div><p>' . esc_html__( 'After the undo, the findings reopen in the next scan.', 'contenthealth' ) . '</p></div></div></div><div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-close>' . esc_html__( 'Cancel', 'contenthealth' ) . '</button><button type="button" class="rbn-btn rbn-btn--danger" data-confirm-undo>' . esc_html__( 'Undo', 'contenthealth' ) . '</button></div></dialog>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
