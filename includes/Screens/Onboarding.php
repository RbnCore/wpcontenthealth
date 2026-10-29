<?php
/**
 * İlk açılış: taranacak içerik, sıklık ve ilk tarama (3 adım).
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Screens;

use ContentHealth\Runs;
use ContentHealth\Settings;
use ContentHealth\Ui;

defined( 'ABSPATH' ) || exit;

final class Onboarding {

	public const OPTION = 'contenthealth_onboarded';

	/** Henüz hiç tarama yapılmamış ve ilk açılış atlanmamış/tamamlanmamışsa true. */
	public static function needed(): bool {
		if ( get_option( self::OPTION ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce aşağıda doğrulanır.
		if ( isset( $_GET['atla'] ) && current_user_can( 'manage_options' ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'contenthealth_atla' ) ) {
			update_option( self::OPTION, 1, false );
			return false;
		}
		if ( Runs::latest() ) { // 0.1.1'den yükseltilen ve zaten tarama yapmış site ilk açılışı görmez.
			update_option( self::OPTION, 1, false );
			return false;
		}
		return true;
	}

	public static function render(): void {
		$s     = Settings::all();
		$posts = (int) ( wp_count_posts( 'post' )->publish ?? 0 ) + (int) ( wp_count_posts( 'page' )->publish ?? 0 );
		$freq  = (string) $s['frequency'];
		$media = ! in_array( 'attachment', (array) ( $s['exclude_post_types'] ?? array() ), true );
		$tax   = ! empty( $s['term_taxonomies'] ?? array() );
		$skip  = wp_nonce_url( Ui::url( array( 'atla' => 1 ) ), 'contenthealth_atla' );

		echo '<section class="rbn-card" aria-labelledby="h-i"><div class="rbn-card__h"><h2 id="h-i">' . esc_html__( 'Welcome to Content Health', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Three short steps before the first scan.', 'contenthealth' ) . '</p></div><div class="rbn-card__b rbn-card__b--pad" data-wiz-first>';
		echo '<ol class="ch-stepper" style="--n:3" aria-label="' . esc_attr__( 'Setup steps', 'contenthealth' ) . '"><li aria-current="step"><b>1</b><span>' . esc_html__( 'What should we scan?', 'contenthealth' ) . '</span></li><li><b>2</b><span>' . esc_html__( 'When?', 'contenthealth' ) . '</span></li><li><b>3</b><span>' . esc_html__( 'First scan', 'contenthealth' ) . '</span></li></ol>';

		$switch = static function ( string $id, string $label, string $help, bool $on, bool $disabled = false ): string {
			return '<label class="rbn-switch"><input type="checkbox" role="switch" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '"' . checked( $on, true, false ) . ( $disabled ? ' disabled' : '' ) . ' aria-describedby="' . esc_attr( $id ) . '-h"><span class="rbn-switch__t" aria-hidden="true"></span><span class="rbn-switch__x"><b>' . esc_html( $label ) . '</b><span class="rbn-help" id="' . esc_attr( $id ) . '-h">' . esc_html( $help ) . '</span></span></label>';
		};
		echo '<section data-fstep="1" tabindex="-1" aria-labelledby="f1"><h3 class="rbn-h3" id="f1">' . esc_html__( '1 / 3 · Content to scan', 'contenthealth' ) . '</h3><div class="rbn-stack">';
		echo $switch( 'f-p', __( 'Posts and pages', 'contenthealth' ), sprintf( /* translators: %d: content count */ __( '%d published items are always scanned.', 'contenthealth' ), $posts ), true, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $switch( 'f-m', __( 'Media library', 'contenthealth' ), __( 'Finds missing alt texts.', 'contenthealth' ), $media ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $switch( 'f-t', __( 'Tags & categories', 'contenthealth' ), __( 'Finds duplicate and empty tags.', 'contenthealth' ), $tax ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>' . Ui::note( 'info', '<p>' . esc_html__( 'Scans', 'contenthealth' ) . ' <strong>' . esc_html__( 'only read', 'contenthealth' ) . '</strong>; ' . esc_html__( 'no content changes unless you approve.', 'contenthealth' ) . '</p>' ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<section data-fstep="2" tabindex="-1" aria-labelledby="f2" hidden><h3 class="rbn-h3" id="f2">' . esc_html__( '2 / 3 · Scan frequency', 'contenthealth' ) . '</h3><div class="rbn-seg" role="radiogroup" aria-label="' . esc_attr__( 'Scan frequency', 'contenthealth' ) . '">';
		foreach ( array( 'daily' => __( 'Daily', 'contenthealth' ), 'weekly' => __( 'Weekly', 'contenthealth' ), 'off' => __( 'Manual only', 'contenthealth' ) ) as $v => $l ) {
			echo '<label><input type="radio" name="sik" value="' . esc_attr( $v ) . '"' . checked( $v, 'weekly' === $freq ? 'weekly' : 'daily', false ) . '><span>' . esc_html( $l ) . '</span></label>';
		}
		echo '</div><p class="rbn-help" style="margin-top:12px">' . esc_html__( 'The automatic scan runs in the background with WP-Cron; you can change it later in Settings.', 'contenthealth' ) . '</p></section>';

		echo '<section data-fstep="3" tabindex="-1" aria-labelledby="f3" hidden><h3 class="rbn-h3" id="f3">' . esc_html__( '3 / 3 · First scan', 'contenthealth' ) . '</h3><p class="rbn-p">' . esc_html__( 'It takes a few seconds to a few minutes depending on the site size. You can leave this page while the scan runs.', 'contenthealth' ) . '</p>';
		echo '<div id="scanbox" hidden><div class="rbn-progress__l"><span id="scan-msg">' . esc_html__( 'Starting scan…', 'contenthealth' ) . '</span><b id="scan-pct">0%</b></div><div class="rbn-progress" role="progressbar" id="scan-bar" aria-label="' . esc_attr__( 'Scan progress', 'contenthealth' ) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="--w:0%"><i></i></div></div>';
		echo '<div id="scandone" hidden>' . Ui::note( 'ok', '<p><strong>' . esc_html__( 'The first scan has finished.', 'contenthealth' ) . '</strong> <span data-first-sum></span></p>', '<a class="rbn-btn rbn-btn--pri rbn-btn--sm" href="' . esc_url( Ui::url() ) . '">' . esc_html__( 'Go to the dashboard', 'contenthealth' ) . '</a>' ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="ch-wiz__f rbn-row rbn-row--between" style="margin-top:20px"><button type="button" class="rbn-btn" data-f-prev hidden>' . esc_html__( 'Back', 'contenthealth' ) . '</button><div class="rbn-row" style="margin-left:auto"><a class="rbn-btn rbn-btn--link" href="' . esc_url( $skip ) . '">' . esc_html__( 'Not now', 'contenthealth' ) . '</a><button type="button" class="rbn-btn rbn-btn--pri" data-f-next>' . esc_html__( 'Continue', 'contenthealth' ) . '</button></div></div>';
		echo '</div></section>';
	}
}
