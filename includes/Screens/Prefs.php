<?php
/**
 * Ayarlar: kurallar, tarama, desen düzenleyici, SEO eklentisi ve kaydetme çubuğu.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth\Screens;

use ContentHealth\Admin;
use ContentHealth\Registry;
use ContentHealth\Rule;
use ContentHealth\Seo;
use ContentHealth\Settings;
use ContentHealth\Ui;

defined( 'ABSPATH' ) || exit;

final class Prefs {

	/** Desen düzenleyicide listelenen kural ve alanları. */
	private const EDITOR_RULE   = 'ai_chat_residue';
	private const EDITOR_FIELDS = array( 'patterns_head', 'patterns_tail', 'patterns_anywhere' );

	/**
	 * Anında anahtar işareti: kural tek yerde (AdminKit `Form::instant`), burada kopyalanmaz.
	 *
	 * @param string $path  Motorun şema yolu (`keep_modified`, `rules.latex_leftovers.enabled`).
	 * @param string $label Onay sorusunda görünen ad.
	 */
	private static function instant( string $path, string $label ): string {
		return class_exists( '\AdminKit\Core\Settings\Form' )
			? \AdminKit\Core\Settings\Form::attrs( \AdminKit\Core\Settings\Form::instant( Settings::SLUG, $path, $label ) )
			: '';
	}

	private static function label( string $key ): string {
		$map = array(
			'max_len'          => __( 'Maximum length (characters)', 'contenthealth' ),
			'max_chars'        => __( 'Maximum characters', 'contenthealth' ),
			'max_words'        => __( 'Maximum words', 'contenthealth' ),
			'min_words'        => __( 'Minimum words', 'contenthealth' ),
			'min_len'          => __( 'Minimum length', 'contenthealth' ),
			'min'              => __( 'Minimum', 'contenthealth' ),
			'max'              => __( 'Maximum', 'contenthealth' ),
			'fuzzy_min'        => __( 'Minimum length for fuzzy matching', 'contenthealth' ),
			'max_requests'     => __( 'Maximum requests per scan', 'contenthealth' ),
			'per_second'       => __( 'Requests per second', 'contenthealth' ),
			'post_types'       => __( 'Post types (one per line)', 'contenthealth' ),
			'dev_hosts'        => __( 'Extra development addresses (one per line)', 'contenthealth' ),
			'fallback_excerpt' => __( 'Count the excerpt as a fallback', 'contenthealth' ),
			'bracket_names'    => __( 'Also count names in square brackets', 'contenthealth' ),
			'check_bold'       => __( 'Look for bold marks', 'contenthealth' ),
			'check_heading'    => __( 'Look for heading marks', 'contenthealth' ),
			'check_bullets'    => __( 'Look for list marks', 'contenthealth' ),
			'patterns'         => __( 'Patterns (one per line)', 'contenthealth' ),
			'taxonomies'       => __( 'Taxonomies (one per line)', 'contenthealth' ),
			'allow_years'      => __( 'Allow four-digit years', 'contenthealth' ),
		);
		return $map[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
	}

	/**
	 * Alt sekmeler: kural grupları, tarama, desenler. Her biri tek ekranda rahat okunur.
	 *
	 * @return array<string,string> alt sekme kimliği => etiket.
	 */
	public static function subs(): array {
		return array_merge(
			Registry::groups(),
			array(
				'scan'     => __( 'Scan', 'contenthealth' ),
				'patterns' => __( 'Patterns', 'contenthealth' ),
			)
		);
	}

	/** Gönderilen alt sekme kimliğini doğrular; bilinmiyorsa boş döner. */
	public static function clean_sub( string $sub ): string {
		return isset( self::subs()[ $sub ] ) ? $sub : '';
	}

	public static function render(): void {
		$s    = Settings::all();
		$subs = self::subs();
		$cur  = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cur  = isset( $subs[ $cur ] ) ? $cur : (string) array_key_first( $subs );
		echo '<form id="ayar" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate><input type="hidden" name="action" value="contenthealth_save"><input type="hidden" name="ch_sub" value="' . esc_attr( $cur ) . '" data-sub-now>';
		wp_nonce_field( 'contenthealth_save' );
		echo '<div class="rbn-tabs rbn-tabs--sub" role="tablist" aria-label="' . esc_attr__( 'Settings sections', 'contenthealth' ) . '" data-rbn-tabs>';
		foreach ( $subs as $id => $label ) {
			$on = (string) $id === $cur;
			echo '<a class="rbn-tabs__tab" role="tab" id="stab-' . esc_attr( $id ) . '" aria-controls="spn-' . esc_attr( $id ) . '" aria-selected="' . ( $on ? 'true' : 'false' ) . '" tabindex="' . ( $on ? '0' : '-1' ) . '" data-key="' . esc_attr( $id ) . '" href="' . esc_url( Ui::url( array( 'tab' => 'settings', 'sub' => $id ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</div>';
		foreach ( $subs as $id => $label ) {
			echo '<div class="rbn-tabpanel" role="tabpanel" id="spn-' . esc_attr( $id ) . '" aria-labelledby="stab-' . esc_attr( $id ) . '"' . ( (string) $id === $cur ? '' : ' hidden' ) . '>';
			if ( 'scan' === $id ) {
				self::scan_card( $s );
				self::safety_card( $s );
			} elseif ( 'patterns' === $id ) {
				self::patterns_card( $s );
				self::test_card();
			} else {
				if ( 'seo' === $id ) {
					self::seo_card();
				}
				self::rules_card( (string) $id, (string) $label, $s );
			}
			echo '</div>';
		}
		echo '<div class="rbn-form-actions"><button type="submit" class="rbn-btn rbn-btn--pri" data-save>' . Ui::icon( 'save' ) . esc_html__( 'Save changes', 'contenthealth' ) . '</button><button type="button" class="rbn-btn" data-revert>' . esc_html__( 'Cancel', 'contenthealth' ) . '</button></div></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::update_card();
	}

	/**
	 * Güncelleme kartı: gövde AdminKit'in TEK ortak bileşenidir (Kit\UpdateCard); biz yalnız slug ve dönüş adresi veririz.
	 * Kart kendi formunu taşır (admin-post.php), bu yüzden ayar formunun DIŞINDA çizilir.
	 */
	private static function update_card(): void {
		echo '<section class="rbn-card" aria-labelledby="h-upd"><div class="rbn-card__h"><h2 id="h-upd">' . esc_html__( 'Updates', 'contenthealth' ) . '</h2><p>' . esc_html__( 'New versions come from the official releases on GitHub.', 'contenthealth' ) . '</p></div><div class="rbn-card__b">';
		echo \AdminKit\Kit\UpdateCard::render( 'contenthealth', array( 'return_url' => Ui::url( array( 'tab' => 'settings' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UpdateCard kaçışlı çıktı verir.
		echo '</div></section>';
	}

	/** @param array<string,mixed> $s */
	private static function rules_card( string $gid, string $glabel, array $s ): void {
		echo '<section class="rbn-card" aria-labelledby="h-g-' . esc_attr( $gid ) . '"><div class="rbn-card__h"><h2 id="h-g-' . esc_attr( $gid ) . '">' . esc_html( $glabel ) . '</h2><p>' . esc_html__( 'A rule you turn off does not run in the scan; it does not delete existing findings.', 'contenthealth' ) . '</p></div><div class="rbn-card__b rbn-card__b--flush">';
		foreach ( Registry::rules() as $rule ) {
			if ( $rule->group() === $gid ) {
				self::rule_row( $rule, $s['rules'][ $rule->id() ] ?? $rule->defaults() );
			}
		}
		echo '</div></section>';
	}

	/** @param array<string,mixed> $cfg */
	private static function rule_row( Rule $rule, array $cfg ): void {
		$id   = $rule->id();
		$name = 's[rules][' . $id . ']';
		$dom  = 'r-' . $id;
		echo '<div class="ch-rule"><div class="ch-rule__m"><input type="hidden" name="' . esc_attr( $name ) . '[enabled]" value="0"><label class="rbn-switch"><input type="checkbox" role="switch" id="' . esc_attr( $dom ) . '" name="' . esc_attr( $name ) . '[enabled]" value="1"' . checked( ! empty( $cfg['enabled'] ), true, false ) . self::instant( 'rules.' . $id . '.enabled', $rule->label() ) . ' aria-describedby="' . esc_attr( $dom ) . '-h"><span class="rbn-switch__x"></span><span class="ch-switch__t"><b>' . esc_html( $rule->label() ) . '</b><span class="rbn-help" id="' . esc_attr( $dom ) . '-h">' . esc_html( $rule->description() ) . '</span></span></label></div>';
		$fields = '';
		foreach ( $rule->defaults() as $k => $dv ) {
			if ( 'enabled' === $k || ( self::EDITOR_RULE === $id && in_array( $k, self::EDITOR_FIELDS, true ) ) ) {
				continue;
			}
			$fid = $dom . '-' . $k;
			$val = $cfg[ $k ] ?? $dv;
			if ( is_bool( $dv ) ) {
				$fields .= '<div class="ch-opt"><input type="hidden" name="' . esc_attr( $name . '[' . $k . ']' ) . '" value="0"><label class="rbn-check"><input type="checkbox" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name . '[' . $k . ']' ) . '" value="1"' . checked( (bool) $val, true, false ) . '><span>' . esc_html( self::label( $k ) ) . '</span></label></div>';
			} elseif ( is_int( $dv ) ) {
				$fields .= '<div class="ch-opt"><label for="' . esc_attr( $fid ) . '">' . esc_html( self::label( $k ) ) . '</label><input class="rbn-input rbn-input--s" type="number" min="0" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name . '[' . $k . ']' ) . '" value="' . (int) $val . '"></div>';
			} elseif ( is_array( $dv ) ) {
				$fields .= '<div class="ch-opt ch-opt--wide"><label for="' . esc_attr( $fid ) . '">' . esc_html( self::label( $k ) ) . '</label><textarea class="rbn-textarea rbn-input--mono" rows="' . ( 'patterns' === $k ? 6 : 3 ) . '" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name . '[' . $k . ']' ) . '">' . esc_textarea( implode( "\n", array_map( 'strval', (array) $val ) ) ) . '</textarea></div>';
			} else {
				$fields .= '<div class="ch-opt"><label for="' . esc_attr( $fid ) . '">' . esc_html( self::label( $k ) ) . '</label><input class="rbn-input" type="text" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name . '[' . $k . ']' ) . '" value="' . esc_attr( (string) $val ) . '"></div>';
			}
		}
		echo ( '' === $fields ? '' : '<div class="ch-rule__o">' . $fields . '</div>' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parçalar kaçışlı.
	}

	/** @param array<string,mixed> $s */
	private static function scan_card( array $s ): void {
		$freq = array(
			'off'    => __( 'Manual only', 'contenthealth' ),
			'hourly' => __( 'Hourly', 'contenthealth' ),
			'daily'  => __( 'Daily', 'contenthealth' ),
			'weekly' => __( 'Weekly', 'contenthealth' ),
		);
		$lines = static fn( string $k ): string => esc_textarea( implode( "\n", array_map( 'strval', (array) $s[ $k ] ) ) );
		echo '<section class="rbn-card" aria-labelledby="h-t"><div class="rbn-card__h"><h2 id="h-t">' . esc_html__( 'Scan', 'contenthealth' ) . '</h2></div><div class="rbn-card__b rbn-card__b--flush">';
		echo '<div class="ch-rule"><div class="ch-rule__m"><label for="t-sik">' . esc_html__( 'Scan frequency', 'contenthealth' ) . '</label><p class="rbn-help" id="t-sik-h">' . esc_html__( 'The automatic scan runs in the background with WP-Cron.', 'contenthealth' ) . '</p></div><div class="ch-rule__o"><div class="ch-opt ch-opt--wide"><select class="rbn-select" id="t-sik" name="s[frequency]" aria-describedby="t-sik-h">';
		foreach ( $freq as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( (string) $s['frequency'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></div></div></div>';
		echo '<div class="ch-rule"><div class="ch-rule__m"><span class="rbn-label" id="t-tb-l">' . esc_html__( 'Scan step', 'contenthealth' ) . '</span><p class="rbn-help" id="t-tb-h">' . esc_html__( 'Maximum duration of each scan step (1–20) and number of records per step (10–500).', 'contenthealth' ) . '</p></div><div class="ch-rule__o"><div class="ch-opt"><label for="t-tb">' . esc_html__( 'Step duration (s)', 'contenthealth' ) . '</label><input class="rbn-input rbn-input--s" type="number" id="t-tb" name="s[time_budget]" min="1" max="20" value="' . (int) $s['time_budget'] . '" aria-describedby="t-tb-h"></div><div class="ch-opt"><label for="t-bs">' . esc_html__( 'Records per step', 'contenthealth' ) . '</label><input class="rbn-input rbn-input--s" type="number" id="t-bs" name="s[batch_size]" min="10" max="500" value="' . (int) $s['batch_size'] . '" aria-describedby="t-tb-h"></div></div></div>';
		echo '<div class="ch-rule"><div class="ch-rule__m"><label for="t-st">' . esc_html__( 'Post statuses scanned', 'contenthealth' ) . '</label><p class="rbn-help" id="t-st-h">' . esc_html__( 'One status per line (publish, draft…).', 'contenthealth' ) . '</p></div><div class="ch-rule__o"><div class="ch-opt ch-opt--wide"><textarea class="rbn-textarea rbn-input--mono" id="t-st" name="s[statuses]" rows="2" aria-describedby="t-st-h">' . $lines( 'statuses' ) . '</textarea></div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="ch-rule"><div class="ch-rule__m"><label for="t-hr">' . esc_html__( 'Excluded post types', 'contenthealth' ) . '</label><p class="rbn-help" id="t-hr-h">' . esc_html__( 'One post type name per line.', 'contenthealth' ) . '</p></div><div class="ch-rule__o"><div class="ch-opt ch-opt--wide"><textarea class="rbn-textarea rbn-input--mono" id="t-hr" name="s[exclude_post_types]" rows="2" aria-describedby="t-hr-h">' . $lines( 'exclude_post_types' ) . '</textarea></div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="ch-rule"><div class="ch-rule__m"><label for="t-tx">' . esc_html__( 'Taxonomies scanned', 'contenthealth' ) . '</label><p class="rbn-help" id="t-tx-h">' . esc_html__( 'Tag Doctor looks at these taxonomies. One name per line.', 'contenthealth' ) . '</p></div><div class="ch-rule__o"><div class="ch-opt ch-opt--wide"><textarea class="rbn-textarea rbn-input--mono" id="t-tx" name="s[term_taxonomies]" rows="2" aria-describedby="t-tx-h">' . $lines( 'term_taxonomies' ) . '</textarea></div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="ch-rule"><div class="ch-rule__m"><label for="t-lh">' . esc_html__( 'Live domain', 'contenthealth' ) . '</label><p class="rbn-help" id="t-lh-h">' . esc_html__( 'The target of the wrong-address fix. If empty, the site address is used.', 'contenthealth' ) . '</p></div><div class="ch-rule__o"><div class="ch-opt ch-opt--wide"><input class="rbn-input rbn-input--mono" id="t-lh" name="s[live_host]" value="' . esc_attr( (string) $s['live_host'] ) . '" aria-describedby="t-lh-h"></div></div></div>';
		echo '<div class="ch-rule"><div class="ch-rule__m"><label for="t-alt">' . esc_html__( 'Alt text template', 'contenthealth' ) . '</label><p class="rbn-help" id="t-alt-h">' . esc_html__( 'Available tokens: {title}, {file_name}, {site_name}.', 'contenthealth' ) . '</p></div><div class="ch-rule__o"><div class="ch-opt ch-opt--wide"><input class="rbn-input rbn-input--mono" id="t-alt" name="s[alt_template]" maxlength="200" value="' . esc_attr( (string) $s['alt_template'] ) . '" aria-describedby="t-alt-h"></div></div></div>';
		echo '</div></section>';
	}

	/** @param array<string,mixed> $s */
	private static function safety_card( array $s ): void {
		echo '<section class="rbn-card" aria-labelledby="h-sf"><div class="rbn-card__h"><h2 id="h-sf">' . esc_html__( 'Safety', 'contenthealth' ) . '</h2></div><div class="rbn-card__b"><div class="ch-stack">';
		echo '<input type="hidden" name="s[keep_modified]" value="0"><label class="rbn-switch"><input type="checkbox" role="switch" id="t-km" name="s[keep_modified]" value="1"' . checked( ! empty( $s['keep_modified'] ), true, false ) . self::instant( 'keep_modified', __( 'Keep the last modified date when fixing', 'contenthealth' ) ) . ' aria-describedby="t-km-h"><span class="rbn-switch__x"></span><span class="ch-switch__t"><b>' . esc_html__( 'Keep the last modified date when fixing', 'contenthealth' ) . '</b><span class="rbn-help" id="t-km-h">' . esc_html__( 'When on, fixed posts do not show as “updated”.', 'contenthealth' ) . '</span></span></label>';
		echo '<input type="hidden" name="s[audit_on_save]" value="0"><label class="rbn-switch"><input type="checkbox" role="switch" id="t-as" name="s[audit_on_save]" value="1"' . checked( ! empty( $s['audit_on_save'] ), true, false ) . self::instant( 'audit_on_save', __( 'Re-check on save', 'contenthealth' ) ) . ' aria-describedby="t-as-h"><span class="rbn-switch__x"></span><span class="ch-switch__t"><b>' . esc_html__( 'Re-check on save', 'contenthealth' ) . '</b><span class="rbn-help" id="t-as-h">' . esc_html__( 'When you save a post, only that post\'s findings are checked again; a finding you fixed by hand becomes “fixed” on its own. Rules that look at the whole site, such as duplicate title and orphan content, wait for the scan.', 'contenthealth' ) . '</span></span></label>';
		echo '<input type="hidden" name="s[delete_on_uninstall]" value="0"><label class="rbn-switch"><input type="checkbox" role="switch" id="t-du" name="s[delete_on_uninstall]" value="1"' . checked( ! empty( $s['delete_on_uninstall'] ), true, false ) . self::instant( 'delete_on_uninstall', __( 'Delete data when the plugin is deleted', 'contenthealth' ) ) . ' aria-describedby="t-du-h"><span class="rbn-switch__x"></span><span class="ch-switch__t"><b>' . esc_html__( 'Delete data when the plugin is deleted', 'contenthealth' ) . '</b><span class="rbn-help" id="t-du-h">' . esc_html__( 'When off, findings, settings and the undo log remain even if the plugin is deleted.', 'contenthealth' ) . '</span></span></label>';
		echo '</div></div></section>';
	}

	/** @param array<string,mixed> $s */
	private static function patterns_card( array $s ): void {
		$cfg  = $s['rules'][ self::EDITOR_RULE ] ?? array();
		$name = array(
			'patterns_head'     => array( __( 'Start of the post', 'contenthealth' ), __( 'Checked in the first short block of the post.', 'contenthealth' ) ),
			'patterns_tail'     => array( __( 'End of the post', 'contenthealth' ), __( 'Checked in the last short block of the post.', 'contenthealth' ) ),
			'patterns_anywhere' => array( __( 'Match anywhere in the text', 'contenthealth' ), __( 'Checked in every block of the post.', 'contenthealth' ) ),
		);
		echo '<section class="rbn-card" aria-labelledby="h-d"><div class="rbn-card__h"><h2 id="h-d">' . esc_html__( 'Pattern editor', 'contenthealth' ) . '</h2><p>' . esc_html__( 'The chat-leftover rule looks for these patterns. Add your own pattern and test it without saving.', 'contenthealth' ) . '</p></div><div class="rbn-card__b rbn-card__b--flush">';
		foreach ( self::EDITOR_FIELDS as $f ) {
			$list = array_values( array_map( 'strval', (array) ( $cfg[ $f ] ?? array() ) ) );
			echo '<div class="ch-rule ch-rule--pats"><div class="ch-rule__m"><h3 class="ch-h3" id="pl-' . esc_attr( $f ) . '">' . esc_html( $name[ $f ][0] ) . '</h3><p class="rbn-help">' . esc_html( $name[ $f ][1] ) . '</p></div><div class="ch-rule__o" data-pats="' . esc_attr( $f ) . '"><textarea hidden name="s[rules][' . esc_attr( self::EDITOR_RULE ) . '][' . esc_attr( $f ) . ']" data-pats-store>' . esc_textarea( implode( "\n", $list ) ) . '</textarea><ul class="ch-pats" aria-labelledby="pl-' . esc_attr( $f ) . '">';
			foreach ( $list as $p ) {
				echo '<li class="ch-pat"><code>' . esc_html( $p ) . '</code><button type="button" data-pat-del aria-label="' . esc_attr( sprintf( /* translators: %s: pattern */ __( 'Delete pattern: %s', 'contenthealth' ), mb_substr( $p, 0, 40 ) ) ) . '">' . Ui::icon( 'trash' ) . '</button></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul><div class="ch-addrow"><label class="rbn-sr" for="pat-' . esc_attr( $f ) . '">' . esc_html__( 'New pattern', 'contenthealth' ) . '</label><input class="rbn-input rbn-input--mono" id="pat-' . esc_attr( $f ) . '" data-pat-new placeholder="' . esc_attr__( 'E.g. Sure,? here is .{0,40}:', 'contenthealth' ) . '"><button type="button" class="rbn-btn" data-pat-add>' . Ui::icon( 'plus' ) . esc_html__( 'Add', 'contenthealth' ) . '</button></div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div><div class="rbn-card__foot"><p class="rbn-help" id="pat-h">' . esc_html__( 'Regular expression (PCRE). Invalid or extremely slow patterns are rejected on save.', 'contenthealth' ) . '</p></div></section>';
	}

	private static function test_card(): void {
		echo '<section class="rbn-card ch-test" aria-labelledby="pt-h"><div class="rbn-card__h"><h2 id="pt-h">' . esc_html__( 'Test the pattern', 'contenthealth' ) . '</h2></div><div class="rbn-card__b"><div class="rbn-field"><label for="pt-pat">' . esc_html__( 'Pattern', 'contenthealth' ) . '</label><input class="rbn-input rbn-input--mono" id="pt-pat"></div><div class="rbn-field"><label for="pt-in">' . esc_html__( 'Sample text', 'contenthealth' ) . '</label><textarea class="rbn-textarea" id="pt-in" rows="3">' . esc_textarea( __( 'Here is the article that matches your requirements:', 'contenthealth' ) . "\n" . __( 'Email marketing is a way of staying in touch with subscribers.', 'contenthealth' ) ) . '</textarea></div><div class="ch-row"><button type="button" class="rbn-btn" data-pat-test>' . Ui::icon( 'play' ) . esc_html__( 'Test', 'contenthealth' ) . '</button></div><div id="pt-out" class="ch-test__out" role="status" aria-live="polite"><p class="rbn-muted">' . esc_html__( 'The result appears here.', 'contenthealth' ) . '</p></div></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function seo_card(): void {
		$seo = Seo::active_plugin();
		$name = Seo::label( $seo );
		echo '<section class="rbn-card" aria-labelledby="h-s"><div class="rbn-card__h"><h2 id="h-s">' . esc_html__( 'SEO plugin', 'contenthealth' ) . '</h2></div><div class="rbn-card__b">';
		if ( '' !== $name ) {
			echo Ui::note( 'ok', '<p><strong>' . esc_html( sprintf( /* translators: %s: plugin name */ __( '%s detected.', 'contenthealth' ), $name ) ) . '</strong> ' . esc_html__( 'The meta description rule reads this plugin\'s field.', 'contenthealth' ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo Ui::note( 'info', '<p><strong>' . esc_html__( 'No SEO plugin found.', 'contenthealth' ) . '</strong> ' . esc_html__( 'The meta description rule looks only at the post excerpt.', 'contenthealth' ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></section>';
	}

	public static function dialogs(): void {}
}
