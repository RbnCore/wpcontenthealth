<?php
/**
 * Kayıtta yeniden denetim: yazı kaydedilince YALNIZ o yazının tek-yazılık kuralları çalışır.
 *
 * Elle düzeltilen bulgu kendiliğinden "düzeltildi" olur; hâlâ eşleşen açık kalır; yeni çıkan açılır.
 * Küme kuralları (yinelenen başlık, yetim içerik…) tüm tabloya baktığı için burada ÇALIŞMAZ; taramayı bekler.
 * Yazı içeriği ve tarihleri değiştirilmez, yalnız bulgu tablosu yazılır. Hata kaydı asla bozmaz.
 *
 * @package ContentHealth
 */

declare(strict_types=1);

namespace ContentHealth;

defined( 'ABSPATH' ) || exit;

final class SaveAudit {

	public const OPTION = 'contenthealth_last_audit';

	/** Kuralları değerlendirmek için yumuşak süre sınırı (sn); aşılırsa kalan kurallara dokunulmaz. */
	private const BUDGET = 0.25;

	private static bool $busy = false;

	/** `wp_after_insert_post` kancası. Hiçbir koşulda istisna dışarı sızmaz. */
	public static function on_save( int $post_id, \WP_Post $post, bool $update = true ): void {
		if ( self::$busy ) {
			return;
		}
		self::$busy = true;
		try {
			self::audit( $post );
		} catch ( \Throwable $e ) {
			Log::error( 'save_audit', $e );
		} finally {
			self::$busy = false;
		}
	}

	/** Ucuz önkoşullar: revizyon, otomatik kayıt, taslak iskeleti, içe aktarma ve yetkisiz bağlam denetlenmez. */
	private static function eligible( \WP_Post $post ): bool {
		if ( in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) || 'revision' === $post->post_type ) {
			return false;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
			return false;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return false;
		}
		return current_user_can( 'edit_post', $post->ID );
	}

	private static function audit( \WP_Post $post ): void {
		global $wpdb;
		if ( ! self::eligible( $post ) ) {
			return;
		}
		Plugin::boot();
		if ( empty( Settings::get( 'audit_on_save' ) ) ) {
			return;
		}
		$ctx = new Ctx();
		if ( ! in_array( $post->post_type, $ctx->post_types(), true ) || ! in_array( $post->post_status, $ctx->statuses(), true ) ) {
			return;
		}
		$rules = array();
		foreach ( Registry::enabled() as $id => $rule ) {
			$scopes = $rule->scopes();
			if ( in_array( 'post', $scopes, true ) && ! in_array( 'global', $scopes, true ) ) {
				$rules[ $id ] = $rule;
			}
		}
		if ( ! $rules ) {
			return;
		}
		$t       = Db::table( 'issues' );
		$current = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, rule_id, status, details FROM {$t} WHERE object_type = 'post' AND object_id = %d", $post->ID ) ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$current[ (string) $row->rule_id ] = $row;
		}
		$run_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM " . Db::table( 'runs' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$view   = new PostView( $post );
		$t0     = microtime( true );
		$n      = 0;
		foreach ( $rules as $id => $rule ) {
			if ( $n > 0 && microtime( true ) - $t0 > self::BUDGET ) {
				break; // Ağır yazı: kalan kurallar bir sonraki taramaya kalır, kayıt gecikmez.
			}
			++$n;
			try {
				$d = $rule->check( $view, $ctx );
			} catch ( \Throwable $e ) {
				Log::error( $id, $e );
				continue; // Kuralın hatası bulguyu kapatmaz.
			}
			if ( null !== $d ) {
				Issues::upsert( $run_id, 'post', (int) $post->ID, $rule, $d );
			} elseif ( isset( $current[ $id ] ) && 'open' === $current[ $id ]->status ) {
				self::close_manual( $current[ $id ] );
			}
		}
		if ( $n > 0 ) {
			update_option( self::OPTION, current_time( 'mysql', true ), false );
			Score::forget(); // Bulgular değişmiş olabilir: canlı puan bir sonraki okumada yeniden hesaplanır.
		}
	}

	/** Açık bulguyu "düzeltildi" yapar; nedeni ayrıntıya yazar (şema değişmez). */
	private static function close_manual( object $row ): void {
		global $wpdb;
		$d                 = json_decode( (string) $row->details, true );
		$d                 = is_array( $d ) ? $d : array();
		$d['fixed_by']     = 'user';
		$d['fixed_reason'] = 'manual';
		$d['fixed_at']     = current_time( 'mysql', true );
		$d['fixed_user']   = get_current_user_id();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'issues' ),
			array(
				'status'  => 'fixed',
				'details' => wp_json_encode( $d, JSON_UNESCAPED_UNICODE ),
			),
			array(
				'id'     => (int) $row->id,
				'status' => 'open',
			)
		);
	}

	/** Son kayıt-denetimi (GMT, `Y-m-d H:i:s`) ya da boş. */
	public static function last(): string {
		return (string) get_option( self::OPTION, '' );
	}
}
