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
	private const EDITOR_FIELDS = array( 'patterns_head', 'patterns_tail' );

	private const TOP_BOOL = array( 'keep_modified', 'delete_on_uninstall' );
	private const TOP_KEYS = array( 'frequency', 'time_budget', 'batch_size', 'statuses', 'exclude_post_types', 'term_taxonomies', 'keep_modified', 'delete_on_uninstall', 'live_host', 'alt_template', 'rules' );

	/**
	 * Gönderilen ayar dizisini yalnız bilinen anahtarlara indirger (Settings::save asıl temizliği yapar).
	 *
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 */
	public static function normalize( array $in ): array {
		$out = array_intersect_key( $in, array_flip( self::TOP_KEYS ) );
		foreach ( self::TOP_BOOL as $b ) {
			$out[ $b ] = ! empty( $in[ $b ] );
		}
		if ( isset( $out['rules'] ) && ! is_array( $out['rules'] ) ) {
			unset( $out['rules'] );
		}
		return $out;
	}

	private static function label( string $key ): string {
		$map = array(
			'max_len'          => __( 'En çok uzunluk (karakter)', 'contenthealth' ),
			'max_chars'        => __( 'En çok karakter', 'contenthealth' ),
			'max_words'        => __( 'En çok kelime', 'contenthealth' ),
			'min_words'        => __( 'En az kelime', 'contenthealth' ),
			'min_len'          => __( 'En az uzunluk', 'contenthealth' ),
			'min'              => __( 'En az', 'contenthealth' ),
			'max'              => __( 'En çok', 'contenthealth' ),
			'fuzzy_min'        => __( 'Bulanık eşleşme için en az uzunluk', 'contenthealth' ),
			'max_requests'     => __( 'Tarama başına en çok istek', 'contenthealth' ),
			'per_second'       => __( 'Saniyedeki istek', 'contenthealth' ),
			'post_types'       => __( 'Yazı türleri (her satıra bir)', 'contenthealth' ),
			'dev_hosts'        => __( 'Ek geliştirme adresleri (her satıra bir)', 'contenthealth' ),
			'fallback_excerpt' => __( 'Özeti yedek say', 'contenthealth' ),
			'bracket_names'    => __( 'Köşeli parantezli adları da say', 'contenthealth' ),
			'check_bold'       => __( 'Kalın işaretlerini ara', 'contenthealth' ),
			'check_heading'    => __( 'Başlık işaretlerini ara', 'contenthealth' ),
			'check_bullets'    => __( 'Madde işaretlerini ara', 'contenthealth' ),
		);
		return $map[ $key ] ?? $key;
	}

	public static function render(): void {
		$s = Settings::all();
		echo '<form id="ayar" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate><input type="hidden" name="action" value="contenthealth_save">';
		wp_nonce_field( 'contenthealth_save' );
		self::rules_card( $s );
		self::scan_card( $s );
		self::patterns_card( $s );
		self::seo_card();
		echo '<div class="rbn-bar" id="savebar" role="region" aria-label="' . esc_attr__( 'Kaydetme çubuğu', 'contenthealth' ) . '"><p><span class="rbn-dot"></span><span id="savebar-t" aria-live="polite">' . esc_html__( 'Kaydedilmemiş değişiklik yok.', 'contenthealth' ) . '</span></p><div class="rbn-bar__act"><button type="button" class="rbn-btn" data-revert disabled>' . esc_html__( 'Vazgeç', 'contenthealth' ) . '</button><button type="submit" class="rbn-btn rbn-btn--pri" data-save disabled>' . Ui::icon( 'save' ) . esc_html__( 'Değişiklikleri kaydet', 'contenthealth' ) . '</button></div></div></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** @param array<string,mixed> $s */
	private static function rules_card( array $s ): void {
		echo '<section class="rbn-card" aria-labelledby="h-k"><div class="rbn-card__h"><h2 id="h-k">' . esc_html__( 'Kurallar', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Kapattığınız kural taramada çalışmaz; var olan bulguları silmez.', 'contenthealth' ) . '</p></div><div class="rbn-card__b rbn-card__b--flush">';
		foreach ( Registry::groups() as $gid => $glabel ) {
			echo '<h3 class="rbn-grp">' . esc_html( (string) $glabel ) . '</h3>';
			foreach ( Registry::rules() as $rule ) {
				if ( $rule->group() === $gid ) {
					self::rule_row( $rule, $s['rules'][ $rule->id() ] ?? $rule->defaults() );
				}
			}
		}
		echo '</div></section>';
	}

	/** @param array<string,mixed> $cfg */
	private static function rule_row( Rule $rule, array $cfg ): void {
		$id   = $rule->id();
		$name = 's[rules][' . $id . ']';
		$dom  = 'r-' . $id;
		echo '<div class="rbn-rule"><div><label class="rbn-switch"><input type="checkbox" role="switch" id="' . esc_attr( $dom ) . '" name="' . esc_attr( $name ) . '[enabled]" value="1"' . checked( ! empty( $cfg['enabled'] ), true, false ) . ' aria-describedby="' . esc_attr( $dom ) . '-h"><span class="rbn-switch__t" aria-hidden="true"></span><span class="rbn-switch__x"><b>' . esc_html( $rule->label() ) . '</b><span class="rbn-help" id="' . esc_attr( $dom ) . '-h">' . esc_html( $rule->description() ) . '</span></span></label></div>';
		$fields = '';
		foreach ( $rule->defaults() as $k => $dv ) {
			if ( 'enabled' === $k || ( self::EDITOR_RULE === $id && in_array( $k, self::EDITOR_FIELDS, true ) ) ) {
				continue;
			}
			$fid = $dom . '-' . $k;
			$val = $cfg[ $k ] ?? $dv;
			if ( is_bool( $dv ) ) {
				$fields .= '<div class="rbn-rule__t"><input type="hidden" name="' . esc_attr( $name . '[' . $k . ']' ) . '" value="0"><label class="rbn-check"><input type="checkbox" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name . '[' . $k . ']' ) . '" value="1"' . checked( (bool) $val, true, false ) . '><span>' . esc_html( self::label( $k ) ) . '</span></label></div>';
			} elseif ( is_int( $dv ) ) {
				$fields .= '<div class="rbn-rule__t"><label for="' . esc_attr( $fid ) . '">' . esc_html( self::label( $k ) ) . '</label><input class="rbn-input rbn-input--s" type="number" min="0" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name . '[' . $k . ']' ) . '" value="' . (int) $val . '"></div>';
			} elseif ( is_array( $dv ) ) {
				$fields .= '<div class="rbn-rule__t" style="flex-basis:100%"><label for="' . esc_attr( $fid ) . '">' . esc_html( self::label( $k ) ) . '</label><textarea class="rbn-textarea rbn-input--mono" rows="3" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name . '[' . $k . ']' ) . '">' . esc_textarea( implode( "\n", array_map( 'strval', (array) $val ) ) ) . '</textarea></div>';
			} else {
				$fields .= '<div class="rbn-rule__t"><label for="' . esc_attr( $fid ) . '">' . esc_html( self::label( $k ) ) . '</label><input class="rbn-input" type="text" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name . '[' . $k . ']' ) . '" value="' . esc_attr( (string) $val ) . '"></div>';
			}
		}
		echo $fields . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parçalar kaçışlı.
	}

	/** @param array<string,mixed> $s */
	private static function scan_card( array $s ): void {
		$freq = array(
			'off'    => __( 'Yalnız elle', 'contenthealth' ),
			'hourly' => __( 'Saatlik', 'contenthealth' ),
			'daily'  => __( 'Günlük', 'contenthealth' ),
			'weekly' => __( 'Haftalık', 'contenthealth' ),
		);
		$lines = static fn( string $k ): string => esc_textarea( implode( "\n", array_map( 'strval', (array) $s[ $k ] ) ) );
		echo '<section class="rbn-card" aria-labelledby="h-t"><div class="rbn-card__h"><h2 id="h-t">' . esc_html__( 'Tarama', 'contenthealth' ) . '</h2></div><div class="rbn-card__b">';
		echo '<div class="rbn-field"><div class="rbn-field__l"><label for="t-sik">' . esc_html__( 'Tarama sıklığı', 'contenthealth' ) . '</label><p class="rbn-help" id="t-sik-h">' . esc_html__( 'Otomatik tarama arka planda WP-Cron ile çalışır.', 'contenthealth' ) . '</p></div><div class="rbn-field__c"><select class="rbn-select" id="t-sik" name="s[frequency]" aria-describedby="t-sik-h">';
		foreach ( $freq as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( (string) $s['frequency'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></div></div>';
		echo '<div class="rbn-field"><div class="rbn-field__l"><label for="t-tb">' . esc_html__( 'Adım süresi (sn)', 'contenthealth' ) . '</label><p class="rbn-help" id="t-tb-h">' . esc_html__( 'Her tarama adımının en çok süresi (1–20) ve adımdaki kayıt sayısı (10–500).', 'contenthealth' ) . '</p></div><div class="rbn-field__c rbn-row"><input class="rbn-input rbn-input--s" type="number" id="t-tb" name="s[time_budget]" min="1" max="20" value="' . (int) $s['time_budget'] . '" aria-describedby="t-tb-h"><label class="rbn-sr" for="t-bs">' . esc_html__( 'Adım başına kayıt', 'contenthealth' ) . '</label><input class="rbn-input rbn-input--s" type="number" id="t-bs" name="s[batch_size]" min="10" max="500" value="' . (int) $s['batch_size'] . '"></div></div>';
		echo '<div class="rbn-field"><div class="rbn-field__l"><label for="t-st">' . esc_html__( 'Taranan yazı durumları', 'contenthealth' ) . '</label><p class="rbn-help" id="t-st-h">' . esc_html__( 'Her satıra bir durum (publish, draft…).', 'contenthealth' ) . '</p></div><div class="rbn-field__c"><textarea class="rbn-textarea rbn-input--mono" id="t-st" name="s[statuses]" rows="2" aria-describedby="t-st-h">' . $lines( 'statuses' ) . '</textarea></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="rbn-field"><div class="rbn-field__l"><label for="t-hr">' . esc_html__( 'Hariç tutulan yazı türleri', 'contenthealth' ) . '</label><p class="rbn-help" id="t-hr-h">' . esc_html__( 'Her satıra bir yazı türü adı.', 'contenthealth' ) . '</p></div><div class="rbn-field__c"><textarea class="rbn-textarea rbn-input--mono" id="t-hr" name="s[exclude_post_types]" rows="2" aria-describedby="t-hr-h">' . $lines( 'exclude_post_types' ) . '</textarea></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="rbn-field"><div class="rbn-field__l"><label for="t-tx">' . esc_html__( 'Taranan taksonomiler', 'contenthealth' ) . '</label><p class="rbn-help" id="t-tx-h">' . esc_html__( 'Etiket Doktoru bu taksonomilere bakar. Her satıra bir ad.', 'contenthealth' ) . '</p></div><div class="rbn-field__c"><textarea class="rbn-textarea rbn-input--mono" id="t-tx" name="s[term_taxonomies]" rows="2" aria-describedby="t-tx-h">' . $lines( 'term_taxonomies' ) . '</textarea></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="rbn-field"><div class="rbn-field__l"><label for="t-lh">' . esc_html__( 'Canlı alan adı', 'contenthealth' ) . '</label><p class="rbn-help" id="t-lh-h">' . esc_html__( 'Hatalı adres düzeltmesinin hedefi. Boşsa site adresi kullanılır.', 'contenthealth' ) . '</p></div><div class="rbn-field__c"><input class="rbn-input rbn-input--mono" id="t-lh" name="s[live_host]" value="' . esc_attr( (string) $s['live_host'] ) . '" aria-describedby="t-lh-h"></div></div>';
		echo '<div class="rbn-field"><div class="rbn-field__l"><label for="t-alt">' . esc_html__( 'Alt metin şablonu', 'contenthealth' ) . '</label><p class="rbn-help" id="t-alt-h">' . esc_html__( 'Kullanılabilir jetonlar: {başlık}, {dosya_adı}, {site_adı}.', 'contenthealth' ) . '</p></div><div class="rbn-field__c"><input class="rbn-input rbn-input--mono" id="t-alt" name="s[alt_template]" maxlength="200" value="' . esc_attr( (string) $s['alt_template'] ) . '" aria-describedby="t-alt-h"></div></div>';
		echo '<div class="rbn-field"><div class="rbn-field__l"><span class="rbn-label">' . esc_html__( 'Güvenlik', 'contenthealth' ) . '</span></div><div class="rbn-field__c"><div class="rbn-stack">';
		echo '<label class="rbn-switch"><input type="checkbox" role="switch" id="t-km" name="s[keep_modified]" value="1"' . checked( ! empty( $s['keep_modified'] ), true, false ) . ' aria-describedby="t-km-h"><span class="rbn-switch__t" aria-hidden="true"></span><span class="rbn-switch__x"><b>' . esc_html__( 'Düzeltmede son değiştirilme tarihini koru', 'contenthealth' ) . '</b><span class="rbn-help" id="t-km-h">' . esc_html__( 'Açıkken düzeltilen yazılar “güncellendi” görünmez.', 'contenthealth' ) . '</span></span></label>';
		echo '<label class="rbn-switch"><input type="checkbox" role="switch" id="t-du" name="s[delete_on_uninstall]" value="1"' . checked( ! empty( $s['delete_on_uninstall'] ), true, false ) . ' aria-describedby="t-du-h"><span class="rbn-switch__t" aria-hidden="true"></span><span class="rbn-switch__x"><b>' . esc_html__( 'Eklenti silinince verileri de sil', 'contenthealth' ) . '</b><span class="rbn-help" id="t-du-h">' . esc_html__( 'Kapalıyken eklenti silinse de bulgular, ayarlar ve geri alma günlüğü kalır.', 'contenthealth' ) . '</span></span></label>';
		echo '</div></div></div></div></section>';
	}

	/** @param array<string,mixed> $s */
	private static function patterns_card( array $s ): void {
		$cfg  = $s['rules'][ self::EDITOR_RULE ] ?? array();
		$name = array(
			'patterns_head' => __( 'Başlangıç desenleri', 'contenthealth' ),
			'patterns_tail' => __( 'Bitiş desenleri', 'contenthealth' ),
		);
		echo '<section class="rbn-card" aria-labelledby="h-d"><div class="rbn-card__h"><h2 id="h-d">' . esc_html__( 'Desen düzenleyici', 'contenthealth' ) . '</h2><p>' . esc_html__( 'Sohbet kalıntısı kuralı bu desenleri arar. Kendi deseninizi ekleyip kaydetmeden test edin.', 'contenthealth' ) . '</p></div><div class="rbn-card__b rbn-card__b--pad">';
		foreach ( self::EDITOR_FIELDS as $f ) {
			$list = array_values( array_map( 'strval', (array) ( $cfg[ $f ] ?? array() ) ) );
			echo '<h3 class="rbn-h3">' . esc_html( $name[ $f ] ) . '</h3><div data-pats="' . esc_attr( $f ) . '"><textarea hidden name="s[rules][' . esc_attr( self::EDITOR_RULE ) . '][' . esc_attr( $f ) . ']" data-pats-store>' . esc_textarea( implode( "\n", $list ) ) . '</textarea><ul class="rbn-pats" aria-label="' . esc_attr( $name[ $f ] ) . '">';
			foreach ( $list as $p ) {
				echo '<li class="rbn-pat"><code>' . esc_html( $p ) . '</code><button type="button" data-pat-del aria-label="' . esc_attr( sprintf( /* translators: %s: pattern */ __( 'Deseni sil: %s', 'contenthealth' ), mb_substr( $p, 0, 40 ) ) ) . '">' . Ui::icon( 'trash' ) . '</button></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul><div class="rbn-addrow"><label class="rbn-sr" for="pat-' . esc_attr( $f ) . '">' . esc_html__( 'Yeni desen', 'contenthealth' ) . '</label><input class="rbn-input rbn-input--mono" id="pat-' . esc_attr( $f ) . '" data-pat-new placeholder="' . esc_attr__( 'Örn. Elbette,? işte .{0,40}:', 'contenthealth' ) . '"><button type="button" class="rbn-btn" data-pat-add>' . Ui::icon( 'plus' ) . esc_html__( 'Ekle', 'contenthealth' ) . '</button></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<p class="rbn-help" id="pat-h">' . esc_html__( 'Düzenli ifade (PCRE). Geçersiz ya da aşırı yavaş desenler kayıtta reddedilir.', 'contenthealth' ) . '</p>';
		echo '<div class="rbn-test" aria-labelledby="pt-h"><h3 class="rbn-h3" id="pt-h">' . esc_html__( 'Deseni dene', 'contenthealth' ) . '</h3><label for="pt-pat" class="rbn-help" style="display:block;margin:0 0 6px">' . esc_html__( 'Desen', 'contenthealth' ) . '</label><input class="rbn-input rbn-input--mono" id="pt-pat" style="margin-bottom:8px"><label for="pt-in" class="rbn-help" style="display:block;margin:0 0 6px">' . esc_html__( 'Örnek metin', 'contenthealth' ) . '</label><textarea class="rbn-textarea" id="pt-in" rows="3">' . esc_textarea( __( 'İşte istediğiniz özelliklere uygun makale:', 'contenthealth' ) . "\n" . __( 'E-posta pazarlaması abonelerle iletişim kurmanın bir yoludur.', 'contenthealth' ) ) . '</textarea><div class="rbn-row" style="margin:8px 0"><button type="button" class="rbn-btn rbn-btn--sm" data-pat-test>' . Ui::icon( 'play' ) . esc_html__( 'Test et', 'contenthealth' ) . '</button></div><div id="pt-out" role="status" aria-live="polite"><p class="rbn-muted">' . esc_html__( 'Sonuç burada görünür.', 'contenthealth' ) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></section>';
	}

	private static function seo_card(): void {
		$seo = Seo::active_plugin();
		$map = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'aioseo'   => 'All in One SEO',
		);
		echo '<section class="rbn-card" aria-labelledby="h-s"><div class="rbn-card__h"><h2 id="h-s">' . esc_html__( 'SEO eklentisi', 'contenthealth' ) . '</h2></div><div class="rbn-card__b rbn-card__b--pad">';
		if ( isset( $map[ $seo ] ) ) {
			echo Ui::note( 'ok', '<p><strong>' . esc_html( sprintf( /* translators: %s: plugin name */ __( '%s algılandı.', 'contenthealth' ), $map[ $seo ] ) ) . '</strong> ' . esc_html__( 'Meta açıklama kuralı bu eklentinin alanını okur.', 'contenthealth' ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo Ui::note( 'info', '<p><strong>' . esc_html__( 'SEO eklentisi bulunamadı.', 'contenthealth' ) . '</strong> ' . esc_html__( 'Meta açıklama kuralı yalnız yazı özetine bakar.', 'contenthealth' ) . '</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></section>';
	}

	public static function dialogs(): void {}
}
